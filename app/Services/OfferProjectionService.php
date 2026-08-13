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
        ?CarbonInterface $anchor = null,
    ): array {
        if ($termMonths <= 0) {
            throw new InvalidArgumentException('Term must be at least 1 month.');
        }

        // Monthly rate = annual% / 100 / 12 — identical convention to
        // AmortizationService::generateSchedule().
        $monthlyRate = bcdiv(bcdiv($annualRate, '100', self::SCALE), '12', self::SCALE);

        return match ($type) {
            PayoutType::Amortizing => $this->amortizing($principal, $monthlyRate, $termMonths, $firstDueDate, $anchor),
            PayoutType::InterestOnly => $this->interestOnly($principal, $monthlyRate, $termMonths, $firstDueDate, $anchor),
            PayoutType::Capitalized => $this->capitalized($principal, $monthlyRate, $termMonths, $firstDueDate, $anchor),
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
    private function amortizing(string $principal, string $monthlyRate, int $term, ?CarbonInterface $firstDueDate, ?CarbonInterface $anchor = null): array
    {
        $monthlyPayment = AmortizationService::calculateMonthlyPayment($principal, $monthlyRate, $term);

        $rows = [];
        $remaining = $principal;
        for ($i = 1; $i <= $term; $i++) {
            $interest = bcmul($remaining, $monthlyRate, 2);
            $principalPart = $i === $term ? $remaining : bcsub($monthlyPayment, $interest, 2);
            $total = bcadd($principalPart, $interest, 2);

            $rows[] = [
                'due_date' => $this->dueDate($i, $firstDueDate, $anchor),
                'principal' => $principalPart,
                'interest' => $interest,
                'total' => $total,
            ];

            $remaining = bcsub($remaining, $principalPart, 2);
        }

        return $rows;
    }

    /** Interest each month; full principal in the final month. */
    private function interestOnly(string $principal, string $monthlyRate, int $term, ?CarbonInterface $firstDueDate, ?CarbonInterface $anchor = null): array
    {
        $interest = bcmul($principal, $monthlyRate, 2);

        $rows = [];
        for ($i = 1; $i <= $term; $i++) {
            $principalPart = $i === $term ? $principal : '0.00';
            $rows[] = [
                'due_date' => $this->dueDate($i, $firstDueDate, $anchor),
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
    private function capitalized(string $principal, string $monthlyRate, int $term, ?CarbonInterface $firstDueDate, ?CarbonInterface $anchor = null): array
    {
        $growth = bcpow(bcadd('1', $monthlyRate, self::SCALE), (string) $term, self::SCALE);
        $maturityValue = bcmul($principal, $growth, 2);
        $interest = bcsub($maturityValue, $principal, 2);

        return [[
            'due_date' => $this->dueDate($term, $firstDueDate, $anchor),
            'principal' => $principal,
            'interest' => $interest,
            'total' => $maturityValue,
        ]];
    }

    /**
     * Reconstruct the TERM (months) a capitalized schedule row was generated
     * with, from its own frozen figures: interest = P·((1+r)^n − 1) ⇒
     * n = ln((P+I)/P) / ln(1+r).
     *
     * The live loan.term_months must NEVER feed capitalized milestone math:
     * loan fields are editable in every status (client decision 2026-08-10)
     * while the row is frozen at invest, so a term edit would silently move
     * the engine's compounding milestones off the row — minting phantom
     * accrued interest (term extended) or stalling accrual (term shortened).
     * Adversarial review finding 2026-08-14.
     *
     * Float log() is deliberate and safe here: this derives an integer month
     * COUNT (consecutive n values differ by ~1.2% in log space — far beyond
     * float error), never a money amount.
     */
    public static function capitalizedTermFromRow(string $principal, string $annualRatePct, string $rowInterest, int $fallbackTerm): int
    {
        if (bccomp($principal, '0', 2) <= 0
            || bccomp($annualRatePct, '0', 2) <= 0
            || bccomp($rowInterest, '0', 2) <= 0) {
            return max(1, $fallbackTerm);
        }

        $monthlyRate = (float) bcdiv(bcdiv($annualRatePct, '100', self::SCALE), '12', self::SCALE);
        $growth = ((float) $principal + (float) $rowInterest) / (float) $principal;

        return max(1, (int) round(log($growth) / log(1.0 + $monthlyRate)));
    }

    /**
     * Installment due date. Mirrors AmortizationService: anchored monthly from
     * firstDueDate when given, else the legacy 30·i-day spacing counted from
     * $anchor (defaults to now — e.g. the invest moment for schedules
     * generated at invest time, or the activation moment for legacy catch-up).
     */
    private function dueDate(int $i, ?CarbonInterface $firstDueDate, ?CarbonInterface $anchor = null): CarbonInterface
    {
        return $firstDueDate
            ? $firstDueDate->copy()->addMonthsNoOverflow($i - 1)
            : ($anchor ?? now())->copy()->addDays(30 * $i);
    }
}
