<?php

namespace App\Services\Loans;

use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        private TelegramService $telegram,
    ) {}

    /**
     * Execute a buyback for one loan.
     *
     * @param  int  $loanId  The loan to buy back. Must be in 'late' or 'default'.
     * @param  int  $adminId  User ID of the admin who clicked Execute. Stored
     *                        in loan_event.metadata.executed_by_admin_id AND
     *                        loan_event.triggered_by_user_id (triggered_by='admin').
     *
     * @throws BuybackAlreadyExecutedException Loan already bought back (idempotency hit).
     * @throws InvalidArgumentException Loan in wrong status, dismissed, zero total, no investors.
     * @throws ModelNotFoundException Loan id not found.
     */
    public function execute(int $loanId, int $adminId): BuybackResult
    {
        $result = DB::transaction(function () use ($loanId, $adminId) {
            /** @var Loan $loan */
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // 2. IDEMPOTENCY
            if ($loan->status === Loan::STATUS_BOUGHT_BACK) {
                throw new BuybackAlreadyExecutedException(
                    loanId: $loanId,
                    message: "Loan #{$loanId} is already bought back"
                        .($loan->bought_back_at ? " (at {$loan->bought_back_at->toIso8601String()})" : '')
                        .'.',
                );
            }

            // 3. STATE-MACHINE
            if (! in_array($loan->status, [Loan::STATUS_LATE, Loan::STATUS_DEFAULT], true)) {
                throw new InvalidArgumentException(
                    "Cannot execute buyback: loan #{$loanId} is in status='{$loan->status}'. "
                    ."Buyback is only allowed from 'late' or 'default'."
                );
            }

            // 4. DISMISSED
            if ($loan->buyback_dismissed_at !== null) {
                throw new InvalidArgumentException(
                    "Cannot execute buyback on loan #{$loanId}: dismissed at "
                    ."{$loan->buyback_dismissed_at->toIso8601String()}. "
                    .'Reactivate from the Queue first if this decision has been reversed.'
                );
            }

            $fromStatus = $loan->status;

            // 5. CALCULATE FRESH
            $loan->loadMissing('originator');
            $calc = $this->calculator->calculateTotal($loan);

            if (bccomp($calc->total, '0', 2) <= 0) {
                throw new InvalidArgumentException(
                    "Cannot execute buyback on loan #{$loanId}: calculated total is {$calc->total} €. "
                    .'This typically means the loan has no unpaid schedule items '
                    .'(pending or late) — nothing to buy back.'
                );
            }

            // 6+7. DISTRIBUTE + CREDIT. Offer-based loans pay each investor from
            // their OWN per-investment schedule (and net the accrued bucket so a
            // capitalized investor isn't paid twice); legacy loans split the
            // total pro-rata. Either way only the UNPAID remainder is credited,
            // so a loan already partly auto-paid is never double-paid.
            $accruedReversed = '0.00';
            if ($loan->usesOffers()) {
                [$distributions, $accruedReversed] = $this->creditOfferBuyback($loan, $calc->coverageType);
            } else {
                $distributions = $this->calculator->distribute($loan, $calc);

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
            }

            // The audit record (loan_event, Telegram, result object) reports what
            // was ACTUALLY credited, not the calc estimate: the legacy branch
            // returns each investor's exact ledger outstanding, which can diverge
            // from the schedule-summed calc->principal (e.g. rows in 'default'
            // status hold capital outside buyback scope while the ledger still
            // tracks it as outstanding).
            $actualPrincipal = '0.00';
            $actualInterest = '0.00';
            foreach ($distributions as $d) {
                $actualPrincipal = bcadd($actualPrincipal, $d['principal'], 2);
                $actualInterest = bcadd($actualInterest, $d['interest'], 2);
            }
            $actualTotal = bcadd($actualPrincipal, $actualInterest, 2);

            // Close out the borrower-side plan: rows left pending/late on a
            // terminal loan would keep feeding the nightly days_late snapshot
            // refresh forever (zombie counters on a closed loan). Rows in
            // 'default' status stay untouched — they are explicitly out of
            // buyback scope and remain the admin's manual concern.
            $unpaidBorrowerRows = $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'late'])
                ->lockForUpdate()
                ->get();
            foreach ($unpaidBorrowerRows as $row) {
                $row->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            }

            Log::info('BuybackExecutionService: distributed', [
                'loan_id' => $loanId,
                'admin_id' => $adminId,
                'from_status' => $fromStatus,
                'coverage_type' => $calc->coverageType,
                'calc_total' => $calc->total,
                'credited_total' => $actualTotal,
                'accrued_reversed' => $accruedReversed,
                'investor_count' => count($distributions),
                'uses_offers' => $loan->usesOffers(),
            ]);

            // 8. STAMP bought_back_at + TRANSITION. forceFill sets the field
            //    dirty; transitionTo's save persists both bought_back_at AND
            //    status in ONE UPDATE → one audit_log row via Auditable trait.
            $boughtBackAt = now();
            $loan->forceFill(['bought_back_at' => $boughtBackAt]);
            $loan->transitionTo(Loan::STATUS_BOUGHT_BACK);

            // Build the result object NOW so the loan_event and the return
            // value agree on a single `executed_at` snapshot.
            $result = new BuybackResult(
                loanId: $loanId,
                executedByAdminId: $adminId,
                originatorId: $loan->originator_id,
                fromStatus: $fromStatus,
                coverageType: $calc->coverageType,
                totalAmount: $actualTotal,
                totalPrincipal: $actualPrincipal,
                totalInterest: $actualInterest,
                investorCount: count($distributions),
                executedAt: $boughtBackAt,
                distributions: $distributions,
                totalAccruedReversed: $accruedReversed,
            );

            // 9. LoanEvent. status_pair CHECK passes: late|default → bought_back
            //    are both non-null and different. triggered_by='admin' +
            //    triggered_by_user_id=adminId satisfy the consistency CHECK.
            LoanEvent::create([
                'loan_id' => $loanId,
                'event_type' => LoanEvent::TYPE_BUYBACK_COMPLETED,
                'from_status' => $fromStatus,
                'to_status' => Loan::STATUS_BOUGHT_BACK,
                'triggered_by' => LoanEvent::TRIGGERED_BY_ADMIN,
                'triggered_by_user_id' => $adminId,
                'metadata' => $result->toLoanEventMetadata(),
                'occurred_at' => $boughtBackAt,
            ]);

            Log::info('BuybackExecutionService: completed', [
                'loan_id' => $loanId,
                'admin_id' => $adminId,
                'from_status' => $fromStatus,
                'total' => $actualTotal,
                'investor_count' => count($distributions),
            ]);

            return $result;
        });

        // Telegram alert (HIGH tier) — fired AFTER transaction commits, so
        // we don't notify on rollback. Best-effort: failures here don't
        // affect the buyback result returned to the caller.
        try {
            $this->telegram->high(
                'Buyback изпълнен',
                "Кредит #{$result->loanId} изкупен обратно от оригинатора.\n"
                ."Сума: {$result->totalAmount} € (главница {$result->totalPrincipal} + лихва {$result->totalInterest}).",
                [
                    'loan_id' => $result->loanId,
                    'investors' => $result->investorCount,
                    'coverage' => $result->coverageType,
                    'from_status' => $result->fromStatus,
                    'admin_id' => $result->executedByAdminId,
                ],
            );
        } catch (\Throwable $ignored) {
            // Logged inside TelegramService.
        }

        return $result;
    }

    /**
     * Offer-based buyback crediting. Each investor is made whole from THEIR OWN
     * unpaid investment-schedule rows: outstanding principal back, plus the
     * covered scheduled interest. The capitalized `accrued` bucket is netted —
     * released first, then topped up — so the covered interest is received
     * exactly ONCE (boss req: never pay twice). Unpaid rows are marked paid so
     * the auto-payout cron can never re-pay them.
     *
     * Under principal_only coverage NO interest is covered: the locked accrued
     * bucket is REVERSED (written off), never released — the originator did
     * not fund it, so paying it out would hand the investor un-backed money.
     *
     * @return array{0: array<int, array{user_id:int, user:User, principal:string, interest:string, total:string}>, 1: string}
     *         [distributions, total accrued interest reversed]
     */
    private function creditOfferBuyback(Loan $loan, string $coverage): array
    {
        $investments = $loan->investments()
            ->whereNotNull('loan_offer_id')
            ->with('user')
            ->orderBy('id')
            ->get();

        $result = [];
        $totalAccruedReversed = '0.00';

        foreach ($investments as $investment) {
            $unpaid = InvestmentSchedule::where('investment_id', $investment->id)
                ->whereIn('status', ['pending', 'late'])
                ->lockForUpdate()
                ->get();

            if ($unpaid->isEmpty()) {
                continue; // already fully paid out — nothing left to buy back
            }

            $outstandingPrincipal = $unpaid->reduce(fn ($c, $r) => bcadd($c, (string) $r->principal, 2), '0.00');
            $scheduledInterest = $coverage === BuybackCalculationService::COVERAGE_PRINCIPAL_PLUS_INTEREST
                ? $unpaid->reduce(fn ($c, $r) => bcadd($c, (string) $r->interest, 2), '0.00')
                : '0.00';

            $reference = "loan:{$loan->id}:investment:{$investment->id}:buyback";

            // Outstanding capital back (invested → available) — exact, no underflow.
            if (bccomp($outstandingPrincipal, '0', 2) > 0) {
                $this->walletService->buybackPrincipal(
                    userId: $investment->user_id,
                    amount: $outstandingPrincipal,
                    description: "Buyback principal for loan #{$loan->id}",
                    reference: $reference,
                );
            }

            // Interest: release any LOCKED accrual first (it counts toward the
            // covered interest), then top up the remainder → net == covered
            // interest, paid exactly once. Under principal_only the accrual is
            // NOT covered — reverse it (accrued -= A, nothing paid out) so the
            // investor receives exactly what the originator funded: principal.
            $accrued = $this->accruedToDate($loan->id, $investment->id);
            $interestPaid = '0.00';

            if (bccomp($accrued, '0', 2) > 0) {
                if ($coverage === BuybackCalculationService::COVERAGE_PRINCIPAL_PLUS_INTEREST) {
                    $this->walletService->releaseAccrued(
                        userId: $investment->user_id,
                        amount: $accrued,
                        description: "Buyback: release accrued interest for loan #{$loan->id}",
                        reference: $reference,
                    );
                    $interestPaid = $accrued;
                } else {
                    $this->walletService->reverseAccrued(
                        userId: $investment->user_id,
                        amount: $accrued,
                        description: "Buyback (principal-only): reverse uncovered accrued interest for loan #{$loan->id}",
                        reference: $reference,
                    );
                    $totalAccruedReversed = bcadd($totalAccruedReversed, $accrued, 2);
                }
            }

            $remainder = bcsub($scheduledInterest, $accrued, 2);
            if (bccomp($remainder, '0', 2) > 0) {
                $this->walletService->buybackInterest(
                    userId: $investment->user_id,
                    amount: $remainder,
                    description: "Buyback interest for loan #{$loan->id}",
                    reference: $reference,
                );
                $interestPaid = bcadd($interestPaid, $remainder, 2);
            }

            foreach ($unpaid as $row) {
                $row->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            }

            $result[] = [
                'user_id' => $investment->user_id,
                'user' => $investment->user,
                'principal' => $outstandingPrincipal,
                'interest' => $interestPaid,
                'total' => bcadd($outstandingPrincipal, $interestPaid, 2),
            ];
        }

        return [$result, $totalAccruedReversed];
    }

    /** Net interest accrued for one investment (Σ accrued − Σ released − Σ reversed), from the immutable ledger. */
    private function accruedToDate(int $loanId, int $investmentId): string
    {
        $rows = Transaction::query()
            ->where('reference', 'like', "loan:{$loanId}:investment:{$investmentId}:%")
            ->whereIn('type', [
                Transaction::TYPE_INTEREST_ACCRUED,
                Transaction::TYPE_INTEREST_RELEASED,
                Transaction::TYPE_INTEREST_ACCRUAL_REVERSED,
            ])
            ->get(['type', 'amount']);

        $net = '0.00';
        foreach ($rows as $row) {
            $net = $row->type === Transaction::TYPE_INTEREST_ACCRUED
                ? bcadd($net, (string) $row->amount, 2)
                : bcsub($net, (string) $row->amount, 2);
        }

        return $net;
    }
}
