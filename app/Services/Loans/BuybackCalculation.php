<?php

namespace App\Services\Loans;

/**
 * Pure-data result of BuybackCalculationService::calculateTotal().
 *
 * All amounts are bcmath-style strings at scale 2 — never floats.
 *
 * Coverage values:
 *   'principal_only'            — interest = '0.00'
 *   'principal_plus_interest'   — interest = sum of scheduled interest on
 *                                 unpaid installments (status in pending|late).
 *                                 NO day-count accrued interest per Q2.
 *
 * Shape matches the BuybackCalculationService::COVERAGE_* constants.
 */
final class BuybackCalculation
{
    public function __construct(
        public readonly string $coverageType,
        public readonly string $principal,
        public readonly string $interest,
        public readonly string $total,
    ) {}
}
