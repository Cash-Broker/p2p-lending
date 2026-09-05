<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\LoanOffer;
use App\Models\User;
use App\Services\Loans\BorrowerPlanService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentService
{
    public function __construct(
        private WalletService $walletService,
        private InvestmentContractService $contractService,
        private InvestmentScheduleGenerator $scheduleGenerator,
        private PromotionService $promotionService,
    ) {}

    /**
     * Process an investment in a loan.
     *
     * The flow:
     * 1. Check idempotency — if this key was already used, return existing investment
     * 2. Lock loan row (prevents overfunding from concurrent investments)
     * 3. Validate business rules (loan status, amounts)
     * 4. Create Investment record
     * 5. Debit wallet via WalletService (available -= amount, invested += amount)
     * 6. Credit loan: funded_amount += amount
     * 7. If loan fully funded → transition to 'funded' (admin activates manually)
     */
    public function invest(User $user, Loan $loan, string $amount, ?string $idempotencyKey = null, ?int $loanOfferId = null, ?string $expectedInterestRate = null): Investment
    {
        try {
            return DB::transaction(function () use ($user, $loan, $amount, $idempotencyKey, $loanOfferId, $expectedInterestRate) {
                // Idempotency check INSIDE transaction — prevents race condition
                // where two concurrent requests both pass the check before either creates
                if ($idempotencyKey) {
                    $existing = Investment::where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        $this->assertReplayBelongsTo($existing, $user);

                        return $existing;
                    }
                }

                // Lock loan — prevents overfunding from concurrent investments
                $loan = Loan::where('id', $loan->id)->lockForUpdate()->firstOrFail();

                // Business rule validations (AFTER lock to prevent TOCTOU)
                $this->validateInvestment($user, $loan, $amount);

                // Resolve the chosen offer (when present) AFTER the lock so a
                // mid-flight disable is caught. Its rate + payout type are
                // snapshotted onto the investment — the source of truth for this
                // investor's cash flow, immune to later offer edits.
                // loanOfferId === null is the legacy path (no offer chosen).
                $offer = $loanOfferId !== null ? $this->resolveOffer($loan, $loanOfferId) : null;

                // Quote-vs-commit protection. If the caller passed the rate it
                // quoted to the investor, reject when the LIVE offer rate has
                // drifted since (the boss edited it mid-funding). The investor
                // is shown current terms to re-confirm rather than being
                // silently committed — and paid — at a different rate than the
                // projection they just saw. Opt-in: a null expectedInterestRate
                // keeps the prior behavior for legacy callers.
                if ($expectedInterestRate !== null && $offer !== null
                    && bccomp((string) $offer->interest_rate, $expectedInterestRate, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'expected_interest_rate' => [
                            "Условията се промениха: лихвата по офертата вече е {$offer->interest_rate}% "
                            ."(показана беше {$expectedInterestRate}%). Моля потвърдете отново.",
                        ],
                    ]);
                }

                // Create investment record
                $investment = Investment::create([
                    'user_id' => $user->id,
                    'loan_id' => $loan->id,
                    'loan_offer_id' => $offer?->id,
                    'amount' => $amount,
                    'interest_rate' => $offer?->interest_rate,
                    'payout_type' => $offer?->payout_type,
                    'invested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Debit wallet via WalletService — the single source of truth
                $this->walletService->invest(
                    $user->id,
                    $amount,
                    "Investment in loan #{$loan->id}",
                    "investment:{$investment->id}"
                );

                // Client decision 2026-08-13 (Reni): «след като клиент
                // инвестира, олихвяването си тръгва веднага за него» — the
                // payout schedule exists from the INVEST moment (anchored to
                // now == invested_at), regardless of whether the loan ever
                // reaches 100% funding. Atomic with the money move; the
                // activation-time generator then skips it (already exists).
                if ($offer !== null) {
                    $this->scheduleGenerator->generateForInvestment($investment, $loan);

                    // Flash promo (Reni 2026-08-14): invest inside a running
                    // promo window → the upfront bonus lands atomically with
                    // the investment. No promo / no budget → silent no-op.
                    $this->promotionService->grantInvestBonus($investment, $loan, $user);
                }

                // Credit loan funded amount
                $newFundedAmount = bcadd($loan->funded_amount, $amount, 2);
                $loan->forceFill(['funded_amount' => $newFundedAmount])->save();

                // Auto-transition loan status. The state machine only permits
                // `published → funding → funded`, never `published → funded` in
                // one step. When a single investment fully funds a previously
                // published loan, we MUST route through `funding` — otherwise
                // transitionTo() throws InvalidArgumentException and the whole
                // investment rolls back.
                //
                // Ordering: PUBLISHED → FUNDING first (always), then FUNDING →
                // FUNDED iff fully funded. Partial-funding remains on FUNDING.
                if ($loan->status === Loan::STATUS_PUBLISHED) {
                    $loan->transitionTo(Loan::STATUS_FUNDING);
                }
                if ($loan->isFullyFunded() && $loan->status === Loan::STATUS_FUNDING) {
                    $loan->transitionTo(Loan::STATUS_FUNDED);

                    // Client decision 2026-08-13 (Reni, explicit, after the
                    // no-way-back consequence was put to her): a fully funded
                    // loan starts REPAYING IMMEDIATELY, counted from the
                    // funding date. The «Активирай» button is gone — the last
                    // euro is the activation.
                    //
                    // This runs inside the investing transaction on purpose:
                    // a funded loan without its payout schedules must never
                    // exist, so schedule generation commits with the money or
                    // not at all. transitionTo() generates them (funded →
                    // active), anchored to now() — which IS the funding
                    // moment, exactly what she asked for.
                    $loan->transitionTo(Loan::STATUS_ACTIVE);

                    // System-driven transition ⇒ it belongs in the append-only
                    // loan_events trail (manual admin transitions rely on
                    // audit_logs instead). Nobody pressed a button here, so
                    // without this the activation would have no owner.
                    LoanEvent::create([
                        'loan_id' => $loan->id,
                        'event_type' => LoanEvent::TYPE_STATUS_CHANGED,
                        'from_status' => Loan::STATUS_FUNDED,
                        'to_status' => Loan::STATUS_ACTIVE,
                        'triggered_by' => LoanEvent::TRIGGERED_BY_SYSTEM,
                        'triggered_by_user_id' => null,
                        'metadata' => [
                            'reason' => 'auto_activated_on_funding',
                            'funded_amount' => (string) $loan->funded_amount,
                            'closing_investment_id' => $investment->id,
                        ],
                        'occurred_at' => now(),
                    ]);

                    // PAY-13 (owner 2026-09-03): the borrower tracking plan is a
                    // late-detection INPUT, not money — it is created only after
                    // the investing transaction has committed and can never roll
                    // it back (same shape as the promo bonus in PromotionService).
                    // The service swallows and reports its own failures.
                    $activatedLoanId = (int) $loan->id;
                    DB::afterCommit(fn () => app(BorrowerPlanService::class)->generateAtActivation($activatedLoanId));
                }

                // Conclude the loan agreement — frozen contract snapshot +
                // click-wrap acceptance evidence, atomic with the money move.
                // An offer-based investment without its contract must not
                // exist (the invest click IS the recorded consent). Legacy
                // no-offer investments (test/console only) have no contract.
                if ($offer !== null) {
                    $this->contractService->createForInvestment($investment, $user, $loan, $offer);
                }

                return $investment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent request with the same idempotency key — the loser
            // returns the winner's row. Only the idempotency index qualifies:
            // any OTHER unique violation inside the invest transaction is a
            // real error and must surface as one (audit 2026-09-01, PAY-05).
            if (! str_contains($e->getMessage(), 'investments_idempotency_key_unique')) {
                throw $e;
            }

            $existing = Investment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $e;
            }
            $this->assertReplayBelongsTo($existing, $user);

            return $existing;
        }
    }

    /**
     * A replayed key must belong to the SAME investor. The key is a client
     * UUID, unguessable in practice, but a leaked or shared one must not hand
     * another account's investment back as a 201 «Investment successful»
     * (audit 2026-09-01, PAY-33).
     */
    private function assertReplayBelongsTo(Investment $existing, User $user): void
    {
        if ((int) $existing->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used by another account.'],
            ]);
        }
    }

    private function validateInvestment(User $user, Loan $loan, string $amount): void
    {
        if (bccomp($amount, '50.00', 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Minimum investment is 50.00 €.'],
            ]);
        }

        if (! in_array($loan->status, Loan::FUNDABLE_STATUSES)) {
            throw ValidationException::withMessages([
                'loan' => ['This loan is not available for investment.'],
            ]);
        }

        // PAY-30 (owner 2026-09-03): a partially funded loan whose investors have
        // ALL been paid out (or closed) has completed its term; a newcomer must
        // not restart a 12-month cycle in the hours before the sweep marks it
        // repaid. Evaluated under the loan lock the caller holds.
        if ($loan->status === Loan::STATUS_FUNDING
            && $loan->investmentSchedules()->exists()
            && ! $loan->investmentSchedules()->whereIn('status', ['pending', 'late'])->exists()) {
            throw ValidationException::withMessages([
                'loan' => ['This loan has completed its term and no longer accepts investments.'],
            ]);
        }

        // Check available balance (WalletService will also check, but fail fast here)
        $wallet = $user->wallet;
        if (bccomp($wallet->available, $amount, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Insufficient available balance.'],
            ]);
        }

        // Check overfunding — investors fund up to the funding cap (the
        // outstanding investable principal), not the full loan amount.
        $remaining = bcsub($loan->fundingCap(), $loan->funded_amount, 2);
        if (bccomp($amount, $remaining, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => ["Maximum available for this loan is {$remaining} €."],
            ]);
        }
    }

    /**
     * Resolve + re-validate the chosen offer inside the locked transaction.
     * Belongs-to-loan and is_enabled are re-checked here (not just in
     * InvestRequest) so a concurrent disable between request validation and
     * commit can't slip an investment onto a withdrawn offer.
     */
    private function resolveOffer(Loan $loan, int $loanOfferId): LoanOffer
    {
        // Locking read on purpose (audit 2026-09-01, PAY-39): the transaction's
        // consistent snapshot was fixed by the idempotency SELECT above, BEFORE
        // the loan row lock was acquired — a plain read here would return the
        // offer as it was before we waited for the lock, so a rate edit or a
        // disable committed in that window would be snapshotted onto the
        // investment and the quote-vs-commit guard would compare against the
        // same stale value. A shared lock reads the committed row.
        $offer = LoanOffer::where('id', $loanOfferId)
            ->where('loan_id', $loan->id)
            ->sharedLock()
            ->first();

        if ($offer === null) {
            throw ValidationException::withMessages([
                'loan_offer_id' => ['Избраната оферта не е намерена за този кредит.'],
            ]);
        }

        if (! $offer->is_enabled) {
            throw ValidationException::withMessages([
                'loan_offer_id' => ['Тази оферта вече не е активна.'],
            ]);
        }

        return $offer;
    }
}
