<?php

namespace Tests\Feature\Commands;

use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SEC-22: the 04:30 sweep that finalises confirmed, due deletion requests. */
class FinalizeAccountDeletionsTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedInvestor(?string $scheduledFor): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!')]);
        $user->wallet()->create();
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'Password123!');
        if ($scheduledFor !== null) {
            $service->confirm($user->fresh());
            DB::table('users')->where('id', $user->id)->update(['deletion_scheduled_for' => $scheduledFor]);
        }

        return $user->fresh();
    }

    public function test_the_command_finalises_only_confirmed_requests_that_are_due(): void
    {
        $due = $this->confirmedInvestor(now()->subHour());
        $notYet = $this->confirmedInvestor(now()->addDays(3));
        $awaiting = $this->confirmedInvestor(null);

        $this->artisan('accounts:finalize-deletions')->assertSuccessful();

        $this->assertSame('finalized', $due->fresh()->deletionState());
        $this->assertSame('scheduled', $notYet->fresh()->deletionState());
        $this->assertSame('awaiting_confirmation', $awaiting->fresh()->deletionState());
        $this->assertSame('ok', PlatformMetric::read('last_account_deletions_status'));
        $this->assertNotNull(PlatformMetric::measuredAt('last_account_deletions_run_at'));

        $this->getJson('/api/health/scheduler')->assertJsonStructure(['account_deletions' => ['last_run_at', 'last_run_stats']]);
    }

    public function test_the_kill_switch_and_the_dry_run_touch_nothing(): void
    {
        $due = $this->confirmedInvestor(now()->subHour());

        $this->artisan('accounts:finalize-deletions', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('scheduled', $due->fresh()->deletionState());

        PlatformSetting::set('account_deletion_finalize_enabled', false);
        $this->artisan('accounts:finalize-deletions')->assertSuccessful();
        $this->assertSame('scheduled', $due->fresh()->deletionState());
        $this->assertSame('disabled', PlatformMetric::read('last_account_deletions_status'));
    }

    public function test_one_failing_account_does_not_stop_the_sweep_and_the_run_reports_failure(): void
    {
        $broken = $this->confirmedInvestor(now()->subHour());
        $fine = $this->confirmedInvestor(now()->subHour());

        $this->mock(AccountDeletionService::class, function ($mock) use ($broken, $fine) {
            $mock->shouldReceive('finalize')->withArgs(fn (User $u) => $u->id === $broken->id)->andThrow(new \RuntimeException('disk full'));
            $mock->shouldReceive('finalize')->withArgs(fn (User $u) => $u->id === $fine->id)->andReturn('finalized');
        });

        $this->artisan('accounts:finalize-deletions')->assertFailed();
        $this->assertSame('failure', PlatformMetric::read('last_account_deletions_status'));
    }
}
