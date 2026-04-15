<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies ledger integrity: sum of all transactions must equal wallet balances.
 *
 * Run daily to detect any drift between the transaction ledger and wallet state.
 * If a bug in the application causes a mismatch, this command will catch it
 * before real money is affected.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile';
    protected $description = 'Verify wallet balances match transaction ledger sums';

    public function handle(): int
    {
        $mismatches = 0;

        Wallet::chunk(100, function ($wallets) use (&$mismatches) {
            foreach ($wallets as $wallet) {
                $userId = $wallet->user_id;

                // Sum all transactions by type for this user
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

                // Expected available = deposits - withdrawals - investments + repay_principal + repay_interest - fees
                // Note: reserved is part of the original available that was set aside
                $expectedAvailablePlusReserved = bcsub(
                    bcadd(
                        bcadd(
                            bcadd($deposits, $repayPrincipal, 2),
                            $repayInterest,
                            2
                        ),
                        '0.00',
                        2
                    ),
                    bcadd(
                        bcadd($withdrawals, $investments, 2),
                        $fees,
                        2
                    ),
                    2
                );

                $actualAvailablePlusReserved = bcadd($wallet->available, $wallet->reserved, 2);

                // Expected invested = investments - repay_principal
                $expectedInvested = bcsub($investments, $repayPrincipal, 2);

                // Expected earned = repay_interest
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
                }
            }
        });

        if ($mismatches > 0) {
            $this->error("FAILED: {$mismatches} wallet(s) have ledger mismatches.");
            return Command::FAILURE;
        }

        $this->info('OK: All wallets reconciled successfully.');
        return Command::SUCCESS;
    }
}
