<?php

namespace Tests\Unit\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Services\Loans\EarlyRepaymentCalculation;
use App\Services\Loans\EarlyRepaymentCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase F3 Step 2 — EarlyRepaymentCalculationService.
 *
 * Schedule-boundary semantic (F3 Q3 approved): the "interest to pay on
 * early close" is the SUM of scheduled interest across unpaid schedules
 * whose due_date <= the next upcoming schedule's due_date. Pins that
 * rule across 6 boundary-condition scenarios plus the pro-rata
 * distribution invariants inherited from F2.
 */
class EarlyRepaymentCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EarlyRepaymentCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EarlyRepaymentCalculationService::class);
    }

    /**
     * Build a loan + originator + schedules with EXPLICIT due_date
     * offsets (days from today). Negative offsets = past. Allows tests
     * to position "today" at precise points relative to schedule
     * boundaries.
     *
     * @param  array<int, array{offset_days:int, principal:string, interest:string, status?:string}>  $schedules
     */
    private function makeLoan(
        string $fundedAmount,
        array $schedules,
        string $status = 'active',
    ): Loan {
        $orig = Originator::create([
            'name' => 'F3 Calc Test ' . uniqid(),
            'description' => 'Test',
            'buyback' => false,
        ]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A',
            'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);

        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => $fundedAmount, 'funded_amount' => $fundedAmount,
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => count($schedules), 'type' => 'consumer',
            'status' => $status,
        ]);

        $today = Carbon::now()->startOfDay();
        foreach ($schedules as $s) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'due_date' => $today->copy()->addDays($s['offset_days']),
                'principal' => $s['principal'],
                'interest' => $s['interest'],
                'total' => bcadd($s['principal'], $s['interest'], 2),
                'status' => $s['status'] ?? 'pending',
                'paid_at' => ($s['status'] ?? 'pending') === 'paid'
                    ? $today->copy()->addDays($s['offset_days'])
                    : null,
            ]);
        }

        return $loan->fresh();
    }

    /** Attach a list of investors to a loan with specific amounts. */
    private function attachInvestors(Loan $loan, array $amounts): array
    {
        $users = [];
        foreach ($amounts as $amount) {
            $u = User::factory()->create();
            Investment::create([
                'user_id' => $u->id, 'loan_id' => $loan->id,
                'amount' => $amount, 'invested_at' => now(),
            ]);
            $users[] = $u;
        }
        return $users;
    }

    // ═════════════════════════════════════════════════════════════════
    // Scenario 1 — normal mid-term
    // ═════════════════════════════════════════════════════════════════

    public function test_mid_term_with_some_paid_and_future_upcoming(): void
    {
        // Loan: 3-month, 300 funded, 100 principal × 3, 10 interest × 3.
        // Today sits between installment 1 (paid, -30d ago) and
        // installment 2 (pending, +15d from today).
        // Expected:
        //   outstanding = installments 2 + 3 = 200.00
        //   interest    = ONLY installment 2's interest (next upcoming) = 10.00
        //   installment 3 (+45d) is AFTER next upcoming → excluded
        $loan = $this->makeLoan('300.00', [
            ['offset_days' => -30, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['offset_days' => 15,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'pending'],
            ['offset_days' => 45,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'pending'],
        ]);

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('200.00', $calc->principal);
        $this->assertSame('10.00', $calc->interest);
        $this->assertSame('210.00', $calc->total);
    }

    // ═════════════════════════════════════════════════════════════════
    // Scenario 2 — pre-first-due (all upcoming, nothing paid yet)
    // ═════════════════════════════════════════════════════════════════

    public function test_pre_first_due_charges_only_first_upcoming_interest(): void
    {
        // Loan just activated. Today = day 5. First installment due day 30.
        // Borrower wants to close TODAY. All 3 installments pending.
        // Expected:
        //   outstanding = 300
        //   interest    = installment 1 only (next upcoming) = 10
        //   (Option B: ~15-25 day overpayment acceptable per T&C)
        $loan = $this->makeLoan('300.00', [
            ['offset_days' => 25, 'principal' => '100.00', 'interest' => '10.00'],
            ['offset_days' => 55, 'principal' => '100.00', 'interest' => '10.00'],
            ['offset_days' => 85, 'principal' => '100.00', 'interest' => '10.00'],
        ]);

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('300.00', $calc->principal);
        $this->assertSame('10.00', $calc->interest);
    }

    // ═════════════════════════════════════════════════════════════════
    // Scenario 3 — late loan: overdue + next upcoming BOTH included
    // ═════════════════════════════════════════════════════════════════

    public function test_late_loan_includes_overdue_and_next_upcoming_interest(): void
    {
        // 12-month loan. Installment 1 paid (day -60, on time).
        // Installment 2 (day -30) was due 30d ago — now status=late.
        // Installment 3 (day +30) upcoming — next scheduled.
        // Today sits between installment 2 (overdue) and installment 3.
        // Expected:
        //   outstanding = 11 installments × 100 = 1100
        //   interest    = installment 2 (late, overdue) + installment 3
        //                 (upcoming) = 2 × 10 = 20
        //   installments 4-12 (> next upcoming) → excluded
        $schedules = [
            ['offset_days' => -60, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['offset_days' => -30, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'late'],
            ['offset_days' => 30,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'pending'],
        ];
        for ($i = 2; $i <= 10; $i++) {
            $schedules[] = [
                'offset_days' => 30 + ($i * 30),
                'principal' => '100.00', 'interest' => '10.00', 'status' => 'pending',
            ];
        }
        $loan = $this->makeLoan('1200.00', $schedules, 'late');

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('1100.00', $calc->principal,
            'Outstanding principal = all 11 unpaid installments × 100');
        $this->assertSame('20.00', $calc->interest,
            'Interest = overdue (installment 2) + next upcoming (installment 3)');
        $this->assertSame('1120.00', $calc->total);
    }

    // ═════════════════════════════════════════════════════════════════
    // Scenario 4 — default: ALL overdue (fallback branch)
    // ═════════════════════════════════════════════════════════════════

    public function test_default_all_overdue_includes_all_unpaid_interest(): void
    {
        // Loan in default. All 4 installments overdue (past due_dates).
        // No upcoming → fallback: boundary = LAST unpaid due_date →
        // filter matches ALL unpaid → ALL interest included.
        $loan = $this->makeLoan('400.00', [
            ['offset_days' => -120, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'late'],
            ['offset_days' => -90,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'late'],
            ['offset_days' => -60,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'late'],
            ['offset_days' => -30,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'late'],
        ], 'default');

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('400.00', $calc->principal);
        $this->assertSame('40.00', $calc->interest,
            'All-overdue fallback: LAST unpaid due_date as boundary → filter passes everything');
        $this->assertSame('440.00', $calc->total);
    }

    // ═════════════════════════════════════════════════════════════════
    // Scenario 5 — exactly-on-due-date boundary
    // ═════════════════════════════════════════════════════════════════

    public function test_exactly_on_due_date_is_the_boundary(): void
    {
        // Today == installment 2's due_date EXACTLY. Schedule-boundary
        // filter uses `<=` so installment 2 qualifies as "next upcoming".
        // Installment 3 (+30d) → excluded.
        $loan = $this->makeLoan('300.00', [
            ['offset_days' => -30, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['offset_days' => 0,   'principal' => '100.00', 'interest' => '10.00', 'status' => 'pending'],
            ['offset_days' => 30,  'principal' => '100.00', 'interest' => '10.00', 'status' => 'pending'],
        ]);

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('200.00', $calc->principal);
        $this->assertSame('10.00', $calc->interest,
            'due_date == today is INCLUSIVE (first unpaid with due >= today IS today)');
    }

    // ═════════════════════════════════════════════════════════════════
    // Scenario 6 — single investor
    // ═════════════════════════════════════════════════════════════════

    public function test_single_investor_receives_full_amount(): void
    {
        $loan = $this->makeLoan('200.00', [
            ['offset_days' => 15, 'principal' => '100.00', 'interest' => '10.00'],
            ['offset_days' => 45, 'principal' => '100.00', 'interest' => '10.00'],
        ]);
        $this->attachInvestors($loan, ['200.00']);
        $loan->refresh();

        $calc = $this->service->calculateTotal($loan);
        $dist = $this->service->distribute($loan, $calc);

        $this->assertCount(1, $dist);
        $this->assertSame('200.00', $dist[0]['principal']);
        $this->assertSame('10.00', $dist[0]['interest']);
        $this->assertSame('210.00', $dist[0]['total']);
    }

    // ═════════════════════════════════════════════════════════════════
    // Zero-total exception
    // ═════════════════════════════════════════════════════════════════

    public function test_zero_unpaid_schedules_throws(): void
    {
        $loan = $this->makeLoan('300.00', [
            ['offset_days' => -60, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['offset_days' => -30, 'principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['offset_days' => 0,   'principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no unpaid schedule items/');
        $this->service->calculateTotal($loan);
    }

    // ═════════════════════════════════════════════════════════════════
    // Last-investor-remainder penny precision
    // ═════════════════════════════════════════════════════════════════

    public function test_last_investor_receives_remainder_for_penny_precision(): void
    {
        // 100 total / 3 investors with equal share → 33.33 / 33.33 / 33.34
        $loan = $this->makeLoan('300.00', [
            ['offset_days' => 15, 'principal' => '100.00', 'interest' => '0.00'],
        ]);
        $this->attachInvestors($loan, ['100.00', '100.00', '100.00']);
        $loan->refresh();

        $calc = new EarlyRepaymentCalculation('100.00', '0.00', '100.00');
        $dist = $this->service->distribute($loan, $calc);

        // 100 / 3 @ scale 2 → first 2 truncate to 33.33, last absorbs 33.34
        $this->assertSame('33.33', $dist[0]['principal']);
        $this->assertSame('33.33', $dist[1]['principal']);
        $this->assertSame('33.34', $dist[2]['principal']);

        $sum = array_reduce(
            $dist,
            fn ($c, $d) => bcadd($c, $d['principal'], 2),
            '0.00',
        );
        $this->assertSame('100.00', $sum);
    }

    // ═════════════════════════════════════════════════════════════════
    // Multi-position investor groups to ONE distribution entry
    // ═════════════════════════════════════════════════════════════════

    public function test_investor_with_multiple_investments_gets_summed_share(): void
    {
        $loan = $this->makeLoan('300.00', [
            ['offset_days' => 15, 'principal' => '300.00', 'interest' => '0.00'],
        ]);

        $a = User::factory()->create();
        $b = User::factory()->create();
        Investment::create(['user_id' => $a->id, 'loan_id' => $loan->id, 'amount' => '100.00', 'invested_at' => now()]);
        Investment::create(['user_id' => $a->id, 'loan_id' => $loan->id, 'amount' => '50.00',  'invested_at' => now()]);
        Investment::create(['user_id' => $b->id, 'loan_id' => $loan->id, 'amount' => '150.00', 'invested_at' => now()]);

        $calc = new EarlyRepaymentCalculation('300.00', '0.00', '300.00');
        $dist = $this->service->distribute($loan, $calc);

        $this->assertCount(2, $dist, 'Investor with 2 positions → ONE consolidated entry');

        $userIds = array_column($dist, 'user_id');
        $this->assertContains($a->id, $userIds);
        $this->assertContains($b->id, $userIds);

        foreach ($dist as $d) {
            $this->assertSame('150.00', $d['principal'],
                '50/50 share (A: 100+50=150; B: 150) → each gets 50% of 300 = 150');
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // 24-installment no-drift
    // ═════════════════════════════════════════════════════════════════

    public function test_precision_no_drift_over_24_installments(): void
    {
        // 24 × 41.67 = 1000.08 summed via bcadd must be exact.
        // Installments 0-11 paid, 12-23 unpaid. Today sits between 11 and 12.
        $schedules = [];
        for ($i = 0; $i < 24; $i++) {
            $schedules[] = [
                'offset_days' => -330 + ($i * 30),   // 11 paid (-330..0), 13 unpaid (+30..)
                'principal' => '41.67', 'interest' => '8.33',
                'status' => $i < 11 ? 'paid' : 'pending',
            ];
        }
        $loan = $this->makeLoan('1000.08', $schedules);

        $calc = $this->service->calculateTotal($loan);

        // 13 unpaid installments × 41.67 with no precision drift
        $expected = '0.00';
        for ($i = 0; $i < 13; $i++) {
            $expected = bcadd($expected, '41.67', 2);
        }
        $this->assertSame($expected, $calc->principal);
    }
}
