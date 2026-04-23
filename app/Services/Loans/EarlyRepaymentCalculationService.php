<?php

namespace App\Services\Loans;

use App\Models\Loan;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Computes early-repayment amount + pro-rata per-investor distribution.
 *
 * Pure functions over a loaded Loan — no DB writes. Called by
 * EarlyRepaymentExecutionService at execution time (calculate fresh,
 * NOT from a cached value).
 *
 * Schedule-boundary semantic (F3 Q3 approved — see DECISIONS.md):
 *   outstanding_principal = Σ schedule.principal where status IN (pending, late)
 *   unpaid_interest       = Σ schedule.interest where status IN (pending, late)
 *                            AND due_date <= next_upcoming_schedule.due_date
 *   total                 = principal + interest
 *
 * `next_upcoming_schedule.due_date` is the first unpaid schedule whose
 * due_date >= today. Intuition: borrower pays off outstanding principal
 * PLUS the interest for the CURRENT installment period (the one the
 * borrower is in now). Past-due (late) interest is included too because
 * it was already earned and the borrower is catching up.
 *
 * Fallback: if EVERY unpaid schedule is overdue (no upcoming), use the
 * latest unpaid due_date so ALL remaining interest is included. Rare —
 * means the loan is massively late, borrower is closing it out all at
 * once.
 *
 * Pro-rata distribution mirrors BuybackCalculationService: share =
 * investor_total_in_loan / loan.funded_amount (scale 10), last
 * investor absorbs residue so Σ(shares) == total exactly.
 *
 * All math uses bcmath strings. Never floats.
 */
class EarlyRepaymentCalculationService
{
    public function calculateTotal(Loan $loan): EarlyRepaymentCalculation
    {
        $unpaid = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'late'])
            ->orderBy('due_date')
            ->get(['id', 'principal', 'interest', 'due_date', 'status']);

        if ($unpaid->isEmpty()) {
            throw new InvalidArgumentException(
                "Cannot calculate early repayment for loan #{$loan->id}: no unpaid schedule items. "
                . "The loan is already fully paid OR has no schedule generated yet."
            );
        }

        // Principal: sum ALL unpaid principal (past due + upcoming).
        $principal = $unpaid->reduce(
            fn ($carry, $s) => bcadd($carry, (string) $s->principal, 2),
            '0.00',
        );

        // Determine next upcoming schedule's due_date (first unpaid with
        // due_date >= today).
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $nextUpcoming = $unpaid->first(
            fn ($s) => $s->due_date->copy()->startOfDay()->greaterThanOrEqualTo($today)
        );

        // Fallback: no upcoming → loan is fully overdue → include ALL
        // unpaid interest via using the LAST unpaid due_date as the
        // boundary (filter passes everything).
        $boundary = $nextUpcoming
            ? $nextUpcoming->due_date
            : $unpaid->last()->due_date;

        $interest = $unpaid
            ->filter(fn ($s) => $s->due_date->copy()->startOfDay()
                ->lessThanOrEqualTo($boundary->copy()->startOfDay()))
            ->reduce(
                fn ($carry, $s) => bcadd($carry, (string) $s->interest, 2),
                '0.00',
            );

        return new EarlyRepaymentCalculation(
            principal: $principal,
            interest: $interest,
            total: bcadd($principal, $interest, 2),
        );
    }

    /**
     * Distribute a calculated early-repayment total across the loan's
     * investors, pro-rata by each investor's summed investment amount.
     *
     * An investor may hold multiple Investment rows in the same loan —
     * we group by user_id and distribute ONE share per user.
     *
     * Last-investor-remainder pattern (penny-precision):
     *   For n-1 investors: share = floor(total * investor_share, scale=2)
     *   For the last: remainder = total - Σ(previously distributed)
     *   Result: Σ(all shares) == total EXACTLY.
     *
     * @return array<int, array{user_id:int, user:\App\Models\User, principal:string, interest:string, total:string}>
     * @throws InvalidArgumentException  If loan has no investors or zero funded amount.
     */
    public function distribute(Loan $loan, EarlyRepaymentCalculation $calc): array
    {
        $investments = $loan->investments()->with('user')->orderBy('id')->get();
        if ($investments->isEmpty()) {
            throw new InvalidArgumentException(
                "Loan #{$loan->id} has no investors — cannot distribute early repayment."
            );
        }

        $totalFunded = (string) $loan->funded_amount;
        if (bccomp($totalFunded, '0', 2) <= 0) {
            throw new InvalidArgumentException(
                "Loan #{$loan->id} has zero funded_amount — cannot distribute early repayment."
            );
        }

        // Group by user — one distribution entry per investor regardless of
        // how many Investment rows they hold in this loan.
        $grouped = $investments->groupBy('user_id')->map(fn ($items) => [
            'user_id' => $items->first()->user_id,
            'user'    => $items->first()->user,
            'amount'  => $items->reduce(
                fn ($carry, $inv) => bcadd($carry, (string) $inv->amount, 2),
                '0.00',
            ),
        ])->values();

        $distributedPrincipal = '0.00';
        $distributedInterest = '0.00';
        $lastIndex = $grouped->count() - 1;
        $result = [];

        foreach ($grouped as $index => $entry) {
            if ($index === $lastIndex) {
                // Last investor absorbs rounding residue — sum == total exactly.
                $principalShare = bcsub($calc->principal, $distributedPrincipal, 2);
                $interestShare = bcsub($calc->interest, $distributedInterest, 2);
            } else {
                $share = bcdiv($entry['amount'], $totalFunded, 10);
                $principalShare = bcmul($calc->principal, $share, 2);
                $interestShare = bcmul($calc->interest, $share, 2);
                $distributedPrincipal = bcadd($distributedPrincipal, $principalShare, 2);
                $distributedInterest = bcadd($distributedInterest, $interestShare, 2);
            }

            $result[] = [
                'user_id'   => $entry['user_id'],
                'user'      => $entry['user'],
                'principal' => $principalShare,
                'interest'  => $interestShare,
                'total'     => bcadd($principalShare, $interestShare, 2),
            ];
        }

        return $result;
    }
}
