<?php

namespace Tests\Feature\Commands;

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
use App\Notifications\BuybackEligibleAdminNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase F2 Step 3 — loans:detect-buyback-eligible command.
 *
 * Covers the full contract every cron run must honour: detection,
 * flagging, loan_event write, admin digest, metrics — under each of
 * --dry-run, --loan, --force paths and the cache-lock concurrency
 * guard.
 */
class DetectBuybackEligibleCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a late loan that WILL match the eligibility query:
     * became_late_at is 70 days back (beyond the 60-day platform default).
     */
    private function makeEligibleLoan(array $loanAttrs = [], array $origAttrs = []): Loan
    {
        $orig = Originator::create(array_merge([
            'name' => 'F2 CMD Test ' . uniqid(),
            'description' => 'X',
            'buyback' => true,
        ], $origAttrs));
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A',
            'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);
        $loan = Loan::create(array_merge([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000', 'funded_amount' => '1000',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 6, 'type' => 'consumer',
            'status' => 'late',
            'became_late_at' => now()->subDays(70),
        ], $loanAttrs));

        // One pending schedule so the calculator has something to compute.
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => now()->subDays(70)->toDateString(),
            'principal' => '100', 'interest' => '10', 'total' => '110',
            'status' => 'pending',
        ]);

        return $loan->fresh();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_happy_path_detects_flags_and_queues_digest(): void
    {
        Notification::fake();
        $loan = $this->makeEligibleLoan();
        $admin = $this->makeAdmin();

        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();

        // Flagged on the loan
        $this->assertNotNull($loan->fresh()->buyback_eligible_at,
            'Cron must set buyback_eligible_at on the eligible loan');

        // LoanEvent written per Q22 (both-null status pair, metadata includes originator_id)
        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_BUYBACK_TRIGGERED)
            ->first();
        $this->assertNotNull($event);
        $this->assertNull($event->from_status);
        $this->assertNull($event->to_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_SYSTEM, $event->triggered_by);
        $this->assertArrayHasKey('eligible_at', $event->metadata);
        $this->assertArrayHasKey('days_since_became_late', $event->metadata);
        $this->assertArrayHasKey('calculated_buyback_amount_at_detection', $event->metadata);
        $this->assertArrayHasKey('coverage_type', $event->metadata);
        $this->assertArrayHasKey('originator_id', $event->metadata);
        $this->assertSame($loan->originator_id, $event->metadata['originator_id']);

        // Admin digest queued
        Notification::assertSentTo($admin, BuybackEligibleAdminNotification::class);
    }

    public function test_second_run_is_idempotent_no_new_flags_or_events(): void
    {
        Notification::fake();
        $loan = $this->makeEligibleLoan();
        $this->makeAdmin();

        // First run — flags the loan.
        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();
        $firstFlaggedAt = $loan->fresh()->buyback_eligible_at;
        $firstEventCount = LoanEvent::count();

        // Second run — loan already flagged, so nothing changes.
        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();

        $this->assertSame(
            $firstFlaggedAt->toDateTimeString(),
            $loan->fresh()->buyback_eligible_at->toDateTimeString(),
            'buyback_eligible_at MUST remain the original timestamp — not updated on idempotent re-run',
        );
        $this->assertSame($firstEventCount, LoanEvent::count(),
            'No new loan_event on idempotent re-run');
    }

    public function test_dry_run_produces_zero_writes(): void
    {
        Notification::fake();
        $loan = $this->makeEligibleLoan();
        $this->makeAdmin();

        $preEventCount = LoanEvent::count();
        $preMetricCount = PlatformMetric::count();

        $this->artisan('loans:detect-buyback-eligible --dry-run --detail')->assertSuccessful();

        // Loan NOT flagged
        $this->assertNull($loan->fresh()->buyback_eligible_at);
        // No new LoanEvent
        $this->assertSame($preEventCount, LoanEvent::count());
        // No new platform_metric rows
        $this->assertSame($preMetricCount, PlatformMetric::count());
        // No notifications actually dispatched
        Notification::assertNothingSent();
    }

    public function test_loan_filter_scopes_to_single_id(): void
    {
        Notification::fake();
        $loanA = $this->makeEligibleLoan();
        $loanB = $this->makeEligibleLoan();
        $this->makeAdmin();

        $this->artisan('loans:detect-buyback-eligible', ['--loan' => $loanA->id])->assertSuccessful();

        $this->assertNotNull($loanA->fresh()->buyback_eligible_at);
        $this->assertNull($loanB->fresh()->buyback_eligible_at,
            '--loan=ID must scope to that loan only');
    }

    public function test_force_bypasses_kill_switch(): void
    {
        Notification::fake();
        $loan = $this->makeEligibleLoan();
        $this->makeAdmin();

        // Turn off the kill switch
        PlatformSetting::where('key', 'buyback_check_enabled')->update(['value' => 'false']);

        // Without --force: short-circuits, loan NOT flagged
        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();
        $this->assertNull($loan->fresh()->buyback_eligible_at);

        // With --force: runs anyway
        $this->artisan('loans:detect-buyback-eligible --force')->assertSuccessful();
        $this->assertNotNull($loan->fresh()->buyback_eligible_at);
    }

    public function test_cache_lock_prevents_concurrent_runs(): void
    {
        // Pre-acquire the lock to simulate a concurrent run. The second
        // command must exit with FAILURE and NOT touch the loan.
        $lock = Cache::lock('loans:detect-buyback-eligible', 600);
        $this->assertTrue($lock->get(), 'test pre-condition: acquire the lock');

        $loan = $this->makeEligibleLoan();

        $this->artisan('loans:detect-buyback-eligible')->assertFailed();
        $this->assertNull($loan->fresh()->buyback_eligible_at);

        $lock->release();
    }

    public function test_metrics_written_on_success(): void
    {
        Notification::fake();
        $this->makeEligibleLoan();
        $this->makeAdmin();

        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();

        $this->assertNotNull(PlatformMetric::read('last_buyback_check_run_at'));
        $this->assertSame('success', PlatformMetric::read('last_buyback_check_status'));
        $this->assertSame('1', PlatformMetric::read('last_buyback_check_loans_newly_eligible'));
        $this->assertSame('1', PlatformMetric::read('last_buyback_check_notifications_queued'));
        $this->assertSame('true', PlatformMetric::read('last_buyback_check_enabled'));
    }

    public function test_digest_skipped_when_zero_newly_and_zero_waiting(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        // No eligible loans at all.

        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();

        Notification::assertNotSentTo($admin, BuybackEligibleAdminNotification::class);
        $this->assertSame('0', PlatformMetric::read('last_buyback_check_notifications_queued'));
    }

    public function test_digest_sent_when_zero_newly_but_waiting_over_3_days(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        // Old loan flagged 5 days ago, still pending in queue.
        $stale = $this->makeEligibleLoan([
            'buyback_eligible_at' => now()->subDays(5),
        ]);

        // New potentially-eligible loan (70d late) but already flagged yesterday,
        // so this run detects 0 new. But there's 1 waiting > 3 days.
        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();

        Notification::assertSentTo($admin, BuybackEligibleAdminNotification::class,
            fn ($n) => $n->newlyEligibleCount === 0 && $n->waitingMoreThan3DaysCount === 1);
    }

    public function test_admin_digest_contains_correct_counts_and_breakdown(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        // 2 newly eligible (detected this run) + 1 old pending (> 3 days).
        $this->makeEligibleLoan();
        $this->makeEligibleLoan();
        $this->makeEligibleLoan(['buyback_eligible_at' => now()->subDays(5)]);

        $this->artisan('loans:detect-buyback-eligible')->assertSuccessful();

        Notification::assertSentTo($admin, BuybackEligibleAdminNotification::class,
            fn (BuybackEligibleAdminNotification $n) =>
                $n->newlyEligibleCount === 2
                && $n->waitingMoreThan3DaysCount === 1
                && count($n->loanIds) === 2,
        );
    }
}
