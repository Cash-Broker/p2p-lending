<?php

namespace Tests\Unit\Services;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Services\Loans\BuybackEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F2 Step 2 — BuybackEligibilityService query filters + fallback
 * resolvers. Isolated from the cron command; tests only what the service
 * returns given various loan/originator states.
 */
class BuybackEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private BuybackEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BuybackEligibilityService::class);
    }

    /**
     * Make a late loan + originator with default eligibility (70 days late,
     * originator has buyback=true, no per-loan/per-originator overrides).
     * Caller can override any attribute via the two arrays.
     */
    private function makeLateLoan(array $loanAttrs = [], array $origAttrs = []): Loan
    {
        $orig = Originator::create(array_merge([
            'name' => 'F2 Eligibility Test Originator ' . uniqid(),
            'description' => 'Test',
            'buyback' => true,
            'buyback_coverage' => null,
            'buyback_trigger_days' => null,
        ], $origAttrs));

        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A',
            'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);

        return Loan::create(array_merge([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000', 'funded_amount' => '1000',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 6, 'type' => 'consumer',
            'status' => 'late',
            'became_late_at' => now()->subDays(70),
        ], $loanAttrs));
    }

    public function test_includes_late_and_default_loans_past_trigger_threshold(): void
    {
        $late = $this->makeLateLoan();
        $default = $this->makeLateLoan(['status' => 'default']);

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(2, $result);
        $this->assertEqualsCanonicalizing(
            [$late->id, $default->id],
            $result->pluck('id')->all(),
        );
    }

    public function test_excludes_loans_with_buyback_eligible_at_already_set(): void
    {
        $this->makeLateLoan(['buyback_eligible_at' => now()->subHour()]);

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(0, $result,
            'Loan already flagged in a prior run must NOT be re-surfaced (idempotency)');
    }

    public function test_excludes_dismissed_loans(): void
    {
        $this->makeLateLoan([
            'buyback_dismissed_at' => now()->subHour(),
            'buyback_dismissed_reason' => 'originator paying next week',
        ]);

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(0, $result,
            'Admin-dismissed loans are skipped until reactivated');
    }

    public function test_excludes_bought_back_loans(): void
    {
        $this->makeLateLoan([
            'status' => 'bought_back',
            'bought_back_at' => now(),
        ]);

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(0, $result,
            'Terminal bought_back state excluded by both status filter AND bought_back_at');
    }

    public function test_excludes_loans_with_originator_buyback_false(): void
    {
        $this->makeLateLoan([], ['buyback' => false]);

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(0, $result,
            'Master switch — originator.buyback=false → never detected regardless of config');
    }

    public function test_applies_per_originator_trigger_days_override(): void
    {
        // Originator has 30-day override; loan 45 days late → eligible
        $this->makeLateLoan(
            ['became_late_at' => now()->subDays(45)],
            ['buyback_trigger_days' => 30],
        );

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(1, $result,
            '30-day-trigger originator + 45-day-late loan should match');
    }

    public function test_falls_back_to_platform_default_trigger_days(): void
    {
        // No per-originator override → platform default (60); loan 50 days late → NOT eligible
        $this->makeLateLoan(
            ['became_late_at' => now()->subDays(50)],
            ['buyback_trigger_days' => null],
        );

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(0, $result,
            '50-day-late loan must NOT cross the 60-day platform default threshold');
    }

    public function test_zero_trigger_days_is_valid_and_not_treated_as_default_fallback(): void
    {
        // Zero = "eligible immediately once late". Loan 1 day late → should be detected.
        // This pins the ?? (not ??|| or isset()) null-coalescing contract.
        $this->makeLateLoan(
            ['became_late_at' => now()->subDay()],
            ['buyback_trigger_days' => 0],
        );

        $result = $this->service->detectNewlyEligible(Carbon::now()->startOfDay());

        $this->assertCount(1, $result,
            'buyback_trigger_days = 0 must be treated as "zero-grace", not as "null fallback to default"');
    }
}
