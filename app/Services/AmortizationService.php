<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generates an amortization schedule for a loan using the standard annuity formula
 * (fixed monthly payment, principal and interest vary per installment).
 *
 * Formula: M = P * r * (1+r)^n / ((1+r)^n - 1)
 *   P = loan amount (principal)
 *   r = monthly interest rate (interest_rate / 100 / 12)
 *   n = term in months
 *
 * For each installment:
 *   interest_i  = remaining_balance * r
 *   principal_i = M - interest_i
 *
 * The LAST installment's principal is set to exactly the remaining balance
 * so that sum(principals) == loan.amount, with no rounding drift.
 *
 * All arithmetic uses bcmath — never floats — to avoid precision errors.
 */
class AmortizationService
{
    /**
     * Scale for intermediate calculations. 2 decimals is not enough precision
     * for the rate (e.g. 0.00916666...) so we use 10 for math and truncate to 2
     * only when storing monetary values.
     */
    private const SCALE = 10;

    /**
     * @param  CarbonInterface|null  $firstDueDate  Anchor for the first installment.
     *         When given, installments fall monthly from this date (used by the
     *         admin calculator, incl. listing a loan with a chosen first payment
     *         date). When null, preserves the legacy now()+30·i spacing.
     */
    public function generateSchedule(Loan $loan, ?CarbonInterface $firstDueDate = null): void
    {
        DB::transaction(function () use ($loan, $firstDueDate) {
            // Guard: never overwrite an existing schedule. If regeneration is
            // ever needed, that's a separate deliberate action (delete + regenerate).
            if ($loan->amortizationSchedules()->exists()) {
                throw new InvalidArgumentException('Schedule already exists for this loan.');
            }

            // Amortize the INVESTABLE portion (== amount when no cap is set), so
            // Σ(principal) equals exactly the capital investors put in. See
            // Loan::amortizationBase().
            $principalTotal = $loan->amortizationBase();
            $termMonths = (int) $loan->term_months;

            if ($termMonths <= 0) {
                throw new InvalidArgumentException('Loan term must be at least 1 month.');
            }

            // Monthly rate as decimal: annual % / 100 / 12
            // Matches the convention in DatabaseSeeder.php:126 (interest_rate / 1200).
            $monthlyRate = bcdiv(
                bcdiv((string) $loan->interest_rate, '100', self::SCALE),
                '12',
                self::SCALE
            );

            $monthlyPayment = self::calculateMonthlyPayment($principalTotal, $monthlyRate, $termMonths);

            $remaining = $principalTotal;
            $baseDate = now();

            for ($i = 1; $i <= $termMonths; $i++) {
                // Interest on remaining balance
                $interest = bcmul($remaining, $monthlyRate, 2);

                if ($i === $termMonths) {
                    // Last installment: principal = whatever is left.
                    // This absorbs all accumulated rounding drift so sum == loan amount.
                    $principal = $remaining;
                } else {
                    $principal = bcsub($monthlyPayment, $interest, 2);
                }

                $total = bcadd($principal, $interest, 2);

                $dueDate = $firstDueDate
                    ? $firstDueDate->copy()->addMonthsNoOverflow($i - 1)
                    : $baseDate->copy()->addDays(30 * $i);

                // Back-dated listing: an installment whose due date has already
                // elapsed was settled off-platform before the loan was listed, so
                // it's marked paid (NOT pending) — this keeps late-detection quiet
                // on activation and leaves only the outstanding stream for
                // investors. For a normal future-dated schedule nothing is marked.
                $isElapsed = $dueDate->copy()->startOfDay()->lt(now()->startOfDay());

                AmortizationSchedule::create([
                    'loan_id' => $loan->id,
                    'due_date' => $dueDate,
                    'principal' => $principal,
                    'interest' => $interest,
                    'total' => $total,
                    'status' => $isElapsed ? 'paid' : 'pending',
                    'paid_at' => $isElapsed ? $dueDate : null,
                ]);

                $remaining = bcsub($remaining, $principal, 2);
            }
        });
    }

    /**
     * Calculate fixed monthly payment via annuity formula.
     * Handles the zero-interest edge case separately (division by zero).
     *
     * Public + static so {@see \App\Services\OfferProjectionService} reuses the
     * EXACT same formula for its AMORTIZING projection — keeping projected and
     * live amortizing schedules provably identical (see OfferProjectionServiceTest).
     */
    public static function calculateMonthlyPayment(string $principal, string $monthlyRate, int $termMonths): string
    {
        // Zero-interest loan: equal principal split, no interest component
        if (bccomp($monthlyRate, '0', self::SCALE) === 0) {
            return bcdiv($principal, (string) $termMonths, 2);
        }

        // M = P * r * (1+r)^n / ((1+r)^n - 1)
        $onePlusR = bcadd('1', $monthlyRate, self::SCALE);
        $pow = bcpow($onePlusR, (string) $termMonths, self::SCALE);

        $numerator = bcmul(bcmul($principal, $monthlyRate, self::SCALE), $pow, self::SCALE);
        $denominator = bcsub($pow, '1', self::SCALE);

        return bcdiv($numerator, $denominator, 2);
    }
}
