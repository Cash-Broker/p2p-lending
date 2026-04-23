<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\LoanEvent;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Executes the financial side of a buyback — admin-triggered only.
 *
 * F2 human-in-the-loop model: this service is NEVER called from the
 * detection cron. It is called:
 *   - From the Filament "Buyback Queue" page's Execute row action
 *     (primary path for admin).
 *   - From a `loans:execute-buyback` artisan command for ops / support
 *     (optional; typically the Queue action is sufficient).
 *
 * Nothing automatic — admin verifies externally that the originator has
 * actually paid (money received off-platform into the platform's bank
 * account), THEN clicks Execute. Per Q1 resolution, there is no platform-
 * held originator balance to check — the admin IS the balance-check.
 *
 * -------------------------------------------------------------------------
 * EXECUTION FLOW (all inside ONE DB::transaction on the loan row):
 *
 *   1. Acquire row lock: Loan::where(id)->lockForUpdate()->firstOrFail()
 *      Prevents concurrent executes on the same loan from either admin UI
 *      or a parallel artisan command.
 *
 *   2. IDEMPOTENCY check: if loan.status === 'bought_back', throw
 *      BuybackAlreadyExecutedException. Caller surfaces a friendly "already
 *      processed" message. No money moves.
 *
 *   3. STATE-MACHINE validation: only `late` or `default` can buy back
 *      (per DECISIONS.md default → bought_back allowance). Other statuses
 *      → InvalidArgumentException (defense beyond the model's booted()
 *      hook — surface the error earlier with a clearer message).
 *
 *   4. DISMISSED check: if buyback_dismissed_at IS NOT NULL, reject
 *      with a "reactivate first" message. Admin consented to skipping —
 *      un-dismissing is their explicit way to reverse that decision.
 *
 *   5. CALCULATE FRESH (per Q3): the loan's schedule may have changed
 *      since cron detection. Pull current numbers via
 *      BuybackCalculationService::calculateTotal() — NEVER from a cached
 *      detection-time value. This is the admin's last window to notice
 *      a discrepancy before money moves.
 *
 *   6. DISTRIBUTE pro-rata: last-investor-remainder so sum == total.
 *      BuybackCalculationService::distribute().
 *
 *   7. CREDIT investors: per-investor WalletService::buybackPrincipal
 *      (invested → available, writes TYPE_BUYBACK_PRINCIPAL transaction)
 *      + buybackInterest (available += + earned +=, writes TYPE_BUYBACK_INTEREST).
 *      Both transactions share reference "loan:{id}:buyback:user:{user_id}"
 *      so reconciliation can group them.
 *
 *   8. STAMP bought_back_at + TRANSITION status to 'bought_back'. We
 *      forceFill bought_back_at FIRST (sets dirty), then call
 *      $loan->transitionTo() which saves — both fields persist in ONE
 *      UPDATE. Audit trail gets ONE row (not two).
 *
 *   9. WRITE loan_event(buyback_completed) with BuybackResult::toLoan-
 *      EventMetadata() — aggregates only (no per-investor distributions
 *      leak into the timeline API).
 *
 * AFTER commit:
 *   Service returns BuybackResult. Caller (Filament / CLI) iterates
 *   $result->distributions and dispatches LoanBoughtBackNotification per
 *   investor (added in Step 6). This mirrors F1's ProcessLateLoans command
 *   dispatching LoanWentLateNotification — pure services, notification
 *   dispatch at the orchestration layer.
 *
 * -------------------------------------------------------------------------
 * PARTIAL FAILURE HANDLING
 *
 * Any throw inside the transaction → DB::transaction rolls back EVERYTHING:
 *   - Wallet bucket changes (invested/available/earned) revert.
 *   - Transaction ledger rows vanish (never committed).
 *   - Loan.bought_back_at revert.
 *   - Loan.status revert to late/default.
 *   - LoanEvent NOT written.
 *   - DB-level immutability triggers on transactions/loan_events do not fire
 *     on uncommitted rows — rollback is clean.
 *
 * Result: atomic. Either the loan is fully bought back OR nothing happened.
 * No halfway state possible. This is the core F1 pattern carried forward.
 *
 * Post-commit notification failure (network, SMTP) is logged by the CALLER,
 * NOT the service — consistent with RepaymentService's "money first, emails
 * second" policy. Admin can re-trigger only the notification (via a future
 * support tool) if needed.
 *
 * -------------------------------------------------------------------------
 * RETURN VALUE
 *
 * BuybackResult (value object — see its class docblock for structure).
 * Contains aggregates for UI display AND per-investor distributions for
 * caller-side notification dispatch.
 */
class BuybackExecutionService
{
    public function __construct(
        private WalletService $walletService,
        private BuybackCalculationService $calculator,
    ) {}

