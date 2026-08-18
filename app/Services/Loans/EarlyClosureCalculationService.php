<?php

namespace App\Services\Loans;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\Transaction;
use App\Support\DayCount;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prices an early closure of an OFFER-based loan — full or partial.
 *
 * Pure reads, no writes: {@see EarlyClosureExecutionService} calls this fresh
 * at execution time and never from a cached quote (same discipline as buyback —
 * the numbers move with every day that passes).
 *
 * Reni's rules (2026-08-18), literally:
 *   • «свива се позицията, не се намалява срокът» — the closed share comes off
 *     every remaining installment pro-rata; due dates and their count stay put;
 *   • «на всеки по 40% от неговата позиция» — the ratio is the same for every
 *     investor, applied to their own outstanding principal;
 *   • «лихвата към текущия ден» — interest on the closed principal for the days
 *     actually used, 30/360 (see {@see DayCount} for why that basis);
 *   • capitalized has no instalments: «изплащаме натрупаното, остатъкът се
 *     свива» — the closed share of the interest compounded to today.
 *
 * Σ of the per-investor closed principal equals the requested amount EXACTLY
 * (Hamilton split, same as every other distribution in this codebase).
 */
class EarlyClosureCalculationService
{
    /** Scale for the compounding intermediates, matching PayoutAccrualService. */
    private const SCALE = 10;

    public function __construct(private InvestorDistributionService $distribution) {}

    /**
     * @param  string|null  $requestedPrincipal  null = close the loan in full
     * @return array{
     *     ratio: string,
     *     is_full: bool,
     *     outstanding_total: string,
     *     principal_total: string,
     *     interest_total: string,
     *     total: string,
     *     positions: array<int, array{
     *         investment: Investment,
     *         user_id: int,
     *         payout_type: PayoutType,
     *         outstanding: string,
     *         principal: string,
     *         interest: string,
     *         accrued_release: string,
     *         total: string,
     *         rows: Collection<int, InvestmentSchedule>
     *     }>
     * }
     */
    public function quote(Loan $loan, ?string $requestedPrincipal, CarbonInterface $asOf): array
    {
        if (! $loan->usesOffers()) {
            throw new InvalidArgumentException(
                "Loan #{$loan->id} is not offer-based; use EarlyRepaymentCalculationService instead."
            );
        }

        $investments = $loan->investments()
            ->whereNotNull('loan_offer_id')
            ->with('user')
            ->orderBy('id')
            ->get();

        $positions = [];
        $outstandingByInvestment = [];

        foreach ($investments as $investment) {
            $rows = InvestmentSchedule::where('investment_id', $investment->id)
                ->whereIn('status', ['pending', 'late'])
                ->orderBy('due_date')
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                continue; // fully paid out already — nothing left to close
            }

            $outstanding = $rows->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');

            if (bccomp($outstanding, '0', 2) <= 0) {
                continue;
            }

            $outstandingByInvestment[$investment->id] = $outstanding;
            $positions[$investment->id] = [
                'investment' => $investment,
                'user_id' => $investment->user_id,
                'payout_type' => $investment->payout_type,
                'outstanding' => $outstanding,
                'rows' => $rows,
            ];
        }

        $outstandingTotal = array_reduce(
            $outstandingByInvestment,
            fn (string $carry, string $amount) => bcadd($carry, $amount, 2),
            '0.00',
        );

        if (bccomp($outstandingTotal, '0', 2) <= 0) {
            throw new InvalidArgumentException(
                "Cannot close loan #{$loan->id} early: no outstanding investor principal."
            );
        }

        $isFull = $requestedPrincipal === null;
        $closedTotal = $isFull ? $outstandingTotal : $requestedPrincipal;

        if (bccomp($closedTotal, '0', 2) <= 0) {
            throw new InvalidArgumentException('Early closure amount must be positive.');
        }

        if (bccomp($closedTotal, $outstandingTotal, 2) > 0) {
            throw new InvalidArgumentException(
                "Early closure of {$closedTotal} € exceeds the outstanding principal "
                ."of {$outstandingTotal} € on loan #{$loan->id}."
            );
        }

        // Paying the last cent turns a "partial" into a full closure — treat it
        // as one, so the loan actually reaches `repaid` instead of lingering
        // with zero-value schedule rows.
        $isFull = $isFull || bccomp($closedTotal, $outstandingTotal, 2) === 0;

        $ratio = bcdiv($closedTotal, $outstandingTotal, self::SCALE);

        // Hamilton: Σ shares == closedTotal exactly, ties by ascending id.
        $principalByInvestment = $this->distribution->largestRemainderSplit($closedTotal, $outstandingByInvestment);

        $principalTotal = '0.00';
        $interestTotal = '0.00';

        foreach ($positions as $investmentId => &$position) {
            $principal = $isFull ? $position['outstanding'] : $principalByInvestment[$investmentId];

            [$interest, $accruedRelease] = $this->interestOnClosedShare(
                $loan,
                $position['investment'],
                $position['rows'],
                $position['outstanding'],
                $principal,
                $asOf,
            );

            $position['principal'] = $principal;
            $position['interest'] = $interest;
            $position['accrued_release'] = $accruedRelease;
            $position['total'] = bcadd($principal, $interest, 2);

            $principalTotal = bcadd($principalTotal, $principal, 2);
            $interestTotal = bcadd($interestTotal, $interest, 2);
        }
        unset($position);

