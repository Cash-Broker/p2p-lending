<?php

namespace App\Support\Loans;

use App\Models\Loan;

/**
 * Balancing guard for hand-edited amortization schedule rows.
 *
 * The buyback (BuybackCalculationService) and early-repayment
 * (EarlyRepaymentCalculationService) payouts are derived by SUMMING the
 * schedule's principal/interest directly. An inconsistent hand-edited schedule
 * therefore silently drives wrong investor payout totals. These checks reject
 * the two ways a row can break that invariant:
 *
 *   1. total != principal + interest      — per-row, exact, unconditionally safe.
 *   2. Σ(principal) > amortization base    — over-distribution direction.
 *
 * We block OVERSHOOT (not exact equality) on save so the admin can still build a
 * schedule incrementally row-by-row; over-scheduling principal is the direction
 * that over-pays investors (platform loss). Exact Σ == base is a separate
 * publish/activate-time concern, coordinated with the planned investable_amount
 * work via Loan::amortizationBase().
 *
 * All arithmetic uses bcmath — never floats.
 */
class ScheduleBalanceValidator
{
    /**
     * Per-row: `total` must equal `principal + interest` (cent-exact).
     * Returns an error message, or null when valid / not yet numeric.
     */
    public static function rowTotalError(string $principal, string $interest, string $total): ?string
    {
        if (! is_numeric($principal) || ! is_numeric($interest) || ! is_numeric($total)) {
            return null; // defer to the field's required/numeric rules
        }

        if (bccomp(bcadd($principal, $interest, 2), $total, 2) !== 0) {
            return 'Общо (€) трябва да е равно на главница + лихва.';
        }

        return null;
    }

    /**
     * Cross-row: the scheduled principal across the whole loan must never exceed
     * the loan's amortization base. `$excludeRowId` is the row being edited (so
     * its current stored principal isn't double-counted); null on create.
     */
    public static function principalOvershootError(Loan $loan, ?int $excludeRowId, string $principal): ?string
    {
        if (! is_numeric($principal)) {
            return null;
        }

        $othersPrincipal = $loan->amortizationSchedules()
            ->when($excludeRowId !== null, fn ($query) => $query->whereKeyNot($excludeRowId))
            ->get(['principal'])
            ->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');

        $newSum = bcadd($othersPrincipal, $principal, 2);
        $base = $loan->amortizationBase();

        if (bccomp($newSum, $base, 2) > 0) {
            return "Сборът на главниците ({$newSum} €) надхвърля сумата на кредита ({$base} €).";
        }

        return null;
    }
}
