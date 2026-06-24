<?php

namespace App\Console\Commands\Loans;

use App\Services\ScheduledPayoutService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily cron that runs scheduled payouts for every loan in AUTOMATIC payout
 * mode (Loan::payout_mode). Manual-mode loans wait for the admin's button.
 *
 * Accrues/releases on schedule regardless of whether the borrower has paid —
 * the boss's "по график" model; the platform carries the gap in the `accrued`
 * bucket / exposure report.
 */
class ProcessScheduledPayouts extends Command
{
    protected $signature = 'loans:process-payouts {--asof= : Process as of this date (Y-m-d), defaults to today}';

    protected $description = 'Run scheduled payouts for loans in automatic payout mode';

    private const LOCK_KEY = 'loans:process-payouts';

    public function handle(ScheduledPayoutService $service): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);

        if (! $lock->get()) {
            $this->error('Another loans:process-payouts instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            $asOf = $this->option('asof') ? Carbon::parse($this->option('asof')) : now();

            $result = $service->runAllAutomatic($asOf);

            $this->info(sprintf(
                'Scheduled payouts: %d loan(s) processed, %d failed (as of %s).',
                $result['loans_processed'],
                $result['loans_failed'],
                $asOf->toDateString(),
            ));

            if ($result['loans_failed'] > 0) {
                Log::warning('loans:process-payouts completed with failures', $result);

                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