    /**
     * Execute a buyback for one loan.
     *
     * @param  int  $loanId    The loan to buy back. Must be in 'late' or 'default'.
     * @param  int  $adminId   User ID of the admin who clicked Execute. Stored
     *                         in loan_event.metadata.executed_by_admin_id AND
     *                         loan_event.triggered_by_user_id (triggered_by='admin').
     *
     * @throws BuybackAlreadyExecutedException  Loan already bought back (idempotency hit).
     * @throws InvalidArgumentException         Loan in wrong status, dismissed, zero total, no investors.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  Loan id not found.
     */
    public function execute(int $loanId, int $adminId): BuybackResult
    {
        return DB::transaction(function () use ($loanId, $adminId) {
            /** @var Loan $loan */
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // 2. IDEMPOTENCY
            if ($loan->status === Loan::STATUS_BOUGHT_BACK) {
                throw new BuybackAlreadyExecutedException(
                    loanId: $loanId,
                    message: "Loan #{$loanId} is already bought back"
                        . ($loan->bought_back_at ? " (at {$loan->bought_back_at->toIso8601String()})" : '')
                        . '.',
                );
            }

            // 3. STATE-MACHINE
            if (! in_array($loan->status, [Loan::STATUS_LATE, Loan::STATUS_DEFAULT], true)) {
                throw new InvalidArgumentException(
                    "Cannot execute buyback: loan #{$loanId} is in status='{$loan->status}'. "
                    . "Buyback is only allowed from 'late' or 'default'."
                );
            }

            // 4. DISMISSED
            if ($loan->buyback_dismissed_at !== null) {
                throw new InvalidArgumentException(
                    "Cannot execute buyback on loan #{$loanId}: dismissed at "
                    . "{$loan->buyback_dismissed_at->toIso8601String()}. "
                    . "Reactivate from the Queue first if this decision has been reversed."
                );
            }

            $fromStatus = $loan->status;

            // 5. CALCULATE FRESH
            $loan->loadMissing('originator');
            $calc = $this->calculator->calculateTotal($loan);

            if (bccomp($calc->total, '0', 2) <= 0) {
                throw new InvalidArgumentException(
                    "Cannot execute buyback on loan #{$loanId}: calculated total is {$calc->total} €. "
                    . "This typically means the loan has no unpaid schedule items "
                    . "(pending or late) — nothing to buy back."
                );
            }

            // 6. DISTRIBUTE
            $distributions = $this->calculator->distribute($loan, $calc);

            Log::info('BuybackExecutionService: distributing', [
                'loan_id'        => $loanId,
                'admin_id'       => $adminId,
                'from_status'    => $fromStatus,
                'coverage_type'  => $calc->coverageType,
                'total'          => $calc->total,
                'total_principal'=> $calc->principal,
                'total_interest' => $calc->interest,
                'investor_count' => count($distributions),
            ]);

            // 7. CREDIT INVESTORS — one pass, two wallet moves per investor
            //    (principal + interest). Skip zero shares (tiny investments
            //    that round to 0 at scale 2 after a low coverage split).
            foreach ($distributions as $d) {
                $reference = "loan:{$loanId}:buyback:user:{$d['user_id']}";

                if (bccomp($d['principal'], '0', 2) > 0) {
                    $this->walletService->buybackPrincipal(
                        userId: $d['user_id'],
                        amount: $d['principal'],
                        description: "Buyback principal for loan #{$loanId}",
                        reference: $reference,
                    );
                }

                if (bccomp($d['interest'], '0', 2) > 0) {
                    $this->walletService->buybackInterest(
                        userId: $d['user_id'],
                        amount: $d['interest'],
                        description: "Buyback interest for loan #{$loanId}",
                        reference: $reference,
                    );
                }
            }

            // 8. STAMP bought_back_at + TRANSITION. forceFill sets the field
            //    dirty; transitionTo's save persists both bought_back_at AND
            //    status in ONE UPDATE → one audit_log row via Auditable trait.
            $boughtBackAt = now();
            $loan->forceFill(['bought_back_at' => $boughtBackAt]);
            $loan->transitionTo(Loan::STATUS_BOUGHT_BACK);

            // Build the result object NOW so the loan_event and the return
            // value agree on a single `executed_at` snapshot.
            $result = new BuybackResult(
                loanId:            $loanId,
                executedByAdminId: $adminId,
                originatorId:      $loan->originator_id,
                fromStatus:        $fromStatus,
                coverageType:      $calc->coverageType,
                totalAmount:       $calc->total,
                totalPrincipal:    $calc->principal,
                totalInterest:     $calc->interest,
                investorCount:     count($distributions),
                executedAt:        $boughtBackAt,
                distributions:     $distributions,
            );

            // 9. LoanEvent. status_pair CHECK passes: late|default → bought_back
            //    are both non-null and different. triggered_by='admin' +
            //    triggered_by_user_id=adminId satisfy the consistency CHECK.
            LoanEvent::create([
                'loan_id'               => $loanId,
                'event_type'            => LoanEvent::TYPE_BUYBACK_COMPLETED,
                'from_status'           => $fromStatus,
                'to_status'             => Loan::STATUS_BOUGHT_BACK,
                'triggered_by'          => LoanEvent::TRIGGERED_BY_ADMIN,
                'triggered_by_user_id'  => $adminId,
                'metadata'              => $result->toLoanEventMetadata(),
                'occurred_at'           => $boughtBackAt,
            ]);

            Log::info('BuybackExecutionService: completed', [
                'loan_id'        => $loanId,
                'admin_id'       => $adminId,
                'from_status'    => $fromStatus,
                'total'          => $calc->total,
                'investor_count' => count($distributions),
            ]);

            return $result;
        });
    }
}
