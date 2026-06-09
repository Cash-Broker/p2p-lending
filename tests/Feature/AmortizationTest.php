<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\AmortizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmortizationTest extends TestCase
{
    use RefreshDatabase;

    private function createFundedLoan(array $overrides = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'amount' => '1000.00',
            'funded_amount' => '1000.00',
            'interest_rate' => '12.00',
            'interest_rate_annual' => '14.00',
            'term_months' => 3,
            'status' => Loan::STATUS_FUNDED,
        ], $overrides));
    }

    // ── Auto-generation on activation ──

    public function test_schedule_generated_on_activation(): void
    {
        $loan = $this->createFundedLoan([
            'amount' => '9000.00',
            'funded_amount' => '9000.00',
            'interest_rate' => '12.00',
            'term_months' => 9,
        ]);

        $this->assertEquals(0, $loan->amortizationSchedules()->count());

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
        $this->assertCount(9, $schedules);
        $this->assertTrue($schedules->every(fn ($s) => $s->status === 'pending'));
        $this->assertTrue($schedules->every(fn ($s) => bccomp($s->principal, '0', 2) > 0));
    }

    public function test_schedule_not_generated_on_late_to_active_transition(): void
    {
        // Simulates: loan activated once (schedule generated), went late, now returning to active
        $loan = $this->createFundedLoan(['status' => Loan::STATUS_ACTIVE]);

        // Manually create some schedule entries as if from prior activation
        AmortizationSchedule::factory()->count(3)->create(['loan_id' => $loan->id]);

        $loan->transitionTo(Loan::STATUS_LATE);
        $loan->transitionTo(Loan::STATUS_ACTIVE);

        // Still exactly 3 entries — not regenerated
        $this->assertEquals(3, $loan->amortizationSchedules()->count());
    }

    // ── Principal totals exactly match loan amount ──

    public function test_schedule_total_matches_loan(): void
    {
        $loan = $this->createFundedLoan([
            'amount' => '9000.00',
            'funded_amount' => '9000.00',
            'interest_rate' => '12.00',
            'term_months' => 9,
        ]);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $sumPrincipal = $loan->amortizationSchedules()
            ->get()
            ->reduce(fn ($carry, $s) => bcadd($carry, $s->principal, 2), '0.00');

        $this->assertEquals(
            0,
            bccomp('9000.00', $sumPrincipal, 2),
            "Sum of principal installments ({$sumPrincipal}) must equal loan amount (9000.00)"
        );
    }

    public function test_schedule_total_matches_loan_with_rounding_challenge(): void
    {
        // 1000 / 3 is inherently non-terminating — perfect test for rounding drift.
        $loan = $this->createFundedLoan([
            'amount' => '1000.00',
            'funded_amount' => '1000.00',
            'interest_rate' => '12.00',
            'term_months' => 3,
        ]);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $sumPrincipal = $loan->amortizationSchedules()
            ->get()
            ->reduce(fn ($carry, $s) => bcadd($carry, $s->principal, 2), '0.00');

        $this->assertEquals(0, bccomp('1000.00', $sumPrincipal, 2),
            "Sum ({$sumPrincipal}) must equal 1000.00 exactly — no penny lost");
    }

    // ── Last installment absorbs rounding drift ──

    public function test_last_installment_absorbs_rounding(): void
    {
        $loan = $this->createFundedLoan([
            'amount' => '1000.00',
            'funded_amount' => '1000.00',
            'interest_rate' => '12.00',
            'term_months' => 3,
        ]);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
        $lastInstallment = $schedules->last();

        // Sum principals of first (n-1) installments
        $distributedBeforeLast = $schedules->take($schedules->count() - 1)
            ->reduce(fn ($carry, $s) => bcadd($carry, $s->principal, 2), '0.00');

        // Last installment's principal should close the gap exactly
        $expectedLast = bcsub('1000.00', $distributedBeforeLast, 2);

        $this->assertEquals(
            0,
            bccomp($expectedLast, $lastInstallment->principal, 2),
            "Last principal ({$lastInstallment->principal}) must equal remaining balance ({$expectedLast})"
        );

        // And it must differ from the prior installments (otherwise no rounding to absorb).
        // For 1000/3 with interest, early installments will have principal ≈ 330.xx,
        // last one should be adjusted. This is a soft check but catches obvious bugs.
        $firstPrincipal = $schedules->first()->principal;
        $lastPrincipal = $lastInstallment->principal;
        // At minimum they won't be exactly identical for this rounding-challenged case
        $this->assertNotEquals($firstPrincipal, $lastPrincipal,
            'Principals should differ — last absorbs rounding');
    }

    // ── Guard against duplicate generation ──

    public function test_cannot_regenerate_schedule(): void
    {
        $loan = $this->createFundedLoan();

        // First generation: succeeds via activation
        $loan->transitionTo(Loan::STATUS_ACTIVE);
        $this->assertEquals(3, $loan->amortizationSchedules()->count());

        // Second generation: explicit service call must throw
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Schedule already exists');

        app(AmortizationService::class)->generateSchedule($loan->fresh());
    }

    // ── Annuity formula sanity check ──

    public function test_annuity_calculation(): void
    {
        // Known example: P=18000, annual rate=11%, n=9
        // Monthly rate r = 0.11/12 ≈ 0.009167
        // M = 18000 * 0.009167 * (1.009167)^9 / ((1.009167)^9 - 1)
        //   ≈ 18000 * 0.009167 * 1.0856 / 0.0856
        //   ≈ 2092 (computed) — request states ~2088 which is a close approximation
        $loan = $this->createFundedLoan([
            'amount' => '18000.00',
            'funded_amount' => '18000.00',
            'interest_rate' => '11.00',
            'term_months' => 9,
        ]);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();

        // All installments except the last should have the same total (annuity = fixed payment)
        $firstTotal = $schedules->first()->total;

        // Sanity check the monthly payment is in the expected ballpark (2080–2100)
        $this->assertGreaterThan(
            0,
            bccomp($firstTotal, '2080.00', 2),
            "Monthly payment ({$firstTotal}) should be > 2080"
        );
        $this->assertLessThan(
            0,
            bccomp($firstTotal, '2100.00', 2),
            "Monthly payment ({$firstTotal}) should be < 2100"
        );

        // All installments except the last should have identical total (annuity property)
        $firstEight = $schedules->take(8);
        foreach ($firstEight as $s) {
            $this->assertEquals(
                0,
                bccomp($s->total, $firstTotal, 2),
                "Annuity installments should have identical totals"
            );
        }

        // First installment: interest should equal 18000 * monthlyRate (≈ 165.00)
        $firstInterest = $schedules->first()->interest;
        $this->assertGreaterThan(0, bccomp($firstInterest, '164.00', 2));
        $this->assertLessThan(0, bccomp($firstInterest, '166.00', 2));

        // Total of all installments = sum(principals) + sum(interests)
        // Sum(principals) == 18000.00 (by construction)
        $sumPrincipal = $schedules->reduce(fn ($carry, $s) => bcadd($carry, $s->principal, 2), '0.00');
        $this->assertEquals(0, bccomp('18000.00', $sumPrincipal, 2));
    }

    // ── First due date ──

    public function test_first_due_date_is_30_days_out(): void
    {
        $loan = $this->createFundedLoan(['term_months' => 3]);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $firstDue = $loan->amortizationSchedules()->orderBy('due_date')->first()->due_date;
        $expected = now()->addDays(30)->startOfDay();

        $this->assertEquals(
            $expected->format('Y-m-d'),
            $firstDue->format('Y-m-d'),
            'First installment due date should be 30 days from activation'
        );
    }

    // ── Atomicity: failure rolls back status ──

    public function test_schedule_failure_rolls_back_status(): void
    {
        // An invalid term makes schedule generation throw, exercising the
        // transactional rollback. (A PRE-EXISTING schedule is no longer a failure
        // trigger — activation now intentionally preserves an admin-generated
        // schedule; see AmortizationCalculatorTest.)
        $loan = $this->createFundedLoan(['term_months' => 0]);

        try {
            $loan->transitionTo(Loan::STATUS_ACTIVE);
            $this->fail('Expected exception was not thrown.');
        } catch (\InvalidArgumentException $e) {
            // Expected — schedule generation should have thrown
        }

        // Status must remain FUNDED since the transaction rolled back
        $this->assertEquals(Loan::STATUS_FUNDED, $loan->fresh()->status);
    }

    // ── Zero-interest edge case ──

    public function test_zero_interest_loan_equal_principal_split(): void
    {
        $loan = $this->createFundedLoan([
            'amount' => '1200.00',
            'funded_amount' => '1200.00',
            'interest_rate' => '0.00',
            'term_months' => 4,
        ]);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $schedules = $loan->amortizationSchedules()->get();

        foreach ($schedules as $s) {
            $this->assertEquals('0.00', $s->interest);
            $this->assertEquals($s->principal, $s->total);
        }

        $sumPrincipal = $schedules->reduce(fn ($carry, $s) => bcadd($carry, $s->principal, 2), '0.00');
        $this->assertEquals(0, bccomp('1200.00', $sumPrincipal, 2));
    }
}
