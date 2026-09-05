<?php

namespace App\Services\Loans;

use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEarlyClosure;
use App\Models\LoanEvent;
use App\Services\TelegramService;
use App\Services\WalletService;
use App\Support\Loans\AccruedInterestLedger;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Executes an early closure of an OFFER-based loan — full or partial
 * (Reni 2026-08-18). Admin-triggered only; there is no cron.
 *
 * The borrower has paid principal back ahead of plan (verified off-platform by
 * the admin, exactly like buyback). This service does the platform's half:
 * returns that principal to the investors, pays the interest they earned on it
 * up to today, and SHRINKS what remains of their schedules so the platform
 * stops owing interest on money it no longer holds — «набутваме се с лихви
 * иначе».
 *
 * Everything happens inside one transaction on the loan row:
 *   1. lock + guards (offer-based, closable status, positive amount)
 *   2. price it fresh via EarlyClosureCalculationService (never a cached quote)
 *   3. per investor: principal back, interest to the day, schedule shrunk
 *   4. record the closure, transition the loan when nothing is left
 *
 * Shrinking, not marking paid: a cancelled installment was never received, and
 * treating it as received would (a) lie in the portfolio and (b) unlock
 * conditional bonuses, which Reni explicitly excluded for early closures.
 */
class EarlyClosureExecutionService
{
    private const SCALE = 10;

    /** Statuses a loan can be closed early from. */
    // `funding` joined with PAY-30 (owner 2026-09-03): a partially funded loan
    // has investor positions from day one and may be closed like any other.
    private const CLOSABLE_STATUSES = [Loan::STATUS_FUNDING, Loan::STATUS_ACTIVE, Loan::STATUS_LATE, Loan::STATUS_DEFAULT];

    public function __construct(
        private EarlyClosureCalculationService $calculator,
        private InvestorDistributionService $distribution,
        private WalletService $walletService,
        private TelegramService $telegram,
    ) {}

