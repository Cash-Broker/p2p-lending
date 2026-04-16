<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
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

    public function generateSchedule(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            // Guard: never overwrite an existing schedule. If regeneration is
            // ever needed, that's a separate deliberate action (delete + regenerate).
            if ($loan->amortizationSchedules()->exists()) {
                throw new InvalidArgumentException('Schedule already exists for this loan.');
            }

            $principalTotal = $loan->amount;
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

            $monthlyPayment = $this->calculateMonthlyPayment($principalTotal, $monthlyRate, $termMonths);

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

                AmortizationSchedule::create([
                    'loan_id' => $loan->id,
                    'due_date' => $baseDate->copy()->addDays(30 * $i),
                    'principal' => $principal,
                    'interest' => $interest,
                    'total' => $total,
                    'status' => 'pending',
                ]);

                $remaining = bcsub($remaining, $principal, 2);
            }
        });
    }

    /**
     * Calculate fixed monthly payment via annuity formula.
     * Handles the zero-interest edge case separately (division by zero).
     */
    private function calculateMonthlyPayment(string $principal, string $monthlyRate, int $termMonths): string
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
