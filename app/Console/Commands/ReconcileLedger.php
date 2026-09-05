<?php

namespace App\Console\Commands;

use App\Models\PlatformMetric;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies ledger integrity: sum of all transactions must equal wallet balances.
 *
 * Run daily at 03:00 to detect any drift between the transaction ledger and wallet state.
 * If a mismatch is found, sends an alert email to the admin.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile {--notify : Send email alert on mismatch}';

    protected $description = 'Verify wallet balances match transaction ledger sums';

    /**
     * Single source of truth for how each transaction type moves the three
     * reconstructable wallet buckets. Every TYPE_* constant MUST appear here.
     *
     *   cash     → available + reserved (reservations are internal holds with
     *              no ledger row, so available+reserved is the cash invariant)
     *   invested → capital deployed into loans
     *   earned   → cumulative interest income (a reporting bucket; also added
     *              to cash, since interest is spendable)
     *
     * Signs are the contribution of a POSITIVE transaction amount to each
     * bucket. Mirrors WalletService exactly:
     *   deposit            available +amt
     *   withdrawal/fee     available -amt        (debit)
     *   investment         available -amt, invested +amt
     *   *_principal        available +amt, invested -amt   (invested → available)
     *   *_interest         available +amt, earned  +amt
     *
     * Adding a new TYPE_* constant without a row here makes reconciliation
     * fail LOUDLY (default-deny in {@see handle}) rather than silently
     * miscompute — that blind spot is exactly what let buyback/early-repay
     * payouts go unreconciled before this map existed.
     *
     * `accrued` is the locked-profit bucket: interest recognised on schedule
     * but not yet released to `available` (текущо салдо = invested + accrued).
     *
     * @var array<string, array{cash:int, invested:int, earned:int, accrued:int}>
     */
    private const LEDGER_MAP = [
        Transaction::TYPE_DEPOSIT => ['cash' => 1,  'invested' => 0,  'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_WITHDRAWAL => ['cash' => -1, 'invested' => 0,  'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_FEE => ['cash' => -1, 'invested' => 0,  'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_INVESTMENT => ['cash' => -1, 'invested' => 1,  'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_REPAYMENT_PRINCIPAL => ['cash' => 1,  'invested' => -1, 'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_REPAYMENT_INTEREST => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => 0],
        Transaction::TYPE_BUYBACK_PRINCIPAL => ['cash' => 1,  'invested' => -1, 'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_BUYBACK_INTEREST => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => 0],
        Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL => ['cash' => 1, 'invested' => -1, 'earned' => 0, 'accrued' => 0],
        Transaction::TYPE_EARLY_REPAYMENT_INTEREST => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => 0],
        // Locked profit accrues (текущо салдо grows), no cash move yet.
        Transaction::TYPE_INTEREST_ACCRUED => ['cash' => 0,  'invested' => 0,  'earned' => 0, 'accrued' => 1],
        // Locked profit released into spendable available (+ earned counter).
        Transaction::TYPE_INTEREST_RELEASED => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => -1],
        // Locked profit written OFF (principal-only buyback: the originator
        // does not cover interest, so the accrued promise is reversed, not
        // paid out). No cash move, no earned income.
        Transaction::TYPE_INTEREST_ACCRUAL_REVERSED => ['cash' => 0, 'invested' => 0, 'earned' => 0, 'accrued' => -1],
        // Admin-granted promotional credit — spendable cash like a deposit,
        // but with NO bank wire behind it. Wallet-vs-ledger reconciliation
        // treats it as cash-in; the BANK-statement side must exclude it
        // (SUM(type='bonus') = platform marketing spend, not client money).
        Transaction::TYPE_BONUS => ['cash' => 1, 'invested' => 0, 'earned' => 0, 'accrued' => 0],
        // Conditional bonus (Reni 2026-08-18): spendable cash from the start
        // — the investor may invest it immediately — but not WITHDRAWABLE
        // until its condition is met. The restriction is a floor inside
        // WalletService::reserve(), not a separate bucket, so as far as the
        // ledger is concerned this is cash-in like any other bonus. Same
        // marketing-spend note as TYPE_BONUS: no bank wire behind it.
        Transaction::TYPE_BONUS_LOCKED => ['cash' => 1, 'invested' => 0, 'earned' => 0, 'accrued' => 0],
        // LEGACY (never written since 2026-08-18): under the short-lived
        // separate-bucket design this moved the bonus into `available`. Rows
        // from that window are already counted by their grant above, so this
        // must contribute nothing — mapped, not removed, because default-deny
        // fails on any persisted type without a rule.
        Transaction::TYPE_BONUS_RELEASED => ['cash' => 0, 'invested' => 0, 'earned' => 0, 'accrued' => 0],
        // Written off (admin cancel / account closure) — the grant is debited
        // back out of the balance.
        Transaction::TYPE_BONUS_CANCELLED => ['cash' => -1, 'invested' => 0, 'earned' => 0, 'accrued' => 0],
    ];

    public function handle(): int
    {
        // Fail loudly if a transaction type exists with no reconciliation
        // rule — otherwise its money would be silently ignored.
        $unmapped = array_diff(Transaction::TYPES, array_keys(self::LEDGER_MAP));
        if (! empty($unmapped)) {
            $msg = 'ReconcileLedger: unmapped transaction type(s): '.implode(', ', $unmapped);
            $this->error($msg);
            Log::error($msg);

            return Command::FAILURE;
        }

        $mismatches = 0;
        $mismatchDetails = [];
        $walletsChecked = Wallet::count();

        // One transaction for the whole pass (audit 2026-09-01, PAY-02). Under
        // MySQL's default REPEATABLE READ every SELECT below sees the same
        // snapshot, so a WalletService commit landing between "read the wallet
        // row" and "sum its ledger" can no longer produce a spurious «stop
        // withdrawals» alert — or mask a real drift by cancelling it out.
        DB::transaction(function () use (&$mismatches, &$mismatchDetails) {
            Wallet::chunk(100, function ($wallets) use (&$mismatches, &$mismatchDetails) {
                foreach ($wallets as $wallet) {
                    $userId = $wallet->user_id;
                    $errors = [];
                    $expected = $this->expectedBuckets($userId, $errors);

                    $actualAvailablePlusReserved = bcadd($wallet->available, $wallet->reserved, 2);

                    if (bccomp($actualAvailablePlusReserved, $expected['cash'], 2) !== 0) {
                        $errors[] = "available+reserved: expected={$expected['cash']}, actual={$actualAvailablePlusReserved}";
                    }

                    if (bccomp($wallet->invested, $expected['invested'], 2) !== 0) {
                        $errors[] = "invested: expected={$expected['invested']}, actual={$wallet->invested}";
                    }

                    if (bccomp($wallet->earned, $expected['earned'], 2) !== 0) {
                        $errors[] = "earned: expected={$expected['earned']}, actual={$wallet->earned}";
                    }

                    if (bccomp($wallet->accrued, $expected['accrued'], 2) !== 0) {
                        $errors[] = "accrued: expected={$expected['accrued']}, actual={$wallet->accrued}";
                    }

                    if (! empty($errors)) {
                        $mismatches++;
                        $errorMsg = "Ledger mismatch for user #{$userId}: ".implode('; ', $errors);
                        $this->error($errorMsg);
                        Log::error($errorMsg);

                        $mismatchDetails[] = [
                            'user_id' => $userId,
                            'wallet' => [
                                'available' => $wallet->available,
                                'reserved' => $wallet->reserved,
                                'invested' => $wallet->invested,
                                'earned' => $wallet->earned,
                            ],
                            'expected' => [
                                'available+reserved' => $expected['cash'],
                                'invested' => $expected['invested'],
                                'earned' => $expected['earned'],
                            ],
                            'errors' => $errors,
                        ];
                    }
                }
            });

            // Ledger rows whose wallet row is gone (GDPR account closure deletes
            // `wallets`, never `transactions`). WalletService cannot write for
            // such a user any more, so this is not a money leak — but the
            // platform-wide identity «Σ ledger = Σ wallets» is only provable if
            // every orphaned ledger nets to zero in every BALANCE bucket. `earned`
            // is a lifetime counter (every interest type maps +1, nothing maps −1),
            // so a closed investor who ever received interest legitimately leaves
            // earned > 0 behind — it is not compared (review 2026-09-05).
            $orphanUserIds = Transaction::query()
                ->select('user_id')
                ->distinct()
                ->whereNotIn('user_id', Wallet::query()->select('user_id'))
                ->pluck('user_id');

            foreach ($orphanUserIds as $userId) {
                $errors = [];
                $expected = $this->expectedBuckets((int) $userId, $errors);

                foreach (['cash', 'invested', 'accrued'] as $bucket) {
                    if (bccomp($expected[$bucket], '0', 2) !== 0) {
                        $errors[] = "no wallet row, but ledger expects {$bucket}={$expected[$bucket]}";
                    }
                }

                if (! empty($errors)) {
                    $mismatches++;
                    $errorMsg = "Ledger mismatch for user #{$userId} (wallet deleted): ".implode('; ', $errors);
                    $this->error($errorMsg);
                    Log::error($errorMsg);

                    $mismatchDetails[] = [
                        'user_id' => $userId,
                        'wallet' => ['available' => '—', 'reserved' => '—', 'invested' => '—', 'earned' => '—'],
                        'expected' => [
                            'available+reserved' => $expected['cash'],
                            'invested' => $expected['invested'],
                            'earned' => $expected['earned'],
                        ],
                        'errors' => $errors,
                    ];
                }
            }
        });

        if ($mismatches > 0) {
            $this->error("FAILED: {$mismatches} wallet(s) have ledger mismatches.");

            // Send alert email if --notify flag is set
            if ($this->option('notify')) {
                $this->sendAlertEmail($mismatches, $mismatchDetails);
            }

            $this->recordMetrics('mismatch', $walletsChecked, $mismatches);

            return Command::FAILURE;
        }

        $this->info('OK: All wallets reconciled successfully.');
        $this->recordMetrics('ok', $walletsChecked, 0);

        return Command::SUCCESS;
    }

    /**
     * Audit 2026-09-01 (A3): the health endpoint reads these — until now the
     * only trace of a reconciliation run was its e-mail. A metric write must
     * never change the exit code of the check itself.
     */
    private function recordMetrics(string $status, int $walletsChecked, int $mismatches): void
    {
        try {
            PlatformMetric::record('last_reconcile_run_at', now()->toIso8601String());
            PlatformMetric::record('last_reconcile_status', $status);
            PlatformMetric::record('last_reconcile_wallets_checked', (string) $walletsChecked);
            PlatformMetric::record('last_reconcile_mismatches', (string) $mismatches);
        } catch (\Throwable $e) {
            Log::warning('ledger:reconcile metrics not recorded', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Rebuild the four reconstructable buckets for one user from the ledger
     * via LEDGER_MAP. Unmapped persisted types are appended to $errors
     * (default-deny) rather than silently dropped from the sums.
     *
     * @param  array<int, string>  $errors
     * @return array{cash: string, invested: string, earned: string, accrued: string}
     */
    private function expectedBuckets(int $userId, array &$errors): array
    {
        $sums = Transaction::where('user_id', $userId)
            ->select('type', DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        $unknownTypes = array_diff($sums->keys()->all(), array_keys(self::LEDGER_MAP));
        foreach ($unknownTypes as $unknownType) {
            $errors[] = "unmapped transaction type '{$unknownType}' (sum={$sums[$unknownType]})";
        }

        $expected = ['cash' => '0.00', 'invested' => '0.00', 'earned' => '0.00', 'accrued' => '0.00'];

        foreach (self::LEDGER_MAP as $type => $signs) {
            $amount = (string) ($sums[$type] ?? '0.00');
            foreach ($expected as $bucket => $_) {
                $expected[$bucket] = bcadd($expected[$bucket], bcmul((string) $signs[$bucket], $amount, 2), 2);
            }
        }

        return $expected;
    }

    private function sendAlertEmail(int $count, array $details): void
    {
        // Recipient = config('app.admin_email') / ADMIN_ALERT_EMAIL — the single
        // ops-alert address, no longer hardcoded here (2026-09-03).
        $sent = OpsAlert::mail("Ledger mismatch detected — {$count} wallet(s)", $this->formatEmailBody($count, $details));

        if ($sent) {
            $this->info('Alert email sent to '.OpsAlert::email());
        } else {
            $this->error('Failed to send alert email — see the application log.');
        }
    }

    private function formatEmailBody(int $count, array $details): string
    {
        $body = "LEDGER RECONCILIATION ALERT\n";
        $body .= "==========================\n\n";
        $body .= 'Timestamp: '.now()->toIso8601String()."\n";
        $body .= "Mismatched wallets: {$count}\n\n";

        foreach ($details as $detail) {
            $body .= "--- User #{$detail['user_id']} ---\n";
            $body .= "Actual:   available={$detail['wallet']['available']}, reserved={$detail['wallet']['reserved']}, invested={$detail['wallet']['invested']}, earned={$detail['wallet']['earned']}\n";
            $body .= "Expected: available+reserved={$detail['expected']['available+reserved']}, invested={$detail['expected']['invested']}, earned={$detail['expected']['earned']}\n";
            foreach ($detail['errors'] as $error) {
                $body .= "  -> {$error}\n";
            }
            $body .= "\n";
        }

        $body .= "\nACTION REQUIRED: Investigate immediately. Do NOT process any withdrawals until resolved.\n";

        return $body;
    }
}
