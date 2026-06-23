<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, exact per-investor money distribution primitives.
 *
 * Two building blocks shared by the repayment engine:
 *
 *   1. {@see outstandingPrincipalByUser} — each investor's UNRETURNED capital
 *      in a loan (invested − principal already returned, read from the
 *      immutable ledger). Returning EXACTLY this on a loan's final installment
 *      guarantees `Σ(principal returned to a user over the loan's life)
 *      == their invested`, with zero pro-rata drift and no invested-bucket
 *      underflow. This is the fix for the audit's cumulative-drift /
 *      clamp-money-creation family.
 *
 *   2. {@see largestRemainderSplit} — Hamilton (largest-remainder)
 *      apportionment: floor every share, then hand the leftover stotinki one
 *      at a time to the largest fractional remainders. Σ(shares) == amount
 *      EXACTLY and no single investor is over-allocated by more than 0.01 vs
 *      their exact share — replacing the "dump all residue on the
 *      positionally-last investor" pattern.
 *
 * All arithmetic is bcmath at scale 2 (rate intermediates at scale 10).
 */
class InvestorDistributionService
{
    /** Scale for fractional ranking intermediates. */
    private const RANK_SCALE = 12;

    /**
     * Transaction types that RETURN principal capital to an investor. Summing
     * these per user gives "principal already returned" for the outstanding
     * calculation. All three are included so the figure stays correct even if
     * a loan mixes scheduled repayments with a later buyback / early payoff.
     */
    private const PRINCIPAL_RETURN_TYPES = [
        Transaction::TYPE_REPAYMENT_PRINCIPAL,
        Transaction::TYPE_BUYBACK_PRINCIPAL,
        Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL,
    ];

    /**
     * Each investor's outstanding (unreturned) principal in this loan, one row
     * per user, in a deterministic order (ascending first-investment id).
     *
     * @return Collection<int, array{user_id:int, user:\App\Models\User, invested:string, returned:string, outstanding:string}>
     */
    public function outstandingPrincipalByUser(Loan $loan): Collection
    {
        $investments = $loan->investments()->with('user')->orderBy('id')->get();

        // Principal already returned per user, from the immutable ledger —
        // exact, reflects what actually moved (not a re-derived estimate).
        $returnedByUser = Transaction::query()
            ->whereIn('type', self::PRINCIPAL_RETURN_TYPES)
            ->where('reference', 'like', "loan:{$loan->id}:%")
            ->groupBy('user_id')
            ->select('user_id', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'user_id');

        return $investments
            ->groupBy('user_id')
            ->map(function (Collection $rows) use ($returnedByUser) {
                $userId = $rows->first()->user_id;
                $invested = $rows->reduce(
                    fn (string $carry, $inv) => bcadd($carry, (string) $inv->amount, 2),
                    '0.00',
                );
                $returned = (string) ($returnedByUser[$userId] ?? '0.00');
                $outstanding = bcsub($invested, $returned, 2);

                return [
                    'user_id'     => $userId,
                    'user'        => $rows->first()->user,
                    'invested'    => $invested,
                    'returned'    => $returned,
                    // Never report negative outstanding — a negative would mean
                    // the ledger already over-returned (a bug elsewhere); clamp
                    // the REPORT to 0 so we never try to return more capital.
                    'outstanding' => bccomp($outstanding, '0', 2) < 0 ? '0.00' : $outstanding,
                    '_first_id'   => $rows->min('id'),
                ];
            })
            ->sortBy('_first_id')
            ->values();
    }

    /**
     * Largest-remainder (Hamilton) split of $amount across users weighted by
     * $weightByUser. Returns user_id => share (bcmath string, scale 2).
     *
     * Invariants:
     *   - Σ(shares) == $amount EXACTLY (no penny lost or conjured).
     *   - each share ≤ exact_share + 0.01 (bounded over-allocation; fair).
     *   - deterministic: ties broken by ascending user_id.
     *
     * @param  array<int, string>  $weightByUser  user_id => weight (e.g. invested)
     * @return array<int, string>  user_id => share
     */
    public function largestRemainderSplit(string $amount, array $weightByUser): array
    {
        $shares = [];
        foreach ($weightByUser as $userId => $_) {
            $shares[$userId] = '0.00';
        }

        if (bccomp($amount, '0', 2) <= 0 || empty($weightByUser)) {
            return $shares;
        }

        $totalWeight = array_reduce(
            $weightByUser,
            fn (string $carry, string $w) => bcadd($carry, $w, 2),
            '0.00',
        );

        // Degenerate: zero total weight → give everything to the first user so
        // the sum invariant still holds (rather than silently dropping money).
        if (bccomp($totalWeight, '0', 2) <= 0) {
            $firstUser = array_key_first($weightByUser);
            $shares[$firstUser] = $amount;

            return $shares;
        }

        $distributed = '0.00';
        $fractional = []; // user_id => fractional part (for remainder ranking)

        foreach ($weightByUser as $userId => $weight) {
            // exact = amount * weight / totalWeight  (high scale)
            $exact = bcdiv(bcmul($amount, $weight, self::RANK_SCALE), $totalWeight, self::RANK_SCALE);
            $floor = bcadd($exact, '0', 2); // bcmath truncates toward zero → floor for ≥0
            $shares[$userId] = $floor;
            $distributed = bcadd($distributed, $floor, 2);
            $fractional[$userId] = bcsub($exact, $floor, self::RANK_SCALE);
        }

        // Leftover stotinki to hand out (exact integer count — both operands 2dp).
        $remainderCents = (int) bcmul(bcsub($amount, $distributed, 2), '100', 0);
        if ($remainderCents <= 0) {
            return $shares;
        }

        // Rank by fractional part desc, tie-break user_id asc (deterministic).
        $order = array_keys($fractional);
        usort($order, function ($a, $b) use ($fractional) {
            $cmp = bccomp($fractional[$b], $fractional[$a], self::RANK_SCALE);

            return $cmp !== 0 ? $cmp : ($a <=> $b);
        });

        foreach ($order as $userId) {
            if ($remainderCents <= 0) {
                break;
            }
            $shares[$userId] = bcadd($shares[$userId], '0.01', 2);
            $remainderCents--;
        }

        return $shares;
    }
}
