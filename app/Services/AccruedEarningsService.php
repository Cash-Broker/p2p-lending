<?php

namespace App\Services;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Services\Loans\InvestorDistributionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * READ-ONLY display math for the dashboard «Спечелени» ticker (boss feature
 * 2026-08-13): interest the investor has earned to date per their repayment
 * plan but that has NOT yet been paid out to `available`. Purely informational
 * — «само за справка, не може да разполага с тези пари» — it never touches
 * wallets, ledgers, or schedules, and rounding here is display rounding, not
 * money movement.
 *
 * Semantics mirror the actual payout engine so the reference number and the
 * real money agree:
 *
 *   • Only loans the engine pays (STATUS_ACTIVE / STATUS_LATE — the exact
 *     filter of ScheduledPayoutService::runAllAutomatic). Default is where the
 *     open write-off decision lives; the engine stops there, so do we.
 *   • Unpaid (pending/late) schedule rows only. A row's interest accrues
 *     linearly across its period and counts IN FULL once due-but-unpaid; the
 *     moment the engine marks it paid the amount leaves this counter and shows
 *     up in the wallet's `earned` («Изплатени») instead.
 *   • Capitalized plans follow PayoutAccrualService's monthly compounding
 *     milestones (same firstDue anchor, same targets), interpolated linearly
 *     inside the current month; the final month interpolates toward the
 *     schedule row's EXACT interest — the engine's own maturity source of
 *     truth — so display == engine at every milestone.
 *   • Legacy (pre-offer) investments approximate their share of the loan-level
 *     amortization interest by invested weight — the same weights
 *     RepaymentService feeds to largestRemainderSplit. The eventual Hamilton
 *     split may differ by a stotinka; acceptable for a reference display.
 *
 * Two granularities are returned because the client wants both variants:
 *   amount_daily — steps once per full elapsed day («всеки ден му тропат»),
 *   amount_live  — second-granular base for the front-end live ticker.
 */
class AccruedEarningsService
{
    /** Rate-math scale — matches PayoutAccrualService / OfferProjectionService. */
    private const SCALE = 10;

    /**
     * Generation spacing of schedule due dates (InvestmentScheduleGenerator /
     * AmortizationService legacy convention: due_i = activation + 30·i days).
     * Used to reconstruct the first period's start — no activated_at column.
     */
    private const PERIOD_DAYS = 30;

    public function __construct(private InvestorDistributionService $distribution) {}

