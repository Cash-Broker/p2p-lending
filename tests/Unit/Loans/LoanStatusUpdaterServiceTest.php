<?php

namespace Tests\Unit\Loans;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Services\Loans\LoanStatusUpdaterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F1 Step 2 — recovery rule R1 + tiebreaker. Mirrors the 3
 * scenarios the user pinned in Step 2 approval, plus the safeguards
 * (no-history skip, default-status skip).
 */
class LoanStatusUpdaterServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLoan(string $status = 'active', ?Carbon $becameLateAt = null): Loan
    {
        $orig = Originator::create(['name' => 'F1 Status Test', 'description' => 'X', 'buyback' => false]);
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
        \DB::table('loans')->where('id', $loan->id)->update([
            'status' => $status,
            'became_late_at' => $becameLateAt,
        ]);
        return $loan->refresh();
    }

    private function addSchedule(Loan $loan, array $attrs): AmortizationSchedule
    {
        return AmortizationSchedule::create(array_merge([
            'loan_id' => $loan->id,
            'principal' => '170', 'interest' => '5', 'total' => '175',
        ], $attrs));
    }

    // ─────────────────────────────────────────────────────────────────
    // 3 PINNED SCENARIOS FROM STEP 2 APPROVAL
    // ─────────────────────────────────────────────────────────────────

    public function test_partial_recovery_three_late_one_paid_two_remain_stays_late(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeLoan('late', $today->copy()->subDays(80));

        // 3 originally-late items: 1 now paid, 2 still status='late'.
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(90),
            'status' => 'paid',
            'became_late_at' => $today->copy()->subDays(80),
            'paid_at' => $today->copy()->subDays(78),
            'days_late' => 80,
        ]);
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(60),
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(50),
            'days_late' => 50,
        ]);
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(30),
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(20),
            'days_late' => 20,
        ]);

        $result = app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck();

        $loan->refresh();
        $this->assertSame('late', $loan->status, 'still has late items → must stay late');
        $this->assertSame([], $result['recovered_to_active']);
        $this->assertSame([], $result['recovered_to_repaid']);
        $this->assertSame(0, $loan->events()->count(), 'no transition → no event');
    }

    public function test_full_recovery_to_active_all_late_paid_other_pending(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeLoan('late', $today->copy()->subDays(110));

        // 2 previously-late, both paid AFTER becoming late.
        foreach ([120, 90] as $i => $daysAgo) {
            $this->addSchedule($loan, [
                'due_date' => $today->copy()->subDays($daysAgo),
                'status' => 'paid',
                'became_late_at' => $today->copy()->subDays($daysAgo - 10),
                'paid_at' => $today->copy()->subDays($daysAgo - 30),
                'days_late' => $daysAgo - 10,
            ]);
        }
        // 4 future schedules still pending.
        foreach ([10, 40, 70, 100] as $futureDay) {
            $this->addSchedule($loan, [
                'due_date' => $today->copy()->addDays($futureDay),
                'status' => 'pending',
            ]);
        }

        $result = app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck();

        $loan->refresh();
        $this->assertSame('active', $loan->status);
        $this->assertNull($loan->became_late_at, 'recovery must clear became_late_at');
        $this->assertContains($loan->id, $result['recovered_to_active']);

        $event = $loan->events()->latest('id')->first();
        $this->assertSame(LoanEvent::TYPE_RECOVERED_FROM_LATE, $event->event_type);
        $this->assertSame('active', $event->to_status);
        $this->assertNotEmpty($event->metadata['previous_became_late_at'], 'metadata must preserve previous_became_late_at for audit');
    }

    public function test_full_recovery_to_repaid_when_all_schedules_paid(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeLoan('late', $today->copy()->subDays(110));

        // All 3 schedules paid; some had been late, all paid AFTER becoming late.
        foreach ([120, 90, 60] as $daysAgo) {
            $this->addSchedule($loan, [
                'due_date' => $today->copy()->subDays($daysAgo),
                'status' => 'paid',
                'became_late_at' => $today->copy()->subDays($daysAgo - 10),
                'paid_at' => $today->copy()->subDays($daysAgo - 25),
                'days_late' => $daysAgo - 10,
            ]);
        }

        $result = app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck();

        $loan->refresh();
        $this->assertSame('repaid', $loan->status, 'tiebreaker: all schedules paid → repaid not active');
        $this->assertContains($loan->id, $result['recovered_to_repaid']);

        $event = $loan->events()->latest('id')->first();
        $this->assertSame('repaid', $event->to_status);
        $this->assertSame('repaid', $event->metadata['transitioned_to']);
    }

    // ─────────────────────────────────────────────────────────────────
    // SAFEGUARDS
    // ─────────────────────────────────────────────────────────────────

    public function test_no_history_guard_skips_late_loan_without_any_became_late_at(): void
    {
        // Admin manually marked loan late without any schedule ever going late.
        // The R1 part-0 guard must skip — admin's decision is preserved.
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeLoan('late', null);
        $this->addSchedule($loan, ['due_date' => $today->copy()->addDays(30), 'status' => 'pending']);
        $this->addSchedule($loan, ['due_date' => $today->copy()->addDays(60), 'status' => 'pending']);

        $result = app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck();

        $loan->refresh();
        $this->assertSame('late', $loan->status, 'no became_late_at history → admin-set, skip recovery');
        $this->assertSame([], $result['recovered_to_active']);
        $this->assertSame([], $result['recovered_to_repaid']);
    }

    public function test_default_status_safeguard_skips_recovery_and_buckets(): void
    {
        // Loan has been recovered-eligible by all R1 criteria EXCEPT it has
        // a 'default' schedule. Service must skip and report.
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeLoan('late', $today->copy()->subDays(50));
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(60),
            'status' => 'paid',
            'became_late_at' => $today->copy()->subDays(50),
            'paid_at' => $today->copy()->subDays(40),
            'days_late' => 50,
        ]);
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(30),
            'status' => 'default',  // admin or future F2 marked this default
            'became_late_at' => $today->copy()->subDays(20),
            'days_late' => 20,
        ]);

        $result = app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck();

        $loan->refresh();
        $this->assertSame('late', $loan->status, 'default schedule must block auto-recovery');
        $this->assertContains($loan->id, $result['recovery_skipped_default']);
        $this->assertSame(0, $loan->events()->count(), 'no event written for skipped recovery');
    }

    public function test_active_to_late_writes_went_late_event_with_days_late_at_transition(): void
    {
        $today = Carbon::create(2026, 4, 23);
        $loan = $this->makeLoan('active');
        // A late schedule (status already 'late' as if LateDetectionService ran).
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(20),
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(10),
            'days_late' => 18,
        ]);
        $this->addSchedule($loan, [
            'due_date' => $today->copy()->subDays(50),
            'status' => 'late',
            'became_late_at' => $today->copy()->subDays(40),
            'days_late' => 42,
        ]);

        app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck();

        $loan->refresh();
        $this->assertSame('late', $loan->status);
        $event = $loan->events()->where('event_type', 'went_late')->first();
        $this->assertNotNull($event);
        $this->assertSame(2, $event->metadata['late_schedule_count']);
        $this->assertSame(42, $event->metadata['days_late_at_transition'],
            'days_late_at_transition must equal MAX days_late across late schedules');
    }
}
