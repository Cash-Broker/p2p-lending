<?php

namespace App\Services;

use App\Models\Loan;

/**
 * F5 — Annual Percentage Rate (APR / ГПР) calculator for loans.
 *
 * v1 strategy — **nominal pass-through.** APR equals
 * `loans.interest_rate_annual` formatted to 2 decimals. For a no-fee
 * annuity loan the nominal borrower rate IS the EU CCD APR by
 * definition (solution of the cash-flow IRR equation
 * `Σ drawdowns / (1+X)^t_d = Σ repayments / (1+X)^t_r` collapses to X
 * = nominal rate when there are no fees). Therefore a one-line
 * formatter is mathematically correct — no approximation, no
 * simplification.
 *
 * Upgrade path — when borrower-side fees activate (F4 origination /
 * service / late fee categories, or any future regulator-mandated
 * disclosure component), swap this method's internals to a
 * Newton-Raphson IRR solver in bcmath. The caller contract is
 * preserved: the service takes a Loan, returns a 2-decimal string or
 * null. See DECISIONS.md F5-01 for full rationale and future-path
 * scaffolding.
 *
 * **F1-L6 activation guard.** The `interest_rate_annual` column was
 * introduced in F1 as "metadata-only" — nothing in any computation or
 * UI referenced it until F5. The column is `NOT NULL decimal(5,2)` at
 * the DB level, so no production row can be null. A defensive guard
 * against zero/negative values returns null → UI renders "—" rather
 * than a misleading "0.00%". Relevant in two edge cases only:
 *   1. A badly-backfilled row (someone bypassed the Filament form).
 *   2. A test fixture that deliberately seeds zero to probe this path.
 *
 * Single public entry point: {@see calculate()}. Pure function, no
 * side effects, no DB writes, no caching at this layer (callers decide
 * whether to memoize).
 */
class APRCalculatorService
{
    /**
     * Compute the APR for a loan.
     *
     * @return string|null  2-decimal string (e.g. "10.50") when
     *                      calculable; null when the source data is
     *                      missing or non-positive (caller should
     *                      render as "—").
     */
    public function calculate(Loan $loan): ?string
    {
        $rate = $loan->interest_rate_annual;

        if ($rate === null || bccomp((string) $rate, '0', 2) <= 0) {
            return null;
        }

        return number_format((float) $rate, 2, '.', '');
    }
}
