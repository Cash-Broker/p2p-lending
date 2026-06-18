<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\LoanEvent;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Executes the financial side of an early repayment — admin-triggered only.
 *
 * Parallel to F2's BuybackExecutionService. The real-world flow is:
 *   1. Borrower contacts admin externally asking to close the loan early.
 *   2. Borrower wires funds to admin's business bank account.
 *   3. Admin opens Filament, navigates to the loan.
 *   4. Admin clicks "Изпълни предсрочно погасяване" row action.
 *   5. Modal shows FRESH calculated total (principal + unpaid interest
 *      through the current period boundary).
 *   6. Admin confirms → this service runs.
 *
 * Nothing automatic. No cron. No borrower-facing flow. Admin is the
 * single entry point.
 *
 * -------------------------------------------------------------------------
 * EXECUTION FLOW (all inside ONE DB::transaction with lockForUpdate):
 *
 *   1. Acquire row lock: Loan::where(id)->lockForUpdate()->firstOrFail()
 *
 *   2. IDEMPOTENCY + STATE validation with DIFFERENTIATED messages:
 *      - status=repaid AND early_repaid_at IS NOT NULL
 *        → EarlyRepaymentAlreadyExecutedException
 *          ("already early-repaid on {date}")
 *      - status=repaid AND early_repaid_at IS NULL
 *        → InvalidArgumentException
 *          ("already repaid through scheduled completion; cannot early-
 *           repay a finalised loan")
 *      - status=bought_back
 *        → InvalidArgumentException
 *          ("originator owns the debt — early repayment N/A")
 *      - status NOT IN (active, late, default)
 *        → InvalidArgumentException
 *          ("not yet activated — only active/late/default can close early")
 *
 *   3. CALCULATE FRESH (never cached): `EarlyRepaymentCalculationService
 *      ::calculateTotal()` computes outstanding_principal + unpaid
 *      interest through the current-period boundary. Throws its own
 *      InvalidArgumentException if zero (no unpaid schedules).
 *
 *   4. DISTRIBUTE pro-rata with last-investor-remainder.
 *
 *   5. CREDIT investors: per-investor WalletService::earlyRepayPrincipal
 *      (invested → available, writes TYPE_EARLY_REPAYMENT_PRINCIPAL
 *      transaction) + ::earlyRepayInterest (available += + earned +=,
 *      writes TYPE_EARLY_REPAYMENT_INTEREST). Both share reference
 *      "loan:{id}:early_repayment:user:{user_id}" for reconciliation.
 *
 *   6. STAMP early_repaid_at + early_repayment_amount + TRANSITION to
 *      'repaid' in ONE UPDATE (forceFill sets the fields dirty; transitionTo
 *      saves everything in a single Eloquent UPDATE — same pattern as F2's
 *      bought_back_at + status batch, empirically verified).
 *
 *   7. WRITE loan_event(early_repayment_completed) with
 *      EarlyRepaymentResult::toLoanEventMetadata() — aggregates only,
 *      NO per-investor leak into the timeline API.
 *
 * AFTER commit:
 *   Service returns EarlyRepaymentResult. Caller (Filament action /
 *   CLI) iterates $result->distributions and dispatches
 *   EarlyRepaymentReceivedNotification per investor (added in Step 4).
 *   Money first, emails second — F1/F2 pattern.
 *
 * -------------------------------------------------------------------------
 * PARTIAL FAILURE HANDLING
 *
 * Any throw inside the transaction → DB::transaction rolls back
 * EVERYTHING: wallet bucket changes, Transaction rows, early_repaid_at /
 * early_repayment_amount / status, LoanEvent. Atomic — either fully
 * early-repaid OR nothing happened.
 *
 * Post-commit notification failure is the caller's concern (log & keep).
 */
class EarlyRepaymentExecutionService
{
    public function __construct(
        private WalletService $walletService,
        private EarlyRepaymentCalculationService $calculator,
        private \App\Services\TelegramService $telegram,
    ) {}

    /**
     * Execute an early repayment for one loan.
     *
     * @param  int  $loanId    The loan to early-repay. Must be in
     *                         'active', 'late', or 'default'.
     * @param  int  $adminId   User ID of the admin who clicked Execute.
     *                         Stored in loan_event.metadata.executed_by_admin_id
     *                         AND loan_event.triggered_by_user_id.
     *
     * @throws EarlyRepaymentAlreadyExecutedException  Loan already early-repaid.
     * @throws InvalidArgumentException                Wrong status OR zero total.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  Loan id not found.
     */
    public function execute(int $loanId, int $adminId): EarlyRepaymentResult
    {
        $result = DB::transaction(function () use ($loanId, $adminId) {
            /** @var Loan $loan */
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // 3-offer guard: offer-based loans pay investors via per-investment
            // schedules, not the single per-loan amortization schedule this
            // service distributes from. Block here (all call paths) until a
            // structure-aware early repayment lands — explicit follow-up.
            if ($loan->usesOffers()) {
                throw new InvalidArgumentException(
                    "Cannot early-repay loan #{$loanId}: it uses per-offer investor payouts. "
                    . 'Offer-based early repayment is not yet supported.'
                );
            }

            // 2. IDEMPOTENCY + STATE with differentiated messages
            if ($loan->status === Loan::STATUS_REPAID) {
                if ($loan->early_repaid_at !== null) {
                    throw new EarlyRepaymentAlreadyExecutedException(
                        loanId: $loanId,
                        message: "Loan #{$loanId} is already early-repaid on "
                            . "{$loan->early_repaid_at->toIso8601String()}.",
                    );
                }
                throw new InvalidArgumentException(
                    "Loan #{$loanId} is already repaid through scheduled completion; "
                    . "cannot early-repay a finalised loan."
                );
            }

            if ($loan->status === Loan::STATUS_BOUGHT_BACK) {
                throw new InvalidArgumentException(
                    "Cannot early-repay loan #{$loanId}: loan is in status='bought_back' — "
                    . "originator owns the debt, early repayment is not applicable."
                );
            }

            if (! in_array(
                $loan->status,
                [Loan::STATUS_ACTIVE, Loan::STATUS_LATE, Loan::STATUS_DEFAULT],
                true,
            )) {
                throw new InvalidArgumentException(
                    "Cannot early-repay loan #{$loanId}: current status='{$loan->status}' — "
                    . "only active/late/default loans support early repayment."
                );
            }

            $fromStatus = $loan->status;

            // 3. CALCULATE FRESH
            $calc = $this->calculator->calculateTotal($loan);

            if (bccomp($calc->total, '0', 2) <= 0) {
                // Defensive guard — calculator throws on empty unpaid,
                // but guard here in case of a zero-sum edge case.
                throw new InvalidArgumentException(
                    "Cannot early-repay loan #{$loanId}: calculated total is {$calc->total} €."
                );
            }

            // 4. DISTRIBUTE
            $distributions = $this->calculator->distribute($loan, $calc);

            Log::info('EarlyRepaymentExecutionService: distributing', [
                'loan_id'        => $loanId,
                'admin_id'       => $adminId,
                'from_status'    => $fromStatus,
                'total'          => $calc->total,
                'total_principal'=> $calc->principal,
                'total_interest' => $calc->interest,
                'investor_count' => count($distributions),
            ]);

            // 5. CREDIT INVESTORS
            foreach ($distributions as $d) {
                $reference = "loan:{$loanId}:early_repayment:user:{$d['user_id']}";

                if (bccomp($d['principal'], '0', 2) > 0) {
                    $this->walletService->earlyRepayPrincipal(
                        userId: $d['user_id'],
                        amount: $d['principal'],
                        description: "Early repayment principal for loan #{$loanId}",
                        reference: $reference,
                    );
                }

                if (bccomp($d['interest'], '0', 2) > 0) {
                    $this->walletService->earlyRepayInterest(
                        userId: $d['user_id'],
                        amount: $d['interest'],
                        description: "Early repayment interest for loan #{$loanId}",
                        reference: $reference,
                    );
                }
            }

            // 6. STAMP early_repaid_at + early_repayment_amount + TRANSITION
            //    in ONE UPDATE. forceFill sets the fields dirty; transitionTo()'s
            //    save persists all dirty fields (including early_repaid_at +
            //    early_repayment_amount + status) in a single UPDATE. One
            //    audit_logs row via Auditable trait.
            $executedAt = now();
            $loan->forceFill([
                'early_repaid_at'        => $executedAt,
                'early_repayment_amount' => $calc->total,
            ]);
            $loan->transitionTo(Loan::STATUS_REPAID);

            // Build result object — used for loan_event metadata AND returned
            // to the caller for per-investor notification dispatch.
            $result = new EarlyRepaymentResult(
                loanId:            $loanId,
                executedByAdminId: $adminId,
                fromStatus:        $fromStatus,
                totalAmount:       $calc->total,
                totalPrincipal:    $calc->principal,
                totalInterest:     $calc->interest,
                investorCount:     count($distributions),
                executedAt:        $executedAt,
                distributions:     $distributions,
            );

            // 7. LoanEvent. status_pair CHECK passes: active|late|default →
            //    repaid are all non-null + different. triggered_by='admin' +
            //    triggered_by_user_id=adminId satisfy the consistency CHECK.
            LoanEvent::create([
                'loan_id'              => $loanId,
                'event_type'           => LoanEvent::TYPE_EARLY_REPAYMENT_COMPLETED,
                'from_status'          => $fromStatus,
                'to_status'            => Loan::STATUS_REPAID,
                'triggered_by'         => LoanEvent::TRIGGERED_BY_ADMIN,
                'triggered_by_user_id' => $adminId,
                'metadata'             => $result->toLoanEventMetadata(),
                'occurred_at'          => $executedAt,
            ]);

            Log::info('EarlyRepaymentExecutionService: completed', [
                'loan_id'        => $loanId,
                'admin_id'       => $adminId,
                'from_status'    => $fromStatus,
                'total'          => $calc->total,
                'investor_count' => count($distributions),
            ]);

            return $result;
        });

        // Telegram alert (HIGH tier) — fired AFTER transaction commits.
        try {
            $this->telegram->high(
                'Ранно погасяване изпълнено',
                "Кредит #{$result->loanId} погасен предсрочно.\n"
                ."Сума: {$result->totalAmount} € (главница {$result->totalPrincipal} + лихва {$result->totalInterest}).",
                [
                    'loan_id'     => $result->loanId,
                    'investors'   => $result->investorCount,
                    'from_status' => $result->fromStatus,
                    'admin_id'    => $result->executedByAdminId,
                ],
            );
        } catch (\Throwable $ignored) {
            // Logged inside TelegramService.
        }

        return $result;
    }
}
