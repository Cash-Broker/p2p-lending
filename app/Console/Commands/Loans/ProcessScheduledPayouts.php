<?php

namespace App\Console\Commands\Loans;

use App\Models\PlatformMetric;
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
 *
 * Health: writes to `platform_metrics` (read via /api/health/scheduler —
 * this is the cron that PAYS investors; a silent death here surfaces as
 * angry investors, so it must be as visible as the late/buyback crons):
 *   last_payouts_run_at          — Iso-8601 timestamp
 *   last_payouts_status          — success | failure (failure = some loans errored)
 *   last_payouts_loans_processed — int
 *   last_payouts_loans_failed    — int
 * The metrics are written whenever a run COMPLETES (even with per-loan
 * failures — the machinery ran; `last_payouts_status` carries the outcome).
 * An uncaught crash writes nothing and the staleness alarm fires instead.
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

            $this->writeMetrics($result);

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

    /** Upsert the health metrics; measured_at is stamped by PlatformMetric::record. */
    private function writeMetrics(array $result): void
    {
        $metrics = [
            'last_payouts_run_at' => now()->toIso8601String(),
            'last_payouts_status' => $result['loans_failed'] > 0 ? 'failure' : 'success',
            'last_payouts_loans_processed' => (string) $result['loans_processed'],
            'last_payouts_loans_failed' => (string) $result['loans_failed'],
        ];

        foreach ($metrics as $key => $value) {
            PlatformMetric::record($key, $value);
        }
    }
}
