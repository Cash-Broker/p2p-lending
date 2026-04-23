<?php

namespace App\Services\Loans;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Return value of EarlyRepaymentExecutionService::execute().
 *
 * The Filament action / CLI caller uses this to:
 *   (a) Render a success toast with aggregate stats (UI concern).
 *   (b) Dispatch per-investor EarlyRepaymentReceivedNotification (Step 4)
 *       using $distributions — the service intentionally DOES NOT
 *       dispatch investor notifications, matching F1 / F2 pattern
 *       (service = pure business logic; notification dispatch = caller
 *       concern, AFTER the DB::transaction has committed).
 *
 * `distributions` structure per entry:
 *   - user_id   : int
 *   - user      : App\Models\User  (eager loaded at distribution time)
 *   - principal : string (bcmath scale 2)
 *   - interest  : string (bcmath scale 2)
 *   - total     : string (bcmath scale 2)  = bcadd(principal, interest)
 *
 * Sum of per-investor `total` == `totalAmount` EXACTLY
 * (last-investor-remainder pattern guarantees no penny loss).
 *
 * NO `coverageType` field (early repayment has no per-originator policy).
 * `fromStatus` can be 'active', 'late', or 'default' — captured for the
 * LoanEvent.from_status column and forensic audit.
 */
final class EarlyRepaymentResult
{
    public function __construct(
        public readonly int $loanId,
        public readonly int $executedByAdminId,
        public readonly string $fromStatus,
        public readonly string $totalAmount,
        public readonly string $totalPrincipal,
        public readonly string $totalInterest,
        public readonly int $investorCount,
        public readonly CarbonInterface $executedAt,
        /** @var array<int, array{user_id:int, user:User, principal:string, interest:string, total:string}> */
        public readonly array $distributions,
    ) {}

    /**
     * Shape for loan_events.metadata. AGGREGATES ONLY — NO per-investor
     * distributions (privacy: other investors' shares must not leak
     * through the loan timeline API).
     *
     * Per-investor detail is in each user's own Transaction rows
     * (reference = "loan:{id}:early_repayment:user:{user_id}") and
     * their own EarlyRepaymentReceivedNotification.
     */
    public function toLoanEventMetadata(): array
    {
        return [
            'executed_by_admin_id' => $this->executedByAdminId,
            'from_status' => $this->fromStatus,
            'total_amount' => $this->totalAmount,
            'total_principal' => $this->totalPrincipal,
            'total_interest' => $this->totalInterest,
            'investor_count' => $this->investorCount,
            'executed_at' => $this->executedAt->toIso8601String(),
        ];
    }
}
