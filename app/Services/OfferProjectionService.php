<?php

namespace App\Services;

use App\Enums\PayoutType;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Computes the payout schedule + profit summary for a single investment under
 * one of the three offer structures. PURE: it returns arrays and PERSISTS
 * NOTHING.
 *
 * Why pure: writing a row into `amortization_schedules` would (a) flip
 * Loan::fundingCap() into "sum of schedule principal" mode mid-funding and
 * (b) make AmortizationService::generateSchedule() throw "Schedule already
 * exists" at activation. So projections never touch that table; the
 * disbursement step (InvestmentScheduleGenerator) persists into the separate
 * `investment_schedules` table instead.
 *
 * All money math is bcmath — rates at SCALE, monetary values truncated to 2
 * decimals at the boundary, never floats.
 */
class OfferProjectionService
{
    /** Matches AmortizationService::SCALE — rate math needs >2 dp precision. */
    private const SCALE = 10;

    /**
     * Per-installment schedule rows for an investment.
     *
     * @return array<int, array{due_date: CarbonInterface, principal: string, interest: string, total: string}>
     */
    public function schedule(
        string $principal,
        string $annualRate,
        int $termMonths,
        PayoutType $type,
        ?CarbonInterface $firstDueDate = null,
    ): array {
        if ($termMonths <= 0) {
            throw new InvalidArgumentException('Term must be at least 1 month.');
        }

        // Monthly rate = annual% / 100 / 12 — identical convention to
        // AmortizationService::generateSchedule().
        $monthlyRate = bcdiv(bcdiv($annualRate, '100', self::SCALE), '12', self::SCALE);

        return match ($type) {
            PayoutType::Amortizing => $this->amortizing($principal, $monthlyRate, $termMonths, $firstDueDate),
            PayoutType::InterestOnly => $this->interestOnly($principal, $monthlyRate, $termMonths, $firstDueDate),
            PayoutType::Capitalized => $this->capitalized($principal, $monthlyRate, $termMonths, $firstDueDate),
        };
    }

    /**
     * Profit + totals an investor would receive — the comparison the boss wants
     * visible across all three offers. `total_interest` is the profit (печалба).
     * Derived from {@see schedule()} so it is rounding-consistent with the rows.
     *
     * @return array{total_principal: string, total_interest: string, total_repaid: string, monthly_payment: string|null, maturity_payment: string}
     */
    public function summary(string $principal, string $annualRate, int $termMonths, PayoutType $type): array
    {
        $rows = $this->schedule($principal, $annualRate, $termMonths, $type);

        $totalPrincipal = '0.00';
        $totalInterest = '0.00';
        $totalRepaid = '0.00';
        foreach ($rows as $row) {
            $totalPrincipal = bcadd($totalPrincipal, $row['principal'], 2);
            $totalInterest = bcadd($totalInterest, $row['interest'], 2);
            $totalRepaid = bcadd($totalRepaid, $row['total'], 2);
        }

        // Representative FIRST-installment payment (capitalized pays nothing
        // monthly). NOT constant across the term: amortizing intentionally dumps
        // accumulated rounding drift into the final installment, so
        // `monthly_payment × term` does NOT reconcile to `total_repaid`. The
        // true last payment is exposed separately as `maturity_payment`, so a
        // UI can render "× (term-1) of {monthly_payment} + 1 of {maturity_payment}".
        $monthly = $type === PayoutType::Capitalized ? null : $rows[0]['total'];

        return [
            'total_principal' => $totalPrincipal,
            'total_interest' => $totalInterest,
            'total_repaid' => $totalRepaid,
            'monthly_payment' => $monthly,
            'maturity_payment' => $rows[array_key_last($rows)]['total'],
        ];
    }

    /**
     * Annuity — replicates AmortizationService::generateSchedule's loop exactly
     * (shared monthly-payment formula, interest on remaining balance, last row
     * absorbs rounding drift) so projection == live schedule.
     */
    private function amortizing(string $principal, string $monthlyRate, int $term, ?CarbonInterface $firstDueDate): array
    {
        $monthlyPayment = AmortizationService::calculateMonthlyPayment($principal, $monthlyRate, $term);

        $rows = [];
        $remaining = $principal;
        for ($i = 1; $i <= $term; $i++) {
            $interest = bcmul($remaining, $monthlyRate, 2);
            $principalPart = $i === $term ? $remaining : bcsub($monthlyPayment, $interest, 2);
            $total = bcadd($principalPart, $interest, 2);

            $rows[] = [
                'due_date' => $this->dueDate($i, $firstDueDate),
                'principal' => $principalPart,
                'interest' => $interest,
                'total' => $total,
            ];

            $remaining = bcsub($remaining, $principalPart, 2);
        }

        return $rows;
    }

    /** Interest each month; full principal in the final month. */
    private function interestOnly(string $principal, string $monthlyRate, int $term, ?CarbonInterface $firstDueDate): array
    {
        $interest = bcmul($principal, $monthlyRate, 2);

        $rows = [];
        for ($i = 1; $i <= $term; $i++) {
            $principalPart = $i === $term ? $principal : '0.00';
            $rows[] = [
                'due_date' => $this->dueDate($i, $firstDueDate),
                'principal' => $principalPart,
                'interest' => $interest,
                'total' => bcadd($principalPart, $interest, 2),
            ];
        }

        return $rows;
    }

    /**
     * Capitalized — nothing until maturity, then principal + monthly-compounded
     * interest in one lump. Compounds at SCALE and rounds only the final figures
     * (no per-month rounding → no drift). One row, on the maturity date.
     */
    private function capitalized(string $principal, string $monthlyRate, int $term, ?CarbonInterface $firstDueDate): array
    {
        $growth = bcpow(bcadd('1', $monthlyRate, self::SCALE), (string) $term, self::SCALE);
        $maturityValue = bcmul($principal, $growth, 2);
        $interest = bcsub($maturityValue, $principal, 2);

        return [[
            'due_date' => $this->dueDate($term, $firstDueDate),
            'principal' => $principal,
            'interest' => $interest,
            'total' => $maturityValue,
        ]];
    }

    /**
     * Installment due date. Mirrors AmortizationService: anchored monthly from
     * firstDueDate when given, else the legacy now()+30·i spacing.
     */
    private function dueDate(int $i, ?CarbonInterface $firstDueDate): CarbonInterface
    {
        return $firstDueDate
            ? $firstDueDate->copy()->addMonthsNoOverflow($i - 1)
            : now()->addDays(30 * $i);
    }
}
