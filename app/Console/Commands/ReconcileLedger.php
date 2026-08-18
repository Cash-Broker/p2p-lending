<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        Transaction::TYPE_DEPOSIT => ['cash' => 1,  'invested' => 0,  'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_WITHDRAWAL => ['cash' => -1, 'invested' => 0,  'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_FEE => ['cash' => -1, 'invested' => 0,  'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_INVESTMENT => ['cash' => -1, 'invested' => 1,  'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_REPAYMENT_PRINCIPAL => ['cash' => 1,  'invested' => -1, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_REPAYMENT_INTEREST => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_BUYBACK_PRINCIPAL => ['cash' => 1,  'invested' => -1, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_BUYBACK_INTEREST => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL => ['cash' => 1, 'invested' => -1, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        Transaction::TYPE_EARLY_REPAYMENT_INTEREST => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => 0, 'bonus_locked' => 0],
        // Locked profit accrues (текущо салдо grows), no cash move yet.
        Transaction::TYPE_INTEREST_ACCRUED => ['cash' => 0,  'invested' => 0,  'earned' => 0, 'accrued' => 1, 'bonus_locked' => 0],
        // Locked profit released into spendable available (+ earned counter).
        Transaction::TYPE_INTEREST_RELEASED => ['cash' => 1,  'invested' => 0,  'earned' => 1, 'accrued' => -1, 'bonus_locked' => 0],
        // Locked profit written OFF (principal-only buyback: the originator
        // does not cover interest, so the accrued promise is reversed, not
        // paid out). No cash move, no earned income.
        Transaction::TYPE_INTEREST_ACCRUAL_REVERSED => ['cash' => 0, 'invested' => 0, 'earned' => 0, 'accrued' => -1, 'bonus_locked' => 0],
        // Admin-granted promotional credit — spendable cash like a deposit,
        // but with NO bank wire behind it. Wallet-vs-ledger reconciliation
        // treats it as cash-in; the BANK-statement side must exclude it
        // (SUM(type='bonus') = platform marketing spend, not client money).
        Transaction::TYPE_BONUS => ['cash' => 1, 'invested' => 0, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => 0],
        // Conditional bonus (Reni 2026-08-18): granted into the LOCKED
        // bucket — visible to the investor, spendable by nobody until the
        // investment condition is met. Same marketing-spend note as
        // TYPE_BONUS: the bank statement must not expect a wire for it.
        Transaction::TYPE_BONUS_LOCKED => ['cash' => 0, 'invested' => 0, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => 1],
        // Condition met — the bonus becomes real money.
        Transaction::TYPE_BONUS_RELEASED => ['cash' => 1, 'invested' => 0, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => -1],
        // Written off (admin cancel / account closure): leaves the bucket,
        // reaches no one.
        Transaction::TYPE_BONUS_CANCELLED => ['cash' => 0, 'invested' => 0, 'earned' => 0, 'accrued' => 0, 'bonus_locked' => -1],
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

        Wallet::chunk(100, function ($wallets) use (&$mismatches, &$mismatchDetails) {
            foreach ($wallets as $wallet) {
                $userId = $wallet->user_id;

                $sums = Transaction::where('user_id', $userId)
                    ->select('type', DB::raw('SUM(amount) as total'))
                    ->groupBy('type')
                    ->pluck('total', 'type');

                $errors = [];

                // Default-deny: any persisted type without a map entry is a
                // hard error, not a silently-dropped sum.
                $unknownTypes = array_diff($sums->keys()->all(), array_keys(self::LEDGER_MAP));
                foreach ($unknownTypes as $unknownType) {
                    $errors[] = "unmapped transaction type '{$unknownType}' (sum={$sums[$unknownType]})";
                }

                $expectedCash = '0.00';
                $expectedInvested = '0.00';
                $expectedEarned = '0.00';
                $expectedAccrued = '0.00';
                $expectedBonusLocked = '0.00';

                foreach (self::LEDGER_MAP as $type => $signs) {
                    $amount = (string) ($sums[$type] ?? '0.00');
                    $expectedCash = bcadd($expectedCash, bcmul((string) $signs['cash'], $amount, 2), 2);
                    $expectedInvested = bcadd($expectedInvested, bcmul((string) $signs['invested'], $amount, 2), 2);
                    $expectedEarned = bcadd($expectedEarned, bcmul((string) $signs['earned'], $amount, 2), 2);
                    $expectedAccrued = bcadd($expectedAccrued, bcmul((string) $signs['accrued'], $amount, 2), 2);
                    $expectedBonusLocked = bcadd($expectedBonusLocked, bcmul((string) $signs['bonus_locked'], $amount, 2), 2);
                }

                $expectedAvailablePlusReserved = $expectedCash;
                $actualAvailablePlusReserved = bcadd($wallet->available, $wallet->reserved, 2);

                if (bccomp($actualAvailablePlusReserved, $expectedAvailablePlusReserved, 2) !== 0) {
                    $errors[] = "available+reserved: expected={$expectedAvailablePlusReserved}, actual={$actualAvailablePlusReserved}";
                }

                if (bccomp($wallet->invested, $expectedInvested, 2) !== 0) {
                    $errors[] = "invested: expected={$expectedInvested}, actual={$wallet->invested}";
                }

                if (bccomp($wallet->earned, $expectedEarned, 2) !== 0) {
                    $errors[] = "earned: expected={$expectedEarned}, actual={$wallet->earned}";
                }

                if (bccomp($wallet->accrued, $expectedAccrued, 2) !== 0) {
                    $errors[] = "accrued: expected={$expectedAccrued}, actual={$wallet->accrued}";
                }

                if (bccomp($wallet->bonus_locked, $expectedBonusLocked, 2) !== 0) {
                    $errors[] = "bonus_locked: expected={$expectedBonusLocked}, actual={$wallet->bonus_locked}";
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
                            'bonus_locked' => $wallet->bonus_locked,
                        ],
                        'expected' => [
                            'available+reserved' => $expectedAvailablePlusReserved,
                            'invested' => $expectedInvested,
                            'earned' => $expectedEarned,
                            'bonus_locked' => $expectedBonusLocked,
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

            return Command::FAILURE;
        }

        $this->info('OK: All wallets reconciled successfully.');

        return Command::SUCCESS;
    }

    private function sendAlertEmail(int $count, array $details): void
    {
        $adminEmail = config('app.admin_email', 'yordanyordanov0104@gmail.com');

        try {
            Mail::raw($this->formatEmailBody($count, $details), function ($message) use ($adminEmail, $count) {
                $message->to($adminEmail)
                    ->subject("[Vamaasset ALERT] Ledger mismatch detected — {$count} wallet(s)");
            });

            $this->info("Alert email sent to {$adminEmail}");
        } catch (\Throwable $e) {
            Log::error('Failed to send ledger reconciliation alert email', ['error' => $e->getMessage()]);
            $this->error("Failed to send alert email: {$e->getMessage()}");
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
