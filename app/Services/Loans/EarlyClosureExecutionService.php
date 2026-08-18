<?php

namespace App\Services\Loans;

use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEarlyClosure;
use App\Models\LoanEvent;
use App\Services\TelegramService;
use App\Services\WalletService;
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
    private const CLOSABLE_STATUSES = [Loan::STATUS_ACTIVE, Loan::STATUS_LATE, Loan::STATUS_DEFAULT];

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

        $result = DB::transaction(function () use ($loanId, $adminId, $principalAmount, $asOf, $note) {
            /** @var Loan $loan */
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

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

            foreach ($quote['positions'] as $position) {
                $this->settlePosition($loan, $position, $quote['is_full'], $asOf);
            }

            $closure = LoanEarlyClosure::create([
                'loan_id' => $loan->id,
                'executed_by' => $adminId,
                'principal_amount' => $quote['principal_total'],
                'interest_amount' => $quote['interest_total'],
                'ratio' => $quote['ratio'],
                'is_full' => $quote['is_full'],
                'as_of' => $asOf->toDateString(),
                'note' => $note,
            ]);

            Log::info('Early closure executed', [
                'loan_id' => $loan->id,
                'closure_id' => $closure->id,
                'ratio' => $quote['ratio'],
                'principal' => $quote['principal_total'],
                'interest' => $quote['interest_total'],
                'is_full' => $quote['is_full'],
            ]);

            if ($quote['is_full']) {
                $loan->forceFill([
                    'early_repaid_at' => now(),
                    'early_repayment_amount' => $quote['total'],
                ])->save();

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
    private function settlePosition(Loan $loan, array $position, bool $isFull, CarbonInterface $asOf): void
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

        $this->rewriteSchedule($position, $isFull, $asOf);
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
