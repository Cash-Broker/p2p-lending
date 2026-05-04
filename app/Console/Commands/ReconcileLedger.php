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

                // Expected available+reserved = deposits - withdrawals - investments + repay_principal + repay_interest - fees
                $credits = bcadd(bcadd($deposits, $repayPrincipal, 2), $repayInterest, 2);
                $debits = bcadd(bcadd($withdrawals, $investments, 2), $fees, 2);
                $expectedAvailablePlusReserved = bcsub($credits, $debits, 2);
                $actualAvailablePlusReserved = bcadd($wallet->available, $wallet->reserved, 2);

                $expectedInvested = bcsub($investments, $repayPrincipal, 2);
                $expectedEarned = $repayInterest;

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
