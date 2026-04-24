<?php

namespace Tests\Audit;

use App\Models\Borrower;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AmortizationService;
use App\Services\APRCalculatorService;
use App\Services\Loans\BuybackCalculation;
use App\Services\Loans\BuybackCalculationService;
use App\Services\Loans\EarlyRepaymentCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 2 — Financial Correctness Audit harness.
 *
 * Data-driven comparison between the Python oracle at
 * `audit/reference_calculator.py` and the platform's production
 * services. Fixture at `audit/fixtures/phase2_cases.json` is loaded
 * once and each case becomes an individual data-provider test method.
 *
 * Opt-in only: `php artisan test --testsuite=Audit`. NOT in default
 * CI runs. See AUDIT_REPORT_PHASE2.md §7 for the commands reference.
 *
 * Every assertion is an EXACT string comparison (zero drift tolerance
 * per audit policy). A static counter tracks total comparisons and
 * failures for the cumulative report section.
 *
 * Test isolation: `RefreshDatabase` is used per test method — each
 * data-provider case gets a clean DB, creates its own fixtures, and
 * tears down cleanly.
 */
class Phase2FinancialCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private static int $totalAssertions = 0;
    private static int $totalMismatches = 0;
    private static array $mismatchLog = [];

    public static function casesProvider(): array
    {
        $path = __DIR__ . '/../../audit/fixtures/phase2_cases.json';
        if (! file_exists($path)) {
            throw new \RuntimeException("Fixture not found: {$path} (run `python audit/reference_calculator.py --generate` first)");
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $providers = [];
        foreach ($data['cases'] as $case) {
            $providers[$case['case_id']] = [$case];
        }
        return $providers;
    }

    #[DataProvider('casesProvider')]
    public function test_fixture_case_matches_platform(array $case): void
    {
        match ($case['kind']) {
            'amortization'    => $this->assertAmortization($case),
            'prorata'         => $this->assertProrata($case),
            'buyback'         => $this->assertBuyback($case),
            'early_repayment' => $this->assertEarlyRepayment($case),
            'apr'             => $this->assertApr($case),
            default           => $this->fail("Unknown case kind: {$case['kind']}"),
        };
    }

    public static function tearDownAfterClass(): void
    {
        $pass = self::$totalAssertions - self::$totalMismatches;
        fwrite(STDERR, "\n=== Phase 2 Audit — Cumulative Summary ===\n");
        fwrite(STDERR, sprintf("Total comparisons: %d\n", self::$totalAssertions));
        fwrite(STDERR, sprintf("Matched exactly:   %d\n", $pass));
        fwrite(STDERR, sprintf("Mismatches:        %d\n", self::$totalMismatches));
        if (self::$totalMismatches > 0) {
            fwrite(STDERR, "\nFirst 10 mismatches:\n");
            foreach (array_slice(self::$mismatchLog, 0, 10) as $m) {
                fwrite(STDERR, "  - {$m}\n");
            }
        }
        fwrite(STDERR, "==========================================\n\n");
        parent::tearDownAfterClass();
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-kind assertion dispatchers.
    // ──────────────────────────────────────────────────────────────────

    private function assertAmortization(array $case): void
    {
        $input = $case['input'];
        $expected = $case['expected'];

        $loan = $this->makeLoanForSchedule(
            amount: $input['amount'],
            interestRate: $input['interest_rate'],
            termMonths: (int) $input['term_months'],
        );

        app(AmortizationService::class)->generateSchedule($loan);

        $rows = $loan->amortizationSchedules()
            ->orderBy('due_date')
            ->get(['principal', 'interest', 'total']);

        $this->recordAssert(
            $case['case_id'],
            'row_count',
            (string) count($expected['schedule']),
            (string) $rows->count(),
        );

        foreach ($expected['schedule'] as $i => $expRow) {
            $actual = $rows[$i] ?? null;
            if (! $actual) {
                $this->recordMismatch($case['case_id'], "row {$i} missing from platform output");
                $this->fail("row {$i} missing");
            }
            $this->recordAssert($case['case_id'], "row{$i}.principal", $expRow['principal'], (string) $actual->principal);
            $this->recordAssert($case['case_id'], "row{$i}.interest",  $expRow['interest'],  (string) $actual->interest);
            $this->recordAssert($case['case_id'], "row{$i}.total",     $expRow['total'],     (string) $actual->total);
        }

        // Invariants — exact Σ principal == amount
        $principalSum = $rows->reduce(
            fn ($c, $r) => bcadd($c, (string) $r->principal, 2),
            '0.00',
        );
        $this->recordAssert($case['case_id'], 'principal_sum', $expected['principal_sum'], $principalSum);
    }

    private function assertProrata(array $case): void
    {
        $input = $case['input'];
        $expected = $case['expected'];

        $investorAmounts = $input['investor_amounts'];
        $total = $input['total'];

        // The fixture's `investor_amounts` are the SHARE BASIS — each
        // investor's investment.amount. The platform computes pro-rata
        // with denominator = loan.funded_amount, which in a real loan
        // equals Σ investments.amount. We must materialise the test
        // state the same way: funded_amount = Σ investor_amounts,
        // NOT fixture total (which is a separately-distributed amount,
        // e.g. a buyback total not necessarily equal to funded_amount).
        $fundedAmount = array_reduce(
            $investorAmounts,
            fn ($carry, $a) => bcadd($carry, (string) $a, 2),
            '0.00',
        );

        $loan = $this->makeLoanForInvestments(
            fundedAmount: $fundedAmount,
        );

        foreach ($investorAmounts as $amount) {
            $investor = $this->makeInvestor();
            Investment::create([
                'user_id'          => $investor->id,
                'loan_id'          => $loan->id,
                'amount'           => $amount,
                'invested_at'      => now(),
                'idempotency_key'  => (string) \Illuminate\Support\Str::uuid(),
            ]);
        }

        // Use BuybackCalculationService::distribute with a synthetic
        // BuybackCalculation where principal=total and interest=0.00.
        // The service's principal distribution == the abstract pro-rata
        // we want to test. Interest is 0 for everyone (noop).
        $calc = new BuybackCalculation(
            coverageType: BuybackCalculationService::COVERAGE_PRINCIPAL_ONLY,
            principal: (string) $total,
            interest: '0.00',
            total: (string) $total,
        );

        $result = app(BuybackCalculationService::class)->distribute($loan, $calc);

        // Extract principal shares in investment-creation order.
        $platformDistributions = array_map(fn ($r) => $r['principal'], $result);

        $this->recordAssert(
            $case['case_id'],
            'distribution_count',
            (string) count($expected['distributions']),
            (string) count($platformDistributions),
        );

        foreach ($expected['distributions'] as $i => $expDist) {
            $this->recordAssert(
                $case['case_id'],
                "dist[{$i}]",
                $expDist,
                (string) ($platformDistributions[$i] ?? 'MISSING'),
            );
        }

        // Invariant — Σ distributions == total
        $sum = array_reduce($platformDistributions, fn ($c, $d) => bcadd($c, $d, 2), '0.00');
        $this->recordAssert($case['case_id'], 'sum_distributions', $expected['sum_distributions'], $sum);
    }

    private function assertBuyback(array $case): void
    {
        $input = $case['input'];
        $expected = $case['expected'];
        $sf = $input['schedule_from'];

        $loan = $this->makeLoanForSchedule(
            amount: $sf['amount'],
            interestRate: $sf['interest_rate'],
            termMonths: (int) $sf['term_months'],
        );
        $loan->originator->update(['buyback_coverage' => $input['coverage']]);
        app(AmortizationService::class)->generateSchedule($loan);

        // Mark specified indices as paid.
        $this->markScheduleStatus($loan, $input['paid_indices'], 'paid');

        $calc = app(BuybackCalculationService::class)->calculateTotal($loan->fresh());

        $this->recordAssert($case['case_id'], 'coverage',         $expected['coverage'],          $calc->coverageType);
        $this->recordAssert($case['case_id'], 'unpaid_principal', $expected['unpaid_principal'],  $calc->principal);
        $this->recordAssert($case['case_id'], 'unpaid_interest',  $expected['unpaid_interest'],   $calc->interest);
        $this->recordAssert($case['case_id'], 'total',            $expected['total'],             $calc->total);
    }

    private function assertEarlyRepayment(array $case): void
    {
        $input = $case['input'];
        $expected = $case['expected'];
        $sf = $input['schedule_from'];

        $loan = $this->makeLoanForSchedule(
            amount: $sf['amount'],
            interestRate: $sf['interest_rate'],
            termMonths: (int) $sf['term_months'],
        );
        app(AmortizationService::class)->generateSchedule($loan);

        // Mark paid first (status='paid' → excluded from unpaid filter).
        $this->markScheduleStatus($loan, $input['paid_indices'], 'paid');

        // Late: past due_date + status='late'. We move these rows' due_date
        // BEFORE today so the service's "next upcoming" picker correctly
        // identifies the boundary as a later-indexed row.
        $lateIndices = $input['late_indices'];
        $boundaryIndex = (int) $input['boundary_index'];

        if (! empty($lateIndices)) {
            $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
            $pastAnchor = Carbon::now()->subDays(30);
            foreach ($lateIndices as $offset => $idx) {
                $schedule = $schedules[$idx];
                $schedule->update([
                    'status'   => 'late',
                    'due_date' => $pastAnchor->copy()->addDays($offset), // preserve ordering
                ]);
            }

            // Push the boundary schedule to TODAY (the first "upcoming"
            // unpaid — due_date >= today per service). This triggers the
            // late-interest-inclusion path in the service's filter
            // (due_date <= boundary.due_date includes the late rows).
            $schedules[$boundaryIndex]->update([
                'due_date' => Carbon::now()->startOfDay(),
            ]);
        }

        $calc = app(EarlyRepaymentCalculationService::class)->calculateTotal($loan->fresh());

        $this->recordAssert($case['case_id'], 'outstanding_principal', $expected['outstanding_principal'], $calc->principal);
        $this->recordAssert($case['case_id'], 'unpaid_interest',       $expected['unpaid_interest'],       $calc->interest);
        $this->recordAssert($case['case_id'], 'total',                 $expected['total'],                 $calc->total);
    }

    private function assertApr(array $case): void
    {
        $input = $case['input'];
        $expected = $case['expected']['apr'];

        // APRCalculatorService operates on a Loan instance; build an
        // in-memory (not saved) Loan with the target rate.
        $loan = new Loan();
        $loan->interest_rate_annual = $input['interest_rate_annual'];

        $actual = app(APRCalculatorService::class)->calculate($loan);

        // Expected is either a string (e.g. "10.50") or null. Both sides
        // must match exactly.
        $this->recordAssert(
            $case['case_id'],
            'apr',
            $expected === null ? '__NULL__' : $expected,
            $actual === null ? '__NULL__' : $actual,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Fixture builders.
    // ──────────────────────────────────────────────────────────────────

    private function makeLoanForSchedule(string $amount, string $interestRate, int $termMonths): Loan
    {
        $originator = Originator::create([
            'name' => 'Audit-' . uniqid(),
            'description' => 'Phase 2 audit fixture',
            'buyback' => true,
        ]);
        $borrower = Borrower::factory()->create();

        return Loan::create([
            'originator_id'        => $originator->id,
            'borrower_id'          => $borrower->id,
            'amount'               => $amount,
            'funded_amount'        => 0,
            'interest_rate'        => $interestRate,
            'interest_rate_annual' => bcadd((string) $interestRate, '2.00', 2),
            'term_months'          => $termMonths,
            'type'                 => 'consumer',
            'status'               => 'draft',
        ]);
    }

    private function makeLoanForInvestments(string $fundedAmount): Loan
    {
        $originator = Originator::create([
            'name' => 'Audit-Prorata-' . uniqid(),
            'description' => 'Phase 2 audit prorata fixture',
            'buyback' => true,
        ]);
        $borrower = Borrower::factory()->create();

        return Loan::create([
            'originator_id'        => $originator->id,
            'borrower_id'          => $borrower->id,
            'amount'               => $fundedAmount,
            'funded_amount'        => $fundedAmount,
            'interest_rate'        => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months'          => 12,
            'type'                 => 'consumer',
            'status'               => 'funding',
        ]);
    }

    private function makeInvestor(): User
    {
        static $counter = 0;
        $counter++;
        $user = User::factory()->kycApproved()->create([
            'email' => "audit-investor-{$counter}-" . uniqid() . "@test.local",
        ]);
        Wallet::create(['user_id' => $user->id, 'available' => 0, 'invested' => 0, 'earned' => 0, 'reserved' => 0]);
        return $user;
    }

    private function markScheduleStatus(Loan $loan, array $indices, string $status): void
    {
        if (empty($indices)) {
            return;
        }
        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
        foreach ($indices as $idx) {
            $schedules[$idx]->update(['status' => $status]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Assertion bookkeeping — exact-string with cumulative tally.
    // ──────────────────────────────────────────────────────────────────

    private function recordAssert(string $caseId, string $field, string $expected, string $actual): void
    {
        self::$totalAssertions++;
        if ($expected !== $actual) {
            self::$totalMismatches++;
            self::$mismatchLog[] = "{$caseId}::{$field}  expected='{$expected}'  actual='{$actual}'";
        }
        $this->assertSame(
            $expected,
            $actual,
            "CASE={$caseId} FIELD={$field} expected={$expected} actual={$actual}",
        );
    }

    private function recordMismatch(string $caseId, string $message): void
    {
        self::$totalMismatches++;
        self::$mismatchLog[] = "{$caseId}::{$message}";
    }
}
