<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Services\AmortizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loan-overhaul Phase 4 — the schedule calculator: generation from a chosen
 * first-due date, and create-time generation surviving activation.
 */
class AmortizationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_monthly_schedule_from_custom_first_due_date(): void
    {
        $loan = Loan::factory()->create([
            'amount' => '1200.00',
            'investable_amount' => '1200.00',
            'term_months' => 3,
            'interest_rate' => '12.00',
            'status' => 'draft',
        ]);

        app(AmortizationService::class)->generateSchedule($loan, Carbon::create(2026, 8, 15));

        $rows = $loan->amortizationSchedules()->orderBy('due_date')->get();

        $this->assertCount(3, $rows);
        $this->assertEquals('2026-08-15', $rows[0]->due_date->format('Y-m-d'));
        $this->assertEquals('2026-09-15', $rows[1]->due_date->format('Y-m-d'));
        $this->assertEquals('2026-10-15', $rows[2]->due_date->format('Y-m-d'));

        // Σ principal == investable amount.
        $sum = $rows->reduce(fn ($c, $r) => bcadd($c, (string) $r->principal, 2), '0.00');
        $this->assertSame('1200.00', $sum);
    }

    public function test_backdated_schedule_marks_elapsed_installments_paid_and_caps_funding_to_outstanding(): void
    {
        $loan = Loan::factory()->create([
            'amount' => '1200.00',
            'investable_amount' => '1200.00',
            'term_months' => 4,
            'interest_rate' => '12.00',
            'status' => 'draft',
        ]);

        // Listed now, but the first installment was due 2 months ago.
        app(AmortizationService::class)->generateSchedule($loan, now()->subMonthsNoOverflow(2));

        $rows = $loan->amortizationSchedules()->orderBy('due_date')->get();
        $paid = $rows->where('status', 'paid');
        $pending = $rows->where('status', 'pending');

        // Elapsed installments are paid; future ones remain pending.
        $this->assertTrue($paid->isNotEmpty(), 'Expected at least one elapsed installment marked paid.');
        $this->assertTrue($pending->isNotEmpty(), 'Expected at least one future installment pending.');
        $this->assertTrue($paid->every(fn ($r) => $r->due_date->lt(today())));

        // The full schedule still sums to the investable amount.
        $this->assertSame('1200.00', $rows->reduce(fn ($c, $r) => bcadd($c, (string) $r->principal, 2), '0.00'));

        // Funding cap == outstanding (pending) principal — strictly less than the
        // full investable, so investors fund only what they'll be repaid.
        $outstanding = $pending->reduce(fn ($c, $r) => bcadd($c, (string) $r->principal, 2), '0.00');
        $this->assertSame($outstanding, $loan->fundingCap());
        $this->assertTrue(bccomp($loan->fundingCap(), '1200.00', 2) < 0);
    }

    public function test_pre_generated_schedule_survives_activation_without_regeneration(): void
    {
        $loan = Loan::factory()->create([
            'amount' => '1200.00',
            'investable_amount' => '1200.00',
            'term_months' => 3,
            'interest_rate' => '12.00',
            'status' => 'funded',
            'funded_amount' => '1200.00',
        ]);

        // Admin generated the plan at draft via the calculator (custom dates).
        app(AmortizationService::class)->generateSchedule($loan, Carbon::create(2026, 8, 15));
        $countBefore = $loan->amortizationSchedules()->count();

        // Activation must NOT throw "schedule already exists" nor overwrite dates.
        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $loan->refresh();
        $this->assertEquals(Loan::STATUS_ACTIVE, $loan->status);
        $this->assertEquals($countBefore, $loan->amortizationSchedules()->count());
        $this->assertEquals(
            '2026-08-15',
            $loan->amortizationSchedules()->orderBy('due_date')->first()->due_date->format('Y-m-d'),
        );
    }
}
