<?php

namespace App\Services;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The scheduled-accrual payout engine for OFFER-based loans (boss feature
 * 2026-06-23). On a given date it advances every investor's position per their
 * plan, REGARDLESS of whether the borrower has actually paid — the platform
 * accrues on schedule and carries the exposure in the `accrued` bucket.
 *
 * Per plan (the investor's snapshotted offer):
 *   • amortizing / interest-only — each due installment is RELEASED straight to
 *     `available` (principal returns invested→available, interest →available+earned).
 *   • capitalized — interest is ACCRUED monthly into the locked `accrued` bucket
 *     (so "текущо салдо" = invested + accrued grows toward maturity), and the
 *     whole lot is RELEASED to `available` at maturity.
 *
 * Idempotent: re-running for the same date is a no-op (released rows are marked
 * paid; capitalized accrues only the delta toward its compounding target).
 *
 * The SAME engine backs both the manual admin button and the automatic cron —
 * the only difference is who pulls the trigger (Loan::payout_mode).
 */
class PayoutAccrualService
{
    /** Rate-math scale — matches OfferProjectionService / AmortizationService. */
    private const SCALE = 10;

    public function __construct(private WalletService $walletService) {}

    /**
     * Advance every offer-based investment of a loan to $asOf.
     *
     * @return array{released_count:int, accrued_count:int, released_total:string, accrued_total:string}
     */
    public function processLoan(int $loanId, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();

        $summary = ['released_count' => 0, 'accrued_count' => 0, 'released_total' => '0.00', 'accrued_total' => '0.00'];

        DB::transaction(function () use ($loanId, $asOf, &$summary) {
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // Since 2026-08-13 (Reni: interest runs from the invest moment) a
            // loan doesn't need to be activated for its investors to be paid —
            // funding-stage loans qualify too. Terminal + default stay out.
            if (! in_array($loan->status, Loan::PAYOUT_ELIGIBLE_STATUSES, true)) {
                throw new InvalidArgumentException('Scheduled payout cannot run for this loan status.');
            }

            $investments = $loan->investments()
                ->whereNotNull('loan_offer_id')
                ->with('user')
                ->lockForUpdate()
                ->get();

            foreach ($investments as $investment) {
                if ($investment->payout_type === PayoutType::Capitalized) {
                    $this->processCapitalized($loan, $investment, $asOf, $summary);
                } else {
                    $this->processScheduledRelease($loan, $investment, $asOf, $summary);
                }
            }
        });

        return $summary;
    }

    /**
     * Amortizing & interest-only: release every due installment straight to
     * `available`. Driven by the per-investment schedule rows.
     */
    private function processScheduledRelease(Loan $loan, Investment $investment, CarbonInterface $asOf, array &$summary): void
    {
        $rows = InvestmentSchedule::where('investment_id', $investment->id)
            ->whereIn('status', ['pending', 'late'])
            ->whereDate('due_date', '<=', $asOf->toDateString())
            ->lockForUpdate()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $reference = "loan:{$loan->id}:investment:{$investment->id}:schedule:{$row->id}";

            if (bccomp((string) $row->principal, '0', 2) > 0) {
                $this->walletService->repayPrincipal(
                    $investment->user_id,
                    (string) $row->principal,
                    "Scheduled principal payout for loan #{$loan->id}",
                    $reference,
                );
            }

            if (bccomp((string) $row->interest, '0', 2) > 0) {
                $this->walletService->repayInterest(
                    $investment->user_id,
                    (string) $row->interest,
                    "Scheduled interest payout for loan #{$loan->id}",
                    $reference,
                );
            }

            $row->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            $summary['released_count']++;
            $summary['released_total'] = bcadd($summary['released_total'], (string) $row->total, 2);
        }
    }

    /**
     * Capitalized: accrue the monthly-compounded interest into `accrued` toward
     * its target for the elapsed months; at maturity, release the whole accrued
     * interest plus principal into `available`.
     */
    private function processCapitalized(Loan $loan, Investment $investment, CarbonInterface $asOf, array &$summary): void
    {
        /** @var InvestmentSchedule|null $row capitalized has exactly one maturity row */
        $row = InvestmentSchedule::where('investment_id', $investment->id)
            ->whereIn('status', ['pending', 'late'])
            ->lockForUpdate()
            ->orderBy('due_date')
            ->first();

        if (! $row) {
            return; // already matured + released
        }

        // Term from the FROZEN row — never the live (editable) loan.term_months.
        // See OfferProjectionService::capitalizedTermFromRow.
        $term = OfferProjectionService::capitalizedTermFromRow(
            (string) $investment->amount,
            (string) $investment->interest_rate,
            (string) $row->interest,
            (int) $loan->term_months,
        );
        $amount = (string) $investment->amount;
        $maturity = $row->due_date->copy()->startOfDay();
        $firstDue = $maturity->copy()->subMonthsNoOverflow($term - 1);
        $asOfDay = $asOf->copy()->startOfDay();

        // Elapsed monthly milestones (firstDue, firstDue+1mo, …, maturity).
        if ($asOfDay->lt($firstDue)) {
            $elapsed = 0;
        } else {
            $elapsed = min($term, (int) $firstDue->diffInMonths($asOfDay) + 1);
        }

        $alreadyAccrued = $this->accruedToDate($loan->id, $investment->id);
        $reference = "loan:{$loan->id}:investment:{$investment->id}:capitalized";

        if ($elapsed >= $term) {
            // Maturity — reconcile to the schedule's EXACT interest (source of
            // truth, avoids per-step compounding drift), then release all.
            $finalInterest = (string) $row->interest;

            $remaining = bcsub($finalInterest, $alreadyAccrued, 2);
            if (bccomp($remaining, '0', 2) > 0) {
                $this->walletService->accrueInterest(
                    $investment->user_id, $remaining,
                    "Capitalized interest accrual (maturity) for loan #{$loan->id}", $reference,
                );
                $summary['accrued_count']++;
                $summary['accrued_total'] = bcadd($summary['accrued_total'], $remaining, 2);
            }

            if (bccomp($finalInterest, '0', 2) > 0) {
                $this->walletService->releaseAccrued(
                    $investment->user_id, $finalInterest,
                    "Capitalized interest payout (maturity) for loan #{$loan->id}", $reference,
                );
            }

            $this->walletService->repayPrincipal(
                $investment->user_id, $amount,
                "Capitalized principal payout (maturity) for loan #{$loan->id}", $reference,
            );

            $row->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            $summary['released_count']++;
            $summary['released_total'] = bcadd($summary['released_total'], bcadd($finalInterest, $amount, 2), 2);

            return;
        }

        // Pre-maturity — accrue the delta toward the compounding target.
        $target = $this->compoundedInterestToDate($amount, (string) $investment->interest_rate, $elapsed);
        $delta = bcsub($target, $alreadyAccrued, 2);

        if (bccomp($delta, '0', 2) > 0) {
            $this->walletService->accrueInterest(
                $investment->user_id, $delta,
                "Capitalized interest accrual for loan #{$loan->id}", $reference,
            );
            $summary['accrued_count']++;
            $summary['accrued_total'] = bcadd($summary['accrued_total'], $delta, 2);
        } elseif (bccomp($delta, '0', 2) < 0) {
            // Should not happen (target is monotonic) — surface, don't silently skip.
            Log::warning('PayoutAccrualService: capitalized accrual target below already-accrued', [
                'loan_id' => $loan->id, 'investment_id' => $investment->id,
                'target' => $target, 'already' => $alreadyAccrued, 'elapsed' => $elapsed,
            ]);
        }
    }

    /** Compounded interest after $months: principal × ((1+r)^months − 1). */
    private function compoundedInterestToDate(string $principal, string $annualRatePct, int $months): string
    {
        if ($months <= 0) {
            return '0.00';
        }

        $r = bcdiv(bcdiv($annualRatePct, '100', self::SCALE), '12', self::SCALE);
        $growth = bcpow(bcadd('1', $r, self::SCALE), (string) $months, self::SCALE);

        return bcsub(bcmul($principal, $growth, 2), $principal, 2);
    }

    /**
     * Net interest currently accrued for one investment (Σ accrued − Σ released
     * − Σ reversed), read from the immutable ledger by reference. The trailing
     * ":" guards against investment-id prefix collisions (1 vs 11).
     */
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
