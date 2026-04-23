<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\Originator;
use App\Models\PlatformSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only service that finds loans which have crossed the buyback trigger
 * threshold and are not yet eligibility-flagged / dismissed / bought back.
 *
 * DOES NOT mutate state. The ProcessBuybacks command (Step 3) sets
 * `loans.buyback_eligible_at` AFTER reading this service's output, and
 * also writes the `buyback_triggered` LoanEvent per Q22.
 *
 * Eligibility rule (all of):
 *   1. loan.status = 'late'  (buyback detection only for late loans;
 *      default loans can still be bought back but admin must initiate
 *      manually from the Queue per DECISIONS.md).
 *   2. loan.became_late_at IS NOT NULL  (defense — F1 always sets this
 *      on active→late, but guard anyway).
 *   3. loan.buyback_eligible_at IS NULL  (not already flagged).
 *   4. loan.buyback_dismissed_at IS NULL  (admin hasn't dismissed).
 *   5. loan.bought_back_at IS NULL  (not already executed).
 *   6. loan.originator.buyback = TRUE  (master switch — originators with
 *      buyback=false are excluded regardless of per-originator config).
 *   7. became_late_at + trigger_days <= today, where
 *      trigger_days = originator.buyback_trigger_days ?? platform default.
 *      Explicit `??` coalescing per Q1.a — 0 is a valid value (immediate
 *      eligibility for a zero-grace originator), NOT "use default".
 *
 * Timezone: uses config('app.timezone'), matching F1 LateDetectionService.
 *
 * Scale: per-row PHP filter after fetching late loans. Expected N is small
 * (the delinquent pipeline); F1 uses the same pattern. If N grows, convert
 * to a SQL join with DATE_SUB + COALESCE.
 */
class BuybackEligibilityService
{
    /**
     * Find loans newly eligible for buyback today.
     *
     * @param  ?Carbon  $today  Override for tests / --date flag. Defaults to
     *                          start-of-day in the app timezone.
     * @param  ?array<int>  $loanIdsFilter  Optional restriction for the
     *                                      `--loan=ID` debug flag.
     * @return Collection<int, Loan>  Eligible loans, originator eager-loaded.
     */
    public function detectNewlyEligible(?Carbon $today = null, ?array $loanIdsFilter = null): Collection
    {
        $today = $today
            ? $today->copy()->startOfDay()
            : Carbon::now(config('app.timezone'))->startOfDay();

        $defaultTriggerDays = (int) PlatformSetting::get('buyback_default_trigger_days', 60);

        $q = Loan::where('status', Loan::STATUS_LATE)
            ->whereNotNull('became_late_at')
            ->whereNull('buyback_eligible_at')
            ->whereNull('buyback_dismissed_at')
            ->whereNull('bought_back_at')
            ->whereHas('originator', fn ($o) => $o->where('buyback', true))
            ->with('originator');

        if ($loanIdsFilter !== null) {
            $q->whereIn('id', $loanIdsFilter);
        }

        return $q->get()->filter(function (Loan $loan) use ($today, $defaultTriggerDays) {
            $triggerDays = $loan->originator->buyback_trigger_days ?? $defaultTriggerDays;
            $threshold = $today->copy()->subDays($triggerDays);

            // became_late_at is a timestamp; compare at start-of-day so a loan
            // that went late on the morning of day N counts exactly as
            // crossing the threshold on day N + trigger_days.
            return $loan->became_late_at->copy()->startOfDay()->lessThanOrEqualTo($threshold);
        })->values();
    }

    /**
     * Effective coverage for a loan: per-originator override or platform default.
     *
     * Explicit `??` per Q1.a. Returns one of BuybackCalculationService::COVERAGE_*.
     */
    public function resolveCoverage(Originator $originator): string
    {
        return $originator->buyback_coverage
            ?? PlatformSetting::get('buyback_default_coverage', BuybackCalculationService::COVERAGE_PRINCIPAL_PLUS_INTEREST);
    }

    /**
     * Effective trigger days: per-originator override or platform default.
     *
     * Explicit `??` per Q1.a — 0 is a valid value (immediate eligibility
     * for a zero-grace originator), NOT "use default".
     */
    public function resolveTriggerDays(Originator $originator): int
    {
        return $originator->buyback_trigger_days
            ?? (int) PlatformSetting::get('buyback_default_trigger_days', 60);
    }
}
