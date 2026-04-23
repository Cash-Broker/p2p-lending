<?php

namespace Tests\Unit\Loans;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\PlatformSetting;
use App\Services\Loans\LateDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F1 Step 2 — late detection. Grace-period boundaries are the
 * highest-impact contract here; one off-by-one and an investor gets a
 * "you're late" email a day too early.
 */
class LateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveLoan(): Loan
    {
        $orig = Originator::create(['name' => 'F1 Detect Test', 'description' => 'X', 'buyback' => false]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A', 'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);
        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000', 'funded_amount' => '1000',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 6, 'type' => 'consumer', 'status' => 'draft',
        ]);
        \DB::table('loans')->where('id', $loan->id)->update(['status' => 'active']);
        return $loan->refresh();
    }

    public function test_grace_period_boundary_due_today_minus_grace_minus_one_is_not_late(): void
    {
        $today = Carbon::create(2026, 4, 23);
        PlatformSetting::where('key', 'grace_period_days')->update(['value' => '10']);
        $loan = $this->makeActiveLoan();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            // 9 days past due — within grace.
            'due_date' => $today->copy()->subDays(9),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today);
        $this->assertCount(0, $newlyLate, '9 days past due (grace=10) must NOT be marked late');
    }

    public function test_grace_period_boundary_due_today_minus_grace_exactly_is_late(): void
    {
        $today = Carbon::create(2026, 4, 23);
        PlatformSetting::where('key', 'grace_period_days')->update(['value' => '10']);
        $loan = $this->makeActiveLoan();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(10),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today);
        $this->assertCount(1, $newlyLate, 'exactly 10 days past due (grace=10) must be marked late');
    }

    public function test_grace_period_zero_marks_anything_past_due(): void
    {
        $today = Carbon::create(2026, 4, 23);
        PlatformSetting::where('key', 'grace_period_days')->update(['value' => '0']);
        $loan = $this->makeActiveLoan();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDay(),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today);
        $this->assertCount(1, $newlyLate);
    }

    public function test_grace_period_thirty_days(): void
    {
        $today = Carbon::create(2026, 4, 23);
        PlatformSetting::where('key', 'grace_period_days')->update(['value' => '30']);
        $loan = $this->makeActiveLoan();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(29),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(35),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today);
        $this->assertCount(1, $newlyLate, 'only the 35-day-overdue one (>= grace 30) is late');
    }

    public function test_paid_schedules_are_not_marked_late(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeActiveLoan();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(60),
            'principal' => '170', 'interest' => '5', 'total' => '175',
            'status' => 'paid', 'paid_at' => $today->copy()->subDays(50),
        ]);

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today);
        $this->assertCount(0, $newlyLate, 'paid schedules must not be re-marked late');
    }

    public function test_already_late_schedules_are_not_re_processed(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeActiveLoan();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(60),
            'principal' => '170', 'interest' => '5', 'total' => '175',
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(40),
            'days_late' => 40,
        ]);

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today);
        $this->assertCount(0, $newlyLate, 'detection scans status=pending only');
    }

    public function test_loan_id_filter_restricts_detection_scope(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $a = $this->makeActiveLoan();
        $b = $this->makeActiveLoan();
        foreach ([$a, $b] as $loan) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'due_date' => $today->copy()->subDays(20),
                'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
            ]);
        }

        $newlyLate = app(LateDetectionService::class)->detectNewlyLateSchedules($today, [$a->id]);
        $this->assertCount(1, $newlyLate);
        $this->assertSame($a->id, $newlyLate->first()->loan_id);
    }

    public function test_refresh_days_late_snapshots_updates_only_changed_rows(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeActiveLoan();
        $stale = AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(20),
            'principal' => '170', 'interest' => '5', 'total' => '175',
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(15),
            'days_late' => 15, // STALE — should be 20 today
        ]);
        $fresh = AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays(10),
            'principal' => '170', 'interest' => '5', 'total' => '175',
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(5),
            'days_late' => 10, // already accurate
        ]);

        $updated = app(LateDetectionService::class)->refreshDaysLateSnapshots($today);
        $this->assertSame(1, $updated, 'only the stale row is rewritten');
        $this->assertSame(20, $stale->fresh()->days_late);
        $this->assertSame(10, $fresh->fresh()->days_late);
    }
}