        return [
            'ratio' => $ratio,
            'is_full' => $isFull,
            'outstanding_total' => $outstandingTotal,
            'principal_total' => $principalTotal,
            'interest_total' => $interestTotal,
            'total' => bcadd($principalTotal, $interestTotal, 2),
            'positions' => $positions,
        ];
    }

    /**
     * Interest owed on the share being closed, priced to `asOf`.
     *
     * @param  Collection<int, InvestmentSchedule>  $rows
     * @return array{0: string, 1: string} [interest owed, accrued already locked for it]
     */
    private function interestOnClosedShare(
        Loan $loan,
        Investment $investment,
        $rows,
        string $outstanding,
        string $closedPrincipal,
        CarbonInterface $asOf,
    ): array {
        if (bccomp($closedPrincipal, '0', 2) <= 0) {
            return ['0.00', '0.00'];
        }

        if ($investment->payout_type === PayoutType::Capitalized) {
            return $this->capitalizedInterest($loan, $investment, $rows, $outstanding, $closedPrincipal, $asOf);
        }

        // Amortizing / interest-only: the investor is paid monthly, so what is
        // owed now is the stub since the last settled installment. Nothing is
        // parked in the `accrued` bucket for these plans.
        $days = DayCount::thirty360($this->lastSettledAt($investment, $asOf), $asOf);

        return [
            DayCount::interestFor($closedPrincipal, (string) $investment->interest_rate, $days),
            '0.00',
        ];
    }

    /**
     * Capitalized: nothing is paid until maturity, so «натрупаното до деня» is
     * the compounded interest earned so far — whole months compounded, the stub
     * days on top at 30/360 — times the share being closed.
     *
     * Part of it is already sitting in the investor's `accrued` bucket (the
     * payout cron puts it there monthly). That part is released rather than
     * credited again; only the difference is new interest.
     *
     * @param  Collection<int, InvestmentSchedule>  $rows
     * @return array{0: string, 1: string}
     */
    private function capitalizedInterest(
        Loan $loan,
        Investment $investment,
        $rows,
        string $outstanding,
        string $closedPrincipal,
        CarbonInterface $asOf,
    ): array {
        $start = $investment->invested_at?->copy()->startOfDay() ?? $loan->created_at->copy()->startOfDay();
        $asOfDay = $asOf->copy()->startOfDay();

        $elapsedDays = DayCount::thirty360($start, $asOfDay);
        $wholeMonths = intdiv($elapsedDays, 30);
        $stubDays = $elapsedDays % 30;

        $rate = (string) $investment->interest_rate;

        // Compound the OUTSTANDING principal for the whole months…
        $compounded = $this->compoundedInterest($outstanding, $rate, $wholeMonths);
        // …then simple interest on the grown balance for the leftover days.
        $stub = DayCount::interestFor(bcadd($outstanding, $compounded, 2), $rate, $stubDays);

        $interestToDate = bcadd($compounded, $stub, 2);

        // Cap at the contracted maturity interest: a closure on the last day
        // must never pay more than the plan promised.
        $scheduledInterest = $rows->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->interest, 2), '0.00');
        if (bccomp($interestToDate, $scheduledInterest, 2) > 0) {
            $interestToDate = $scheduledInterest;
        }

        $share = bcdiv($closedPrincipal, $outstanding, self::SCALE);
        $closedInterest = bcadd(bcmul($interestToDate, $share, self::SCALE), '0', 2);

        $accrued = $this->accruedToDate($loan->id, $investment->id);
        $accruedShare = bcadd(bcmul($accrued, $share, self::SCALE), '0', 2);
        // Never release more than what is owed for the closed share.
        $accruedRelease = bccomp($accruedShare, $closedInterest, 2) > 0 ? $closedInterest : $accruedShare;

        return [$closedInterest, $accruedRelease];
    }

    /**
     * The date interest was last settled up to: the due date of the newest paid
     * installment, or the investment date when nothing has been paid yet.
     */
    private function lastSettledAt(Investment $investment, CarbonInterface $asOf): CarbonInterface
    {
        $lastPaid = InvestmentSchedule::where('investment_id', $investment->id)
            ->where('status', 'paid')
            ->orderByDesc('due_date')
            ->value('due_date');

        $from = $lastPaid
            ? $lastPaid->copy()->startOfDay()
            : ($investment->invested_at?->copy()->startOfDay() ?? $asOf->copy()->startOfDay());

        return $from->greaterThan($asOf) ? $asOf->copy()->startOfDay() : $from;
    }

    /** P·(1+r/12)^n − P, bcmath only. Mirrors PayoutAccrualService. */
    private function compoundedInterest(string $principal, string $annualRatePct, int $months): string
    {
        if ($months <= 0) {
            return '0.00';
        }

        $monthlyRate = bcdiv(bcdiv($annualRatePct, '100', self::SCALE), '12', self::SCALE);
        $growth = bcpow(bcadd('1', $monthlyRate, self::SCALE), (string) $months, self::SCALE);

        return bcsub(bcmul($principal, $growth, 2), $principal, 2);
    }

    /** Net interest currently accrued for one investment, from the ledger. */
    private function accruedToDate(int $loanId, int $investmentId): string
    {
        $reference = "loan:{$loanId}:investment:{$investmentId}:capitalized";

        $sum = fn (string $type) => (string) (DB::table('transactions')
            ->where('reference', $reference)
            ->where('type', $type)
            ->sum('amount') ?: '0');

        return bcsub(
            bcadd($sum(Transaction::TYPE_INTEREST_ACCRUED), '0', 2),
            bcadd(
                bcadd($sum(Transaction::TYPE_INTEREST_RELEASED), '0', 2),
                bcadd($sum(Transaction::TYPE_INTEREST_ACCRUAL_REVERSED), '0', 2),
                2,
            ),
            2,
        );
    }
}
