<?php

namespace App\Services\Loans;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Return value of BuybackExecutionService::execute().
 *
 * The Filament action / CLI caller uses this to:
 *   (a) Render a success toast with aggregate stats (UI concern).
 *   (b) Dispatch per-investor LoanBoughtBackNotification (Step 6) using
 *       $distributions — the service intentionally DOES NOT dispatch
 *       investor notifications, matching F1's ProcessLateLoans command
 *       pattern (detection/execution service = pure business logic;
 *       notification dispatch = caller concern).
 *
 * `distributions` structure per entry:
 *   - user_id   : int
 *   - user      : App\Models\User  (eager loaded at distribution time)
 *   - principal : string (bcmath scale 2)
 *   - interest  : string (bcmath scale 2)
 *   - total     : string (bcmath scale 2)  = bcadd(principal, interest)
 *
 * Sum of per-investor `total` == BuybackResult::totalAmount exactly
 * (last-investor-remainder pattern guarantees no penny loss).
 */
final class BuybackResult
{
    public function __construct(
        public readonly int $loanId,
        public readonly int $executedByAdminId,
        public readonly int $originatorId,
        public readonly string $fromStatus,
        public readonly string $coverageType,
        public readonly string $totalAmount,
        public readonly string $totalPrincipal,
        public readonly string $totalInterest,
        public readonly int $investorCount,
        public readonly CarbonInterface $executedAt,
        /** @var array<int, array{user_id:int, user:User, principal:string, interest:string, total:string}> */
        public readonly array $distributions,
    ) {}

    /**
     * Shape for loan_events.metadata. Aggregates only — NO per-investor
     * distributions (privacy: other investors' shares shouldn't leak
     * through the loan timeline API).
     *
     * Per-investor detail is in their own Transaction rows + their own
     * notification.
     */
    public function toLoanEventMetadata(): array
    {
        return [
            'executed_by_admin_id' => $this->executedByAdminId,
            'coverage_type' => $this->coverageType,
            'total_amount' => $this->totalAmount,
            'total_principal' => $this->totalPrincipal,
            'total_interest' => $this->totalInterest,
            'investor_count' => $this->investorCount,
            'originator_id' => $this->originatorId,
            'executed_at' => $this->executedAt->toIso8601String(),
        ];
    }
}