    /**
     * @param  string|null  $principalAmount  null = close the loan in full
     * @return array{closure: LoanEarlyClosure, quote: array<string, mixed>}
     */
    public function execute(
        int $loanId,
        int $adminId,
        ?string $principalAmount = null,
        ?CarbonInterface $asOf = null,
        ?string $note = null,
        ?string $requestToken = null,
    ): array {
        $asOf = ($asOf ?? now())->copy();

        // Interest is never priced into the future — a date past today would
        // pay investors for days nobody has lived yet. The admin form caps it
        // too, but the service is the authority: it is also reachable from
        // artisan/tinker.
        if ($asOf->greaterThan(now())) {
            $asOf = now()->copy();
        }

        // Canonical money ingress, same as every other amount that enters the
        // platform (CLAUDE.md): rejects non-numeric input and pins the scale
        // before a single bcmath call sees it.
        if ($principalAmount !== null) {
            $principalAmount = Money::normalizePositive($principalAmount);
        }

        $result = DB::transaction(function () use ($loanId, $adminId, $principalAmount, $asOf, $note, $requestToken) {
            /** @var Loan $loan */
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // One admin submit = one closure (audit 2026-09-01, PAY-04). The
            // form hands us a per-open token; a double click or a replayed
            // request carries the same one and is refused under the loan lock.
            if ($requestToken !== null && LoanEarlyClosure::where('request_token', $requestToken)->exists()) {
                throw new InvalidArgumentException(
                    "This closure of loan #{$loanId} has already been executed (duplicate submit)."
                );
            }

            if (! $loan->usesOffers()) {
                throw new InvalidArgumentException(
                    "Loan #{$loanId} is legacy (per-loan amortization); use EarlyRepaymentExecutionService."
                );
            }

            if (! in_array($loan->status, self::CLOSABLE_STATUSES, true)) {
                throw new InvalidArgumentException(
                    "Cannot close loan #{$loanId} early from status '{$loan->status}'."
                );
            }

            $quote = $this->calculator->quote($loan, $principalAmount, $asOf);

            $accruedWrittenOff = '0.00';
            foreach ($quote['positions'] as $position) {
                $accruedWrittenOff = bcadd(
                    $accruedWrittenOff,
                    $this->settlePosition($loan, $position, $quote['is_full'], $asOf),
                    2,
                );
            }

            // Review 2026-09-05 (PAY-30 follow-up): on a FUNDING loan funded_amount is
            // live data — remaining capacity, funded_percentage and the funded→active
            // trigger all read it. The closed principal left the investors' positions,
            // so the marketplace must stop advertising it as raised; otherwise the loan
            // could activate «fully funded» while investors hold less than the borrower
            // amount. Active/late loans keep the historical figure (capacity unused).
            if ($loan->status === Loan::STATUS_FUNDING && ! $quote['is_full']) {
                $loan->forceFill([
                    'funded_amount' => bcsub((string) $loan->funded_amount, $quote['principal_total'], 2),
                ])->save();
            }

            $closure = LoanEarlyClosure::create([
                'loan_id' => $loan->id,
                'executed_by' => $adminId,
                'principal_amount' => $quote['principal_total'],
                'interest_amount' => $quote['interest_total'],
                'accrued_written_off' => $accruedWrittenOff,
                'ratio' => $quote['ratio'],
                'is_full' => $quote['is_full'],
                'as_of' => $asOf->toDateString(),
                'note' => $note,
                'request_token' => $requestToken,
            ]);

            Log::info('Early closure executed', [
                'loan_id' => $loan->id,
                'closure_id' => $closure->id,
                'ratio' => $quote['ratio'],
                'principal' => $quote['principal_total'],
                'interest' => $quote['interest_total'],
                'accrued_written_off' => $accruedWrittenOff,
                'is_full' => $quote['is_full'],
            ]);

            if ($quote['is_full']) {
                $loan->forceFill([
                    'early_repaid_at' => now(),
                    'early_repayment_amount' => $quote['total'],
                    // PAY-30 evidence: a full closure from `funding` ends a
                    // partially funded loan — recorded like the auto-close does.
                    'closed_from_status' => $loan->status,
                    'closed_at' => now(),
                ])->save();

                // PAY-13: a FULL closure means the borrower repaid everything
                // early — the tracking plan is settled too. Rows left pending/late
                // would keep the loan a Buyback candidate / paused forever.
                $openTrackerRows = $loan->amortizationSchedules()
                    ->borrowerTracker()
                    ->whereIn('status', ['pending', 'late'])
                    ->lockForUpdate()
                    ->get();
                foreach ($openTrackerRows as $trackerRow) {
                    $trackerRow->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
                }

                $loan->transitionTo(Loan::STATUS_REPAID);
            }

            LoanEvent::create([
                'loan_id' => $loan->id,
                'event_type' => LoanEvent::TYPE_EARLY_REPAYMENT_COMPLETED,
                'triggered_by' => 'admin',
                'triggered_by_user_id' => $adminId,
                'occurred_at' => now(),
                'metadata' => [
                    'closure_id' => $closure->id,
                    'is_full' => $quote['is_full'],
                    'ratio' => $quote['ratio'],
                    'principal' => $quote['principal_total'],
                    'interest' => $quote['interest_total'],
                    'as_of' => $asOf->toDateString(),
                    'investors' => count($quote['positions']),
                ],
            ]);

            return ['closure' => $closure, 'quote' => $quote];
        });

        $this->announce($loanId, $result['closure'], $result['quote']);

        return $result;
    }

    /**
     * One investor's share: money out, schedule rewritten.
     *
     * @param  array<string, mixed>  $position
     */
    /**
     * @return string accrued interest written off for this position (scale 2)
     */
    private function settlePosition(Loan $loan, array $position, bool $isFull, CarbonInterface $asOf): string
    {
        $investment = $position['investment'];
        $principal = $position['principal'];
        $interest = $position['interest'];
        $accruedRelease = $position['accrued_release'];
        $reference = "loan:{$loan->id}:investment:{$investment->id}:early_closure";

        if (bccomp($principal, '0', 2) > 0) {
            $this->walletService->earlyRepayPrincipal(
                $investment->user_id,
                $principal,
                "Early closure principal for loan #{$loan->id}",
                $reference,
            );
        }

        // Capitalized interest is partly parked in the `accrued` bucket already
        // — release that part instead of crediting it twice, then top up.
        if (bccomp($accruedRelease, '0', 2) > 0) {
            $this->walletService->releaseAccrued(
                $investment->user_id,
                $accruedRelease,
                "Early closure: release accrued interest for loan #{$loan->id}",
                $reference,
            );
        }

        $topUp = bcsub($interest, $accruedRelease, 2);
        if (bccomp($topUp, '0', 2) > 0) {
            $this->walletService->earlyRepayInterest(
                $investment->user_id,
                $topUp,
                "Early closure interest for loan #{$loan->id}",
                $reference,
            );
        }

        // Full closure: whatever the payout engine had already parked in
        // `accrued` beyond the 30/360 interest owed today is a promise that
        // will never be funded — the engine accrues on calendar milestones
        // (first one ~25 days in), the closure prices the days actually used.
        // Left alone it would sit in the investor's «Текущо салдо» forever on a
        // loan that is `repaid` (audit 2026-09-01, PAY-35). Write it off, same
        // as a principal-only buyback does (WalletService::reverseAccrued).
        // Keyed on the POSITION being emptied, not on the loan-level flag: a
        // near-full partial closure can hand one investor their entire
        // outstanding through the Hamilton split while the loan stays partial,
        // and that position has no pending row left to ever release or reverse
        // the residual (review 2026-09-03).
        $writtenOff = '0.00';
        $positionEmptied = $isFull || bccomp($principal, $position['outstanding'], 2) === 0;
        if ($positionEmptied) {
            $residual = AccruedInterestLedger::netFor($loan->id, $investment->id);
            if (bccomp($residual, '0', 2) > 0) {
                $this->walletService->reverseAccrued(
                    $investment->user_id,
                    $residual,
                    "Early closure: write off residual accrued interest for loan #{$loan->id}",
                    $reference,
                );
                $writtenOff = $residual;
            }
        }

        $this->rewriteSchedule($position, $isFull, $asOf);

        return $writtenOff;
    }

