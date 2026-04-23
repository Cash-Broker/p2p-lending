<?php

namespace Tests\Unit\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Services\Loans\BuybackCalculation;
use App\Services\Loans\BuybackCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F2 Step 2 — BuybackCalculationService: coverage math + pro-rata
 * distribution. Pure functions over loaded models — no DB writes.
 *
 * Tests assert exact bcmath strings (scale 2). Any float drift would
 * surface as string inequality.
 */
class BuybackCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BuybackCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BuybackCalculationService::class);
    }

    /**
     * Build a loan + originator + schedules. Caller passes the schedule
     * grid explicitly so each test controls exactly which installments
     * are paid, pending, late etc.
     *
     * @param  array<int, array{principal: string, interest: string, status?: string}>  $schedules
     */
    private function makeLoan(
        string $fundedAmount,
        string $coverage,
        array $schedules,
    ): Loan {
        $orig = Originator::create([
            'name' => 'F2 Calc Test ' . uniqid(),
            'description' => 'Test',
            'buyback' => true,
            'buyback_coverage' => $coverage,
            'buyback_trigger_days' => null,
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
            'status' => 'active',
        ]);

        foreach ($schedules as $i => $s) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'due_date' => now()->subMonths(count($schedules) - $i)->toDateString(),
                'principal' => $s['principal'],
                'interest' => $s['interest'],
                'total' => bcadd($s['principal'], $s['interest'], 2),
                'status' => $s['status'] ?? 'pending',
            ]);
        }

        return $loan;
    }

    /**
     * Attach investors to a loan. Each entry: [amount] — the user is
     * auto-created with a basic User::factory().
     *
     * @param  array<int, string>  $amounts
     * @return array<int, User>
     */
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

    public function test_calculates_principal_only_coverage(): void
    {
        // 3 installments × (100 principal + 10 interest). All pending → unpaid.
        // Coverage = principal_only → interest must be 0.00.
        $loan = $this->makeLoan('300.00', 'principal_only', [
            ['principal' => '100.00', 'interest' => '10.00'],
            ['principal' => '100.00', 'interest' => '10.00'],
            ['principal' => '100.00', 'interest' => '10.00'],
        ]);

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('principal_only', $calc->coverageType);
        $this->assertSame('300.00', $calc->principal);
        $this->assertSame('0.00', $calc->interest,
            'principal_only coverage MUST return zero interest even when schedules have interest');
        $this->assertSame('300.00', $calc->total);
    }

    public function test_calculates_principal_plus_interest_coverage(): void
    {
        // Same setup + principal_plus_interest coverage → both sums.
        $loan = $this->makeLoan('300.00', 'principal_plus_interest', [
            ['principal' => '100.00', 'interest' => '10.00'],
            ['principal' => '100.00', 'interest' => '10.00'],
            ['principal' => '100.00', 'interest' => '10.00'],
        ]);

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('principal_plus_interest', $calc->coverageType);
        $this->assertSame('300.00', $calc->principal);
        $this->assertSame('30.00', $calc->interest);
        $this->assertSame('330.00', $calc->total);
    }

    public function test_distributes_pro_rata_across_three_investors(): void
    {
        // Equal 1/3 shares (100/300 each). Because the service mirrors
        // RepaymentService's bcdiv(scale 10) → bcmul(scale 2) pattern,
        // the per-investor share of 300 ROUNDS DOWN to 99.99 (scale-2
        // truncation of 99.999…). The last-investor-remainder pattern
        // absorbs the cumulative residue — last investor gets 100.02 so
        // the sum equals 300.00 exactly.
        //
        // This test pins that pattern. If a future refactor switches to
        // cross-multiplication (bcdiv(bcmul(a,b,2), c, 2)) for exact-
        // division precision, the expected values here change to
        // 100/100/100 and the sum invariant still holds. Either way, the
        // sum == total contract is the binding invariant.
        $loan = $this->makeLoan('300.00', 'principal_plus_interest', [
            ['principal' => '100.00', 'interest' => '10.00'],
            ['principal' => '100.00', 'interest' => '10.00'],
            ['principal' => '100.00', 'interest' => '10.00'],
        ]);
        $this->attachInvestors($loan, ['100.00', '100.00', '100.00']);
        $loan->refresh();

        $calc = new BuybackCalculation('principal_plus_interest', '300.00', '30.00', '330.00');
        $dist = $this->service->distribute($loan, $calc);

        $this->assertCount(3, $dist);

        // First two investors: truncated share.
        $this->assertSame('99.99', $dist[0]['principal']);
        $this->assertSame('9.99', $dist[0]['interest']);
        $this->assertSame('99.99', $dist[1]['principal']);
        $this->assertSame('9.99', $dist[1]['interest']);

        // Last investor: absorbs the residue to guarantee sum == total.
        $this->assertSame('100.02', $dist[2]['principal']);
        $this->assertSame('10.02', $dist[2]['interest']);

        // Sum invariant — binding contract.
        $sumP = array_reduce($dist, fn ($c, $d) => bcadd($c, $d['principal'], 2), '0.00');
        $sumI = array_reduce($dist, fn ($c, $d) => bcadd($c, $d['interest'], 2), '0.00');
        $this->assertSame('300.00', $sumP);
        $this->assertSame('30.00', $sumI);
    }

    public function test_last_investor_receives_remainder_for_penny_precision(): void
    {
        // Classic 100/3 case: 33.33 + 33.33 + 33.34 = 100.00 exactly.
        // Without last-investor-remainder, naive floor math would produce
        // 33.33 × 3 = 99.99, losing a cent. Pinned by this test.
        $loan = $this->makeLoan('300.00', 'principal_only', [
            ['principal' => '100.00', 'interest' => '0.00'],
        ]);
        $this->attachInvestors($loan, ['100.00', '100.00', '100.00']);
        $loan->refresh();

        $calc = new BuybackCalculation('principal_only', '100.00', '0.00', '100.00');
        $dist = $this->service->distribute($loan, $calc);

        $this->assertSame('33.33', $dist[0]['principal']);
        $this->assertSame('33.33', $dist[1]['principal']);
        $this->assertSame('33.34', $dist[2]['principal'],
            'Last investor MUST absorb the rounding residue — guarantees sum == total');

        $sum = array_reduce(
            $dist,
            fn ($carry, $d) => bcadd($carry, $d['principal'], 2),
            '0.00',
        );
        $this->assertSame('100.00', $sum, 'Sum of distributed principals MUST equal total exactly');
    }

    public function test_single_investor_receives_full_amount(): void
    {
        // Single investor with 100% share — edge case where "last investor
        // gets remainder" collapses to "only investor gets everything".
        $loan = $this->makeLoan('200.00', 'principal_plus_interest', [
            ['principal' => '200.00', 'interest' => '24.00'],
        ]);
        $this->attachInvestors($loan, ['200.00']);
        $loan->refresh();

        $calc = new BuybackCalculation('principal_plus_interest', '200.00', '24.00', '224.00');
        $dist = $this->service->distribute($loan, $calc);

        $this->assertCount(1, $dist);
        $this->assertSame('200.00', $dist[0]['principal']);
        $this->assertSame('24.00', $dist[0]['interest']);
        $this->assertSame('224.00', $dist[0]['total']);
    }

    public function test_returns_zero_total_when_all_installments_paid(): void
    {
        // All 3 schedules marked paid → unpaid set is empty → total = 0.
        // Service doesn't throw; caller (BuybackExecutionService) rejects
        // zero-total with its own message.
        $loan = $this->makeLoan('300.00', 'principal_plus_interest', [
            ['principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
            ['principal' => '100.00', 'interest' => '10.00', 'status' => 'paid'],
        ]);

        $calc = $this->service->calculateTotal($loan);

        $this->assertSame('0.00', $calc->principal);
        $this->assertSame('0.00', $calc->interest);
        $this->assertSame('0.00', $calc->total);
    }

    public function test_precision_no_drift_over_24_installments(): void
    {
        // A realistic-ish loan grid: 24 installments. Principal sums must
        // exactly equal loan funded_amount, no floating-point drift across
        // 24 bcadd operations.
        $schedules = [];
        for ($i = 0; $i < 24; $i++) {
            $schedules[] = ['principal' => '41.67', 'interest' => '8.33'];
        }
        // 41.67 × 24 = 1000.08 (not exactly 1000 — Mimics realistic annuity
        // rounding in the AmortizationService where the LAST row absorbs
        // residue). Test asserts the service produces the EXACT sum of
        // schedule values with no precision loss.
        $loan = $this->makeLoan('1000.08', 'principal_only', $schedules);

        $calc = $this->service->calculateTotal($loan);

        // Expected: 41.67 × 24 = 1000.08 exactly (bcmath add, no float)
        $expected = '0.00';
        for ($i = 0; $i < 24; $i++) {
            $expected = bcadd($expected, '41.67', 2);
        }
        $this->assertSame($expected, $calc->principal,
            'sum of 24 × 41.67 must be calculated with zero drift at scale 2');
        $this->assertSame('1000.08', $calc->principal);
    }

    public function test_investor_with_multiple_investments_gets_summed_share(): void
    {
        // Investor A holds 2 Investment rows (100 + 50) = 150 → 50% share.
        // Investor B holds 1 row (150) = 50% share.
        // Expect 2 distributions (one per unique user_id), each at 50%.
        $loan = $this->makeLoan('300.00', 'principal_only', [
            ['principal' => '100.00', 'interest' => '0.00'],
            ['principal' => '100.00', 'interest' => '0.00'],
            ['principal' => '100.00', 'interest' => '0.00'],
        ]);

        $a = User::factory()->create();
        $b = User::factory()->create();
        Investment::create(['user_id' => $a->id, 'loan_id' => $loan->id, 'amount' => '100.00', 'invested_at' => now()]);
        Investment::create(['user_id' => $a->id, 'loan_id' => $loan->id, 'amount' => '50.00', 'invested_at' => now()]);
        Investment::create(['user_id' => $b->id, 'loan_id' => $loan->id, 'amount' => '150.00', 'invested_at' => now()]);

        $calc = new BuybackCalculation('principal_only', '300.00', '0.00', '300.00');
        $dist = $this->service->distribute($loan, $calc);

        $this->assertCount(2, $dist,
            'Investor holding multiple positions must receive ONE consolidated distribution, not one per position');

        $userIds = array_column($dist, 'user_id');
        $this->assertContains($a->id, $userIds);
        $this->assertContains($b->id, $userIds);

        // Each gets 50% of 300.00 = 150.00
        foreach ($dist as $d) {
            $this->assertSame('150.00', $d['principal']);
        }
    }
}
