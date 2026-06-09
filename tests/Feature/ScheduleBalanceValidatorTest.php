<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Support\Loans\ScheduleBalanceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the balancing guard the AmortizationSchedulesRelationManager delegates
 * to. A hand-edited inconsistent schedule would silently corrupt buyback /
 * early-repayment payouts (both sum the schedule directly), so saving must be
 * rejected when total != principal + interest, or when the scheduled principal
 * overshoots the loan's amortization base.
 */
class ScheduleBalanceValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function draftLoan(string $amount = '1000.00'): Loan
    {
        return Loan::factory()->create(['amount' => $amount, 'status' => Loan::STATUS_DRAFT]);
    }

    private function addRow(Loan $loan, string $principal, string $interest): void
    {
        $loan->amortizationSchedules()->create([
            'due_date' => now(),
            'principal' => $principal,
            'interest' => $interest,
            'total' => bcadd($principal, $interest, 2),
            'status' => 'pending',
        ]);
    }

    // ── Rejection 1: per-row total != principal + interest ──

    public function test_rejects_row_whose_total_is_not_principal_plus_interest(): void
    {
        $this->assertNotNull(ScheduleBalanceValidator::rowTotalError('400.00', '200.00', '601.00'));
        // A one-cent drift is still rejected — payouts are cent-exact.
        $this->assertNotNull(ScheduleBalanceValidator::rowTotalError('400.00', '200.00', '600.01'));
    }

    public function test_accepts_row_whose_total_equals_principal_plus_interest(): void
    {
        $this->assertNull(ScheduleBalanceValidator::rowTotalError('400.00', '200.00', '600.00'));
        $this->assertNull(ScheduleBalanceValidator::rowTotalError('0.00', '0.00', '0.00'));
    }

    public function test_total_check_defers_to_numeric_rules_for_non_numeric_input(): void
    {
        // Empty / non-numeric input is handled by the field's required/numeric
        // rules, not the balancing rule.
        $this->assertNull(ScheduleBalanceValidator::rowTotalError('', '', ''));
    }

    // ── Rejection 2: principal sum overshoots the amortization base ──

    public function test_rejects_principal_that_overshoots_the_loan_amount(): void
    {
        $loan = $this->draftLoan('1000.00');
        $this->addRow($loan, '400.00', '0.00');
        $this->addRow($loan, '200.00', '0.00'); // existing principal sum = 600

        // 600 + 300 = 900 ≤ 1000 → allowed
        $this->assertNull(ScheduleBalanceValidator::principalOvershootError($loan, null, '300.00'));
        // 600 + 500 = 1100 > 1000 → rejected
        $this->assertNotNull(ScheduleBalanceValidator::principalOvershootError($loan, null, '500.00'));
        // Exactly hitting the base is fine.
        $this->assertNull(ScheduleBalanceValidator::principalOvershootError($loan, null, '400.00'));
    }

    public function test_editing_a_row_excludes_its_own_principal_from_the_sum(): void
    {
        $loan = $this->draftLoan('1000.00');
        $this->addRow($loan, '200.00', '0.00');
        $row = $loan->amortizationSchedules()->create([
            'due_date' => now(),
            'principal' => '400.00',
            'interest' => '0.00',
            'total' => '400.00',
            'status' => 'pending',
        ]);

        // Editing the 400 row up to 700: others (200) + 700 = 900 ≤ 1000 → allowed
        $this->assertNull(ScheduleBalanceValidator::principalOvershootError($loan, $row->id, '700.00'));
        // Up to 900: 200 + 900 = 1100 > 1000 → rejected
        $this->assertNotNull(ScheduleBalanceValidator::principalOvershootError($loan, $row->id, '900.00'));
    }

    public function test_overshoot_uses_the_loan_amortization_base(): void
    {
        // Sanity: the guard reads Loan::amortizationBase() (== amount today).
        $loan = $this->draftLoan('500.00');
        $this->assertSame('500.00', $loan->amortizationBase());
        $this->assertNotNull(ScheduleBalanceValidator::principalOvershootError($loan, null, '500.01'));
    }
}