    /**
     * The closed share comes off every remaining installment pro-rata — «свива
     * се позицията, не се намалява срокът». Due dates and their count are left
     * exactly as they were.
     *
     * A full closure marks the rows `closed`: they were cancelled, not paid,
     * and nothing downstream may count them as received money.
     *
     * @param  array<string, mixed>  $position
     */
    private function rewriteSchedule(array $position, bool $isFull, CarbonInterface $asOf): void
    {
        /** @var Collection<int, InvestmentSchedule> $rows */
        $rows = $position['rows'];

        if ($isFull) {
            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => InvestmentSchedule::STATUS_CLOSED,
                    'closed_at' => $asOf,
                ])->save();
            }

            return;
        }

        $outstanding = $position['outstanding'];
        $remainingPrincipal = bcsub($outstanding, $position['principal'], 2);

        $principalWeights = [];
        $interestWeights = [];
        $interestTotal = '0.00';

        foreach ($rows as $row) {
            $principalWeights[$row->id] = (string) $row->principal;
            $interestWeights[$row->id] = (string) $row->interest;
            $interestTotal = bcadd($interestTotal, (string) $row->interest, 2);
        }

        // The remaining position keeps the same share of the future interest as
        // it keeps of the principal.
        $keepShare = bcdiv($remainingPrincipal, $outstanding, self::SCALE);
        $remainingInterest = bcadd(bcmul($interestTotal, $keepShare, self::SCALE), '0', 2);

        $newPrincipal = $this->distribution->largestRemainderSplit($remainingPrincipal, $principalWeights);
        $newInterest = $this->distribution->largestRemainderSplit($remainingInterest, $interestWeights);

        foreach ($rows as $row) {
            $principal = $newPrincipal[$row->id] ?? '0.00';
            $interest = $newInterest[$row->id] ?? '0.00';

            // A row that keeps nothing (a near-full partial where the Hamilton
            // split handed this investor their entire outstanding) is cancelled,
            // not left `pending`: the engine would otherwise mark it `paid` with
            // zero cash, and a zero "received installment" would satisfy the
            // conditional-bonus rule Reni excluded early closures from
            // (audit 2026-09-01, PAY-46).
            if (bccomp($principal, '0', 2) === 0 && bccomp($interest, '0', 2) === 0) {
                $row->forceFill([
                    'principal' => '0.00',
                    'interest' => '0.00',
                    'total' => '0.00',
                    'status' => InvestmentSchedule::STATUS_CLOSED,
                    'closed_at' => $asOf,
                ])->save();

                continue;
            }

            $row->forceFill([
                'principal' => $principal,
                'interest' => $interest,
                'total' => bcadd($principal, $interest, 2),
            ])->save();
        }
    }

    /** Telegram is best-effort and never breaks the money path. */
    private function announce(int $loanId, LoanEarlyClosure $closure, array $quote): void
    {
        try {
            $this->telegram->high(
                $closure->is_full ? 'Предсрочно погасен кредит' : 'Частично предсрочно погасяване',
                "Кредит #{$loanId}: върнати {$quote['principal_total']} € главница + "
                ."{$quote['interest_total']} € лихва към ".$closure->as_of->format('d.m.Y').'.',
                [
                    'loan_id' => $loanId,
                    'closure_id' => $closure->id,
                    'ratio' => $quote['ratio'],
                    'investors' => count($quote['positions']),
                ],
            );
        } catch (\Throwable $ignored) {
            // Logged inside TelegramService.
        }
    }
}
