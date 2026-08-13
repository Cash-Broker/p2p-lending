<?php

namespace App\Console\Commands\Loans;

use App\Models\Investment;
use App\Models\Loan;
use App\Services\InvestmentScheduleGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off deploy companion for the 2026-08-13 client decision («след като
 * клиент инвестира, олихвяването си тръгва веднага за него»): offer-based
 * investments created BEFORE schedules moved to invest time have no
 * investment_schedules until their loan activates. This backfills them,
 * anchored on each investment's OWN invested_at — i.e. retroactively from
 * the invest moment, which is exactly the client's decision.
 *
 * ⚠ Consequence to sign off before running in prod: rows whose due dates
 * already passed become payable immediately — the next payout run (cron
 * 04:00 or the admin button) pays the catch-up in one go.
 *
 * Idempotent: investments that already have schedules are skipped
 * (generateForInvestment's skip-if-exists guard).
 */
class BackfillInvestmentSchedules extends Command
{
    protected $signature = 'loans:backfill-investment-schedules {--dry-run : List what would be generated without writing}';

    protected $description = 'Generate missing per-investment schedules anchored at each investment\'s invest date';

    public function handle(InvestmentScheduleGenerator $generator): int
    {
        $candidates = Investment::query()
            ->whereNotNull('loan_offer_id')
            ->whereDoesntHave('schedules')
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES))
            ->with('loan')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to backfill — every eligible investment already has a schedule.');

            return self::SUCCESS;
        }

        foreach ($candidates as $investment) {
            $anchor = $investment->invested_at ?? $investment->created_at;

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '[dry-run] investment #%d (loan #%d, %s, %s €) — would anchor at %s',
                    $investment->id,
                    $investment->loan_id,
                    $investment->payout_type?->value ?? '?',
                    $investment->amount,
                    $anchor->toDateString(),
                ));

                continue;
            }

            DB::transaction(function () use ($generator, $investment, $anchor) {
                $generator->generateForInvestment($investment, $investment->loan, $anchor);
            });

            $this->line(sprintf('Backfilled investment #%d (loan #%d), anchored %s.',
                $investment->id, $investment->loan_id, $anchor->toDateString()));
        }

        $this->info(sprintf('%s %d investment(s).',
            $this->option('dry-run') ? 'Would backfill' : 'Backfilled', $candidates->count()));

        return self::SUCCESS;
    }
}
