<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Computes buyback amount + pro-rata per-investor distribution.
 *
 * Pure functions over a loaded Loan — no DB writes. Called by
 * BuybackExecutionService at execution time (per Q3 — calculate fresh,
 * NOT from a cached value set at detection time; the loan's state may
 * have changed since cron detection).
 *
 * Coverage math (per F2 Q2):
 *   principal_only            — SUM(unpaid schedule.principal)
 *   principal_plus_interest   — above + SUM(unpaid schedule.interest)
 *                               NO day-count accrued interest (scheduled
 *                               interest of unpaid installments only).
 *
 * "Unpaid" = amortization_schedules.status IN (pending, late). Not 'paid'
 * (already settled) and not 'default' (F1 leaves schedules in 'default' for
 * admin manual handling; those amounts are OUT of buyback scope).
 *
 * Pro-rata distribution mirrors RepaymentService:
 *   share = investor_total_in_loan / loan.funded_amount  (scale 10)
 *   principal_share = round(total_principal * share, 2)
 *   interest_share  = round(total_interest * share, 2)
 *   LAST investor gets remainder — guarantees sum == total (penny-precision).
 *
 * All math uses bcmath strings. Never floats.
 */
class BuybackCalculationService
{
    public function __construct(private InvestorDistributionService $distribution) {}

    public const COVERAGE_PRINCIPAL_ONLY = 'principal_only';

    public const COVERAGE_PRINCIPAL_PLUS_INTEREST = 'principal_plus_interest';

    public const COVERAGES = [
        self::COVERAGE_PRINCIPAL_ONLY,
        self::COVERAGE_PRINCIPAL_PLUS_INTEREST,
    ];

    /**
     * Compute total buyback amount for a loan.
     *
     * Resolves coverage type from originator.buyback_coverage OR platform
     * default (explicit ?? fallback per Q1.a).
     */
    public function calculateTotal(Loan $loan): BuybackCalculation
    {
        $loan->loadMissing('originator');

        $coverage = $loan->originator->buyback_coverage
            ?? PlatformSetting::get('buyback_default_coverage', self::COVERAGE_PRINCIPAL_PLUS_INTEREST);

        if (! in_array($coverage, self::COVERAGES, true)) {
            throw new InvalidArgumentException("Unknown buyback coverage type: {$coverage}");
        }

        // Load unpaid schedules ONCE — both principal and interest sums come
        // from the same rowset. Offer-based loans pay investors from their own
        // per-investment schedules, so the buyback outstanding is summed from
        // investment_schedules; legacy loans use the single per-loan schedule.
        // Either way "unpaid" = the rows the auto-payout has NOT yet released,
        // so buyback covers exactly the remainder (no double-pay).
        $unpaid = $loan->usesOffers()
            ? $loan->investmentSchedules()->whereIn('status', ['pending', 'late'])->get(['principal', 'interest'])
            : $loan->amortizationSchedules()->whereIn('status', ['pending', 'late'])->get(['principal', 'interest']);

        $principal = $this->sumField($unpaid, 'principal');
        $interest = match ($coverage) {
            self::COVERAGE_PRINCIPAL_ONLY => '0.00',
            self::COVERAGE_PRINCIPAL_PLUS_INTEREST => $this->sumField($unpaid, 'interest'),
        };

        return new BuybackCalculation(
            coverageType: $coverage,
            principal: $principal,
            interest: $interest,
            total: bcadd($principal, $interest, 2),
        );
    }

    /**
     * Distribute a calculated buyback total across the loan's investors,
     * pro-rata by each investor's summed investment amount in this loan.
     *
     * An investor may hold multiple Investment rows in the same loan —
     * we group by user_id and distribute ONE share per user (matching F1's
     * LoanWentLateNotification "one email per investor with summed amount"
     * semantic).
     *
     * Last-investor-remainder pattern (penny-precision):
     *   For n-1 investors: share = floor(total * investor_share, scale=2)
     *   For the last: remainder = total - Σ(previously distributed)
     *   Result: Σ(all shares) == total EXACTLY.
     *
     * @return array<int, array{user_id:int, user:User, principal:string, interest:string, total:string}>
     *
     * @throws InvalidArgumentException If loan has no investors or zero funded amount.
     */
    public function distribute(Loan $loan, BuybackCalculation $calc): array
    {
        // Principal is returned as each investor's EXACT outstanding capital
        // (invested − already returned), grouped by user, deterministic order.
        // This zeroes every investor's `invested` on close with no
        // over-allocation, so the hardened WalletService never clamps — and
        // Σ(outstanding) == calc->principal for a consistently-tracked loan.
        $outstanding = $this->distribution->outstandingPrincipalByUser($loan);
        if ($outstanding->isEmpty()) {
            throw new InvalidArgumentException("Loan #{$loan->id} has no investors — cannot distribute buyback.");
        }

        $totalFunded = (string) $loan->funded_amount;
        if (bccomp($totalFunded, '0', 2) <= 0) {
            throw new InvalidArgumentException("Loan #{$loan->id} has zero funded_amount — cannot distribute buyback.");
        }

        $distributedInterest = '0.00';
        $lastIndex = $outstanding->count() - 1;
        $result = [];

        foreach ($outstanding as $index => $entry) {
            $principalShare = $entry['outstanding'];

            // Interest carries no per-investor invariant — split pro-rata by
            // invested, last investor absorbs the rounding residue so
            // Σ(interest) == calc->interest exactly.
            if ($index === $lastIndex) {
                $interestShare = bcsub($calc->interest, $distributedInterest, 2);
            } else {
                $share = bcdiv($entry['invested'], $totalFunded, 10);
                $interestShare = bcmul($calc->interest, $share, 2);
                $distributedInterest = bcadd($distributedInterest, $interestShare, 2);
            }

            $result[] = [
                'user_id' => $entry['user_id'],
                'user' => $entry['user'],
                'principal' => $principalShare,
                'interest' => $interestShare,
                'total' => bcadd($principalShare, $interestShare, 2),
            ];
        }

        return $result;
    }

    /**
     * bcmath-safe sum of one decimal field across a Collection of models.
     *
     * Iterating + bcadd keeps scale 2 throughout. MySQL SUM returns a
     * float-convertible string in some DB driver paths; doing the sum in
     * PHP sidesteps precision loss for very large portfolios.
     */
    private function sumField(Collection $rows, string $field): string
    {
        return $rows->reduce(
            fn ($carry, $row) => bcadd($carry, (string) $row->{$field}, 2),
            '0.00',
        );
    }
}
