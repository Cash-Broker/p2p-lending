<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use Carbon\CarbonInterface;

/**
 * Generates per-investment payout schedules for offer-based investments — the
 * offer-based counterpart to AmortizationService (which generates the single
 * per-loan borrower schedule for legacy loans).
 *
 * Each investment's rows come from its OWN snapshotted rate + payout type, so
 * three investors in the same loan get three different cash-flow shapes.
 * Idempotent per investment (skips any that already have a schedule).
 *
 * Since 2026-08-13 (client decision, Reni: «след като клиент инвестира,
 * олихвяването си тръгва веднага за него») the schedule is generated at
 * INVEST time, anchored on the invest moment — interest runs from the first
 * euro regardless of whether the loan ever reaches 100% funding. The per-loan
 * generate() remains as the activation-time catch-up for investments created
 * before this change (anchored at activation, exactly as before).
 */
class InvestmentScheduleGenerator
{
    public function __construct(private OfferProjectionService $projection) {}

    public function generate(Loan $loan): void
    {
        $investments = $loan->investments()->whereNotNull('loan_offer_id')->get();

        foreach ($investments as $investment) {
            $this->generateForInvestment($investment, $loan);
        }
    }

    /**
     * Generate THIS investment's schedule if it doesn't have one yet.
     * $anchor sets the 30·i-day due-date spacing base (defaults to now):
     * the invest moment at invest time, or invested_at for backfills.
     */
    public function generateForInvestment(Investment $investment, ?Loan $loan = null, ?CarbonInterface $anchor = null): void
    {
        if ($investment->loan_offer_id === null) {
            return; // legacy investment — lives on the per-loan amortization plan
        }

        if ($investment->schedules()->exists()) {
            return;
        }

        $loan = $loan ?? $investment->loan;

        $rows = $this->projection->schedule(
            (string) $investment->amount,
            (string) $investment->interest_rate,
            (int) $loan->term_months,
            $investment->payout_type,
            null,
            $anchor,
        );

        foreach ($rows as $row) {
            InvestmentSchedule::create([
                'investment_id' => $investment->id,
                'loan_id' => $loan->id,
                'due_date' => $row['due_date'],
                'principal' => $row['principal'],
                'interest' => $row['interest'],
                'total' => $row['total'],
                'status' => 'pending',
            ]);
        }
    }
}
