<?php

namespace App\Services\Loans;

/**
 * Pure-data result of EarlyRepaymentCalculationService::calculateTotal().
 *
 * All amounts are bcmath-style strings at scale 2 — never floats.
 *
 * Schedule-boundary semantic (F3 Q3 approved):
 *   principal = Σ schedule.principal where status IN (pending, late)
 *   interest  = Σ schedule.interest  where status IN (pending, late)
 *               AND due_date <= next_upcoming_schedule.due_date
 *   total     = principal + interest
 *
 * Mirrors BuybackCalculation shape minus `coverageType` (no per-originator
 * configuration for early repayment — all loans use the same formula).
 */
final class EarlyRepaymentCalculation
{
    public function __construct(
        public readonly string $principal,
        public readonly string $interest,
        public readonly string $total,
    ) {}
}
