<?php

namespace App\Services;

use App\Models\InvestmentSchedule;
use App\Models\Loan;

/**
 * Generates per-investment payout schedules for an offer-based loan at
 * activation — the offer-based counterpart to AmortizationService (which
 * generates the single per-loan borrower schedule for legacy loans).
 *
 * Each investment's rows come from its OWN snapshotted rate + payout type, so
 * three investors in the same loan get three different cash-flow shapes.
 * Idempotent per investment (skips any that already have a schedule).
 */
class InvestmentScheduleGenerator
{
    public function __construct(private OfferProjectionService $projection) {}

    public function generate(Loan $loan): void
    {
        $investments = $loan->investments()->whereNotNull('loan_offer_id')->get();

        foreach ($investments as $investment) {
            if ($investment->schedules()->exists()) {
                continue;
            }

            $rows = $this->projection->schedule(
                (string) $investment->amount,
                (string) $investment->interest_rate,
                (int) $loan->term_months,
                $investment->payout_type,
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
}
