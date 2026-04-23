<?php

namespace Tests\Feature\Loans;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\LoanWentLateNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase F1 — orchestrator command. Covers the contract every cron run
 * must satisfy: detection → status update → notifications → metrics,
 * with --dry-run, --loan, --force, and lock-based concurrency safety.
 */
class ProcessLateLoansCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Build a loan + investor in a setup that detection will mark late. */
    private function setupLateScenario(int $daysLate = 15, int $investorCount = 1, ?Carbon $today = null): Loan
    {
        $today = $today ?? Carbon::now()->startOfDay();
        $orig = Originator::create(['name' => 'F1 Cmd Test', 'description' => 'X', 'buyback' => false]);
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
        // Force-set status to active without going through the state machine
        // (no schedule auto-generation needed — we craft schedules manually).
        \DB::table('loans')->where('id', $loan->id)->update(['status' => 'active']);

        // One overdue pending schedule + future schedules.
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->subDays($daysLate),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => $today->copy()->addDays(30),
            'principal' => '170', 'interest' => '5', 'total' => '175', 'status' => 'pending',
        ]);

        // Investors
        for ($i = 0; $i < $investorCount; $i++) {
            $u = User::factory()->create(['email_verified_at' => now()]);
            Investment::create([
                'user_id' => $u->id, 'loan_id' => $loan->id,
                'amount' => '100', 'invested_at' => now(),
            ]);
        }

        return $loan->refresh();
    }

    public function test_happy_path_marks_schedule_late_transitions_loan_writes_event_and_queues_notifications(): void
    {
        Notification::fake();
        $loan = $this->setupLateScenario(daysLate: 15, investorCount: 2);

        $this->artisan('loans:process-late')->assertSuccessful();

        $loan->refresh();
        $this->assertSame('late', $loan->status);
        $this->assertNotNull($loan->became_late_at);

        $schedule = $loan->amortizationSchedules()->where('status', 'late')->first();
        $this->assertNotNull($schedule, 'schedule must be marked late');
        $this->assertSame(15, $schedule->days_late);

        $this->assertSame(1, $loan->events()->where('event_type', 'went_late')->count());

        Notification::assertSentTimes(LoanWentLateNotification::class, 2);
    }

    public function test_idempotent_three_consecutive_runs_produce_one_event_per_loan(): void
    {
        Notification::fake();
        $loan = $this->setupLateScenario();

        $this->artisan('loans:process-late')->assertSuccessful();
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->artisan('loans:process-late')->assertSuccessful();

        $this->assertSame(1, $loan->events()->where('event_type', 'went_late')->count(),
            'late detection must be idempotent across runs');
    }

    public function test_dry_run_writes_nothing_to_db(): void
    {
        Notification::fake();
        $loan = $this->setupLateScenario();

        $beforeMetrics = PlatformMetric::count();
        $beforeEvents = LoanEvent::count();
        $beforeNotifs = \DB::table('notifications')->count();

        $this->artisan('loans:process-late', ['--dry-run' => true])->assertSuccessful();

        $loan->refresh();
        $this->assertSame('active', $loan->status, 'dry-run must not transition loans');
        $this->assertNull($loan->became_late_at);
        $this->assertSame('pending',
            $loan->amortizationSchedules()->orderBy('due_date')->first()->status,
            'dry-run must not mark schedules late');

        $this->assertSame($beforeMetrics, PlatformMetric::count(), 'dry-run must not write metrics');
        $this->assertSame($beforeEvents, LoanEvent::count(), 'dry-run must not write loan_events');
        $this->assertSame($beforeNotifs, \DB::table('notifications')->count(), 'dry-run must not write notifications');

        Notification::assertNothingSent();
    }

    public function test_loan_filter_processes_only_targeted_loan(): void
    {
        Notification::fake();
        $targeted = $this->setupLateScenario();
        $untouched = $this->setupLateScenario();

        $this->artisan('loans:process-late', ['--loan' => $targeted->id])->assertSuccessful();

        $this->assertSame('late', $targeted->fresh()->status);
        $this->assertSame('active', $untouched->fresh()->status,
            'loans outside the --loan filter must not be touched');
    }

    public function test_force_bypasses_late_check_enabled(): void
    {
        Notification::fake();
        $loan = $this->setupLateScenario();
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);

        // Without --force the command exits with success but does nothing.
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame('active', $loan->fresh()->status, 'late_check_enabled=false must skip work');

        // With --force it proceeds.
        $this->artisan('loans:process-late', ['--force' => true])->assertSuccessful();
        $this->assertSame('late', $loan->fresh()->status, '--force must bypass the disabled flag');
    }

    public function test_cache_lock_blocks_concurrent_invocation(): void
    {
        Notification::fake();
        $this->setupLateScenario();

        // Pre-acquire the lock as if another process were already holding it.
        $other = Cache::lock('loans:process-late', 600);
        $this->assertTrue($other->get(), 'precondition: lock acquired');

        try {
            $this->artisan('loans:process-late')
                ->expectsOutputToContain('Another loans:process-late instance is already running')
                ->assertFailed();
        } finally {
            $other->release();
        }
    }

    public function test_metrics_written_after_successful_run(): void
    {
        Notification::fake();
        $this->setupLateScenario(investorCount: 3);

        $this->artisan('loans:process-late')->assertSuccessful();

        $this->assertSame('success', PlatformMetric::read('last_late_check_status'));
        $this->assertNotNull(PlatformMetric::measuredAt('last_late_check_run_at'));
        $this->assertSame('1', PlatformMetric::read('last_late_check_loans_to_late'));
        $this->assertSame('1', PlatformMetric::read('last_late_check_schedules_marked'));
        $this->assertSame('3', PlatformMetric::read('last_late_check_notifications_queued'));
        $this->assertSame('0', PlatformMetric::read('last_late_check_recovery_skipped_default'));
    }
}
