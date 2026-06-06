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

    public function handle(): int
    {
        $mismatches = 0;
        $mismatchDetails = [];

        Wallet::chunk(100, function ($wallets) use (&$mismatches, &$mismatchDetails) {
            foreach ($wallets as $wallet) {
                $userId = $wallet->user_id;

                $sums = Transaction::where('user_id', $userId)
                    ->select('type', DB::raw('SUM(amount) as total'))
                    ->groupBy('type')
                    ->pluck('total', 'type');

                $deposits = $sums[Transaction::TYPE_DEPOSIT] ?? '0.00';
                $withdrawals = $sums[Transaction::TYPE_WITHDRAWAL] ?? '0.00';
                $investments = $sums[Transaction::TYPE_INVESTMENT] ?? '0.00';
                $repayPrincipal = $sums[Transaction::TYPE_REPAYMENT_PRINCIPAL] ?? '0.00';
                $repayInterest = $sums[Transaction::TYPE_REPAYMENT_INTEREST] ?? '0.00';
                $fees = $sums[Transaction::TYPE_FEE] ?? '0.00';

                // Buyback (F2) and early-repayment (F3) distributions move money
                // through the SAME wallet buckets as scheduled repayments:
                //   *_principal → invested → available  (like repayment_principal)
                //   *_interest  → available + earned    (like repayment_interest)
                // They MUST be folded into the reconciliation or every wallet that
                // ever received a buyback / early repayment reports a permanent
                // false mismatch — silencing the alert that guards real money.
                $buybackPrincipal = $sums[Transaction::TYPE_BUYBACK_PRINCIPAL] ?? '0.00';
                $buybackInterest = $sums[Transaction::TYPE_BUYBACK_INTEREST] ?? '0.00';
                $earlyPrincipal = $sums[Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL] ?? '0.00';
                $earlyInterest = $sums[Transaction::TYPE_EARLY_REPAYMENT_INTEREST] ?? '0.00';

                // All principal-return types behave alike (invested → available);
                // all interest types behave alike (→ available + earned).
                $principalReturns = bcadd(bcadd($repayPrincipal, $buybackPrincipal, 2), $earlyPrincipal, 2);
                $interestReturns = bcadd(bcadd($repayInterest, $buybackInterest, 2), $earlyInterest, 2);

                // Expected available+reserved = deposits + all principal returns
                //   + all interest returns - withdrawals - investments - fees
                $credits = bcadd(bcadd($deposits, $principalReturns, 2), $interestReturns, 2);
                $debits = bcadd(bcadd($withdrawals, $investments, 2), $fees, 2);
                $expectedAvailablePlusReserved = bcsub($credits, $debits, 2);
                $actualAvailablePlusReserved = bcadd($wallet->available, $wallet->reserved, 2);

                // Floor at zero to mirror WalletService's documented pro-rata
                // clamp (DECISIONS.md P2-01): the last investor's invested bucket
                // is clamped to 0 rather than going negative when cumulative
                // principal returns exceed their original stake by 1–2 stotinki.
                // Without this floor the clamp would itself trip a false mismatch.
                $expectedInvested = bcsub($investments, $principalReturns, 2);
                if (bccomp($expectedInvested, '0', 2) < 0) {
                    $expectedInvested = '0.00';
                }
                $expectedEarned = $interestReturns;

                $errors = [];

                if (bccomp($actualAvailablePlusReserved, $expectedAvailablePlusReserved, 2) !== 0) {
                    $errors[] = "available+reserved: expected={$expectedAvailablePlusReserved}, actual={$actualAvailablePlusReserved}";
                }

                if (bccomp($wallet->invested, $expectedInvested, 2) !== 0) {
                    $errors[] = "invested: expected={$expectedInvested}, actual={$wallet->invested}";
                }

                if (bccomp($wallet->earned, $expectedEarned, 2) !== 0) {
                    $errors[] = "earned: expected={$expectedEarned}, actual={$wallet->earned}";
                }

                if (! empty($errors)) {
                    $mismatches++;
                    $errorMsg = "Ledger mismatch for user #{$userId}: " . implode('; ', $errors);
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
                            'available+reserved' => $expectedAvailablePlusReserved,
                            'invested' => $expectedInvested,
                            'earned' => $expectedEarned,
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
        $body .= "Timestamp: " . now()->toIso8601String() . "\n";
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
