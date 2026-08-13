<?php

namespace App\Console\Commands\Loans;

use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\PlatformMetric;
use App\Models\Wallet;
use Illuminate\Console\Command;

/**
 * Platform exposure report for the scheduled-accrual payout model.
 *
 * Because payouts accrue ON SCHEDULE regardless of whether the borrower has
 * actually paid, the platform fronts the money. This surfaces the standing
 * exposure so it is never an invisible hole:
 *
 *   • locked promise   — Σ wallets.accrued (recognised, not yet released).
 *   • at-risk payouts  — loans in late/default that are STILL auto-paying
 *                        investors on schedule (the borrower isn't servicing).
 *
 * Closing the at-risk gap (write-off / loss socialisation) is the open
 * default-handling decision — this report makes the number visible meanwhile.
 */
class ReportPayoutExposure extends Command
{
    protected $signature = 'payouts:exposure {--record : Persist the figures to platform_metrics}';

    protected $description = 'Report the platform payout exposure (accrued promise + at-risk late/default auto loans)';

    public function handle(): int
    {
        $lockedPromise = (string) (Wallet::query()->sum('accrued') ?? '0.00');
        $lockedPromise = number_format((float) $lockedPromise, 2, '.', '');

        $atRiskLoans = Loan::query()
            ->whereIn('status', [Loan::STATUS_LATE, Loan::STATUS_DEFAULT])
            ->where('payout_mode', Loan::PAYOUT_MODE_AUTOMATIC)
            ->count();

        // Funding-stage fronting (2026-08-14, follows the «олихвяването
        // тръгва от инвестицията» decision): loans that are NOT active have
        // no borrower servicing at all, yet the engine pays their investors
        // on schedule. The interest already paid there is 100 % platform
        // money — it must show up here, not be an invisible hole.
        $fundingStageStatuses = [Loan::STATUS_PUBLISHED, Loan::STATUS_FUNDING, Loan::STATUS_FUNDED];

        $fundingStageLoans = Loan::query()
            ->whereIn('status', $fundingStageStatuses)
            ->where('payout_mode', Loan::PAYOUT_MODE_AUTOMATIC)
            ->whereHas('investmentSchedules')
            ->count();

        $frontedInterest = (string) (InvestmentSchedule::query()
            ->where('status', 'paid')
            ->whereHas('loan', fn ($q) => $q->whereIn('status', $fundingStageStatuses))
            ->sum('interest') ?? '0.00');
        $frontedInterest = number_format((float) $frontedInterest, 2, '.', '');

        $this->info('Payout exposure');
        $this->line("  Locked promise (Σ accrued):            {$lockedPromise} €");
        $this->line("  At-risk auto loans (late/default):     {$atRiskLoans}");
        $this->line("  Funding-stage auto loans on the clock: {$fundingStageLoans}");
        $this->line("  Funding-stage fronted interest paid:   {$frontedInterest} €");

        if ($atRiskLoans > 0) {
            $this->warn('  ⚠ These loans keep paying investors on schedule while the borrower is not servicing.');
            $this->warn('    Resolution depends on the default/write-off policy (open product decision).');
        }

        if ($fundingStageLoans > 0) {
            $this->warn('  ⚠ Funding-stage loans pay investors before any borrower disbursement (client decision 2026-08-13).');
        }

        if ($this->option('record')) {
            PlatformMetric::record('payout_exposure_locked_promise', $lockedPromise);
            PlatformMetric::record('payout_exposure_at_risk_loans', (string) $atRiskLoans);
            PlatformMetric::record('payout_exposure_funding_stage_loans', (string) $fundingStageLoans);
            PlatformMetric::record('payout_exposure_funding_fronted_interest', $frontedInterest);
        }

        return self::SUCCESS;
    }
}