    /**
     * @return array{amount_daily:string, amount_live:string, daily_rate:string, per_second_rate:string, as_of:string}
     */
    public function forUser(int $userId, ?CarbonInterface $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        $totals = [
            'daily' => '0.00',
            'live' => '0.000000',
            'rate_per_day' => '0.0000000000',
        ];

        $investments = Investment::query()
            ->where('user_id', $userId)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_LATE]))
            ->with([
                'loan',
                'schedules' => fn ($q) => $q->orderBy('due_date')->orderBy('id'),
            ])
            ->get();

        foreach ($investments as $investment) {
            if (! $investment->usesOffer()) {
                continue; // handled per-loan below (share of the loan schedule)
            }

            if ($investment->payout_type === PayoutType::Capitalized) {
                $this->addCapitalized($investment, $asOf, $totals);
            } else {
                $this->addScheduledRows(
                    $investment->schedules,
                    $asOf,
                    $totals,
                    // Offer rows carry the investor's own interest — full weight.
                    '1',
                );
            }
        }

        // Legacy (null-offer) investments: the schedule lives on the LOAN; the
        // investor earns their invested-weight share of each row's interest.
        $legacyLoans = $investments
            ->filter(fn (Investment $i) => ! $i->usesOffer())
            ->pluck('loan')
            ->unique('id');

        foreach ($legacyLoans as $loan) {
            $this->addLegacyLoan($loan, $userId, $asOf, $totals);
        }

        $perSecond = bcdiv($totals['rate_per_day'], '86400', self::SCALE);

        return [
            'amount_daily' => bcadd($totals['daily'], '0', 2),
            'amount_live' => bcadd($totals['live'], '0', 6),
            'daily_rate' => bcadd($totals['rate_per_day'], '0', 4),
            'per_second_rate' => $perSecond,
            'as_of' => $asOf->toIso8601String(),
        ];
    }

    /**
     * Accrue interest across period-based rows (amortizing / interest-only /
     * legacy amortization). Each row's period runs from the previous row's due
     * date (or due − 30 days for the first row — the generation spacing, i.e.
     * activation) to its own due date.
     *
     * @param  Collection<int, Model>  $rows  ordered by due_date,id; must expose due_date/interest/status
     * @param  string  $weight  multiplier on each row's interest (legacy invested share; '1' for own rows)
     */
    private function addScheduledRows(Collection $rows, CarbonInterface $asOf, array &$totals, string $weight): void
    {
        $prevDue = null;

        foreach ($rows as $row) {
            $periodEnd = $row->due_date->copy()->startOfDay();
            $periodStart = $prevDue ?? $periodEnd->copy()->subDays(self::PERIOD_DAYS);
            $prevDue = $periodEnd;

            if (! in_array($row->status, ['pending', 'late'], true)) {
                continue; // paid — already counted in wallet `earned`
            }

            $interest = bcmul((string) $row->interest, $weight, self::SCALE);
            if (bccomp($interest, '0', 2) <= 0) {
                continue;
            }

            if ($asOf->gte($periodEnd)) {
                // Due but unpaid — earned in full, waiting for the payout run.
                $totals['daily'] = bcadd($totals['daily'], bcadd($interest, '0', 2), 2);
                $totals['live'] = bcadd($totals['live'], bcadd($interest, '0', 6), 6);

                continue;
            }

            if ($asOf->lte($periodStart)) {
                continue; // period not started yet
            }

            $this->addProRata($interest, $periodStart, $periodEnd, $asOf, $totals);
        }
    }

    /**
     * Capitalized: base = the engine's compounding target for the elapsed
     * monthly milestones, plus linear interpolation toward the next milestone.
     * Milestone dates replicate PayoutAccrualService::processCapitalized.
     */
    private function addCapitalized(Investment $investment, CarbonInterface $asOf, array &$totals): void
    {
        /** @var InvestmentSchedule|null $row capitalized has exactly one maturity row */
        $row = $investment->schedules
            ->first(fn ($r) => in_array($r->status, ['pending', 'late'], true));

        if (! $row) {
            return; // matured + released (or nothing generated yet)
        }

        $term = (int) $investment->loan->term_months;
        if ($term <= 0) {
            return;
        }

        $principal = (string) $investment->amount;
        $finalInterest = (string) $row->interest;
        $maturity = $row->due_date->copy()->startOfDay();
        $firstDue = $maturity->copy()->subMonthsNoOverflow($term - 1);
        $asOfDay = $asOf->copy()->startOfDay();

        if ($asOf->gte($maturity)) {
            // Matured but unpaid — the full schedule interest is earned.
            $totals['daily'] = bcadd($totals['daily'], $finalInterest, 2);
            $totals['live'] = bcadd($totals['live'], bcadd($finalInterest, '0', 6), 6);

            return;
        }

        // Elapsed milestones — IDENTICAL indexing to the payout engine.
        if ($asOfDay->lt($firstDue)) {
            $elapsed = 0;
        } else {
            $elapsed = min($term - 1, (int) $firstDue->diffInMonths($asOfDay) + 1);
        }

        if ($elapsed === 0) {
            // First month: from activation (maturity − 30·term days — the
            // generation spacing) toward the first milestone target.
            $segStart = $maturity->copy()->subDays(self::PERIOD_DAYS * $term);
            $segEnd = $firstDue;
        } else {
            $segStart = $firstDue->copy()->addMonthsNoOverflow($elapsed - 1);
            $segEnd = $firstDue->copy()->addMonthsNoOverflow($elapsed);
        }

        $base = $this->compoundedInterestToDate($principal, (string) $investment->interest_rate, $elapsed);
        // Final segment interpolates toward the schedule's EXACT interest —
        // the same source of truth the engine reconciles to at maturity.
        $next = $elapsed + 1 >= $term
            ? $finalInterest
            : $this->compoundedInterestToDate($principal, (string) $investment->interest_rate, $elapsed + 1);

        $totals['daily'] = bcadd($totals['daily'], $base, 2);
        $totals['live'] = bcadd($totals['live'], bcadd($base, '0', 6), 6);

        $segmentInterest = bcsub($next, $base, self::SCALE);
        if (bccomp($segmentInterest, '0', 2) > 0 && $asOf->gt($segStart) && $asOf->lt($segEnd)) {
            $this->addProRata($segmentInterest, $segStart, $segEnd, $asOf, $totals);
        }
    }

    /**
     * Legacy loan: user's invested-weight share of every unpaid amortization
     * row — the same weights RepaymentService splits interest by.
     */
    private function addLegacyLoan(Loan $loan, int $userId, CarbonInterface $asOf, array &$totals): void
    {
        $outstanding = $this->distribution->outstandingPrincipalByUser($loan);

        $totalInvested = $outstanding->reduce(
            fn (string $carry, array $entry) => bcadd($carry, $entry['invested'], 2),
            '0.00',
        );
        $userEntry = $outstanding->firstWhere('user_id', $userId);
        $userInvested = $userEntry ? $userEntry['invested'] : '0.00';

        if (bccomp($totalInvested, '0', 2) <= 0 || bccomp($userInvested, '0', 2) <= 0) {
            return;
        }

        $share = bcdiv($userInvested, $totalInvested, self::SCALE);

        $rows = $loan->amortizationSchedules()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $this->addScheduledRows($rows, $asOf, $totals, $share);
    }

    /**
     * Add the elapsed fraction of $interest for a period containing $asOf —
     * day-granular into `daily`, second-granular into `live`, and the per-day
     * pace into `rate_per_day` (only currently-running periods have a pace).
     */
    private function addProRata(string $interest, CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonInterface $asOf, array &$totals): void
    {
        $periodSeconds = (int) $periodStart->diffInSeconds($periodEnd);
        if ($periodSeconds <= 0) {
            return; // degenerate period — nothing sane to prorate
        }

        $periodDays = max(1, (int) round($periodStart->diffInDays($periodEnd)));
        $elapsedDays = min($periodDays, max(0, (int) floor($periodStart->diffInDays($asOf))));
        $elapsedSeconds = min($periodSeconds, max(0, (int) $periodStart->diffInSeconds($asOf)));

        $dailyPart = bcdiv(bcmul($interest, (string) $elapsedDays, self::SCALE), (string) $periodDays, 2);
        $livePart = bcdiv(bcmul($interest, (string) $elapsedSeconds, self::SCALE), (string) $periodSeconds, 6);

        $totals['daily'] = bcadd($totals['daily'], $dailyPart, 2);
        $totals['live'] = bcadd($totals['live'], $livePart, 6);
        $totals['rate_per_day'] = bcadd(
            $totals['rate_per_day'],
            bcdiv($interest, (string) $periodDays, self::SCALE),
            self::SCALE,
        );
    }

    /** Compounded interest after $months — same formula as PayoutAccrualService. */
    private function compoundedInterestToDate(string $principal, string $annualRatePct, int $months): string
    {
        if ($months <= 0) {
            return '0.00';
        }

        $r = bcdiv(bcdiv($annualRatePct, '100', self::SCALE), '12', self::SCALE);
        $growth = bcpow(bcadd('1', $r, self::SCALE), (string) $months, self::SCALE);

        return bcsub(bcmul($principal, $growth, 2), $principal, 2);
    }
}
