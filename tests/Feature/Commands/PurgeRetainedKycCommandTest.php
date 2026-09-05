<?php

namespace Tests\Feature\Commands;

use App\Models\AuditLog;
use App\Models\KycRetention;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\KycRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SEC-16: the 05:00 purge of KYC archives past their ЗМИП clock. */
class PurgeRetainedKycCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function archive(string $retainedUntil): KycRetention
    {
        $user = User::factory()->create();
        $dir = "kyc-retained/{$user->id}";
        Storage::disk('local')->put("{$dir}/front-a.jpg", 'front');
        Storage::disk('local')->put("{$dir}/selfie-b.jpg", 'selfie');

        return KycRetention::create([
            'user_id' => $user->id,
            'account_type' => 'individual',
            'kyc_status_at_deletion' => 'approved',
            'kyc_document_front_path' => "{$dir}/front-a.jpg",
            'kyc_document_back_path' => null,
            'kyc_selfie_path' => "{$dir}/selfie-b.jpg",
            'consent_snapshot' => [['type' => 'terms_of_service', 'version' => 'v1.2']],
            'subject_snapshot' => ['name' => 'Тест', 'email' => 'x@example.com'],
            'retention_years' => 5,
            'retained_until' => $retainedUntil,
        ]);
    }

    public function test_it_purges_only_archives_past_their_date_and_records_the_transition(): void
    {
        $due = $this->archive(today()->subDay()->toDateString());
        $future = $this->archive(today()->addDay()->toDateString());

        $this->artisan('kyc:purge-retained')->assertSuccessful();

        $purged = $due->fresh();
        $this->assertNotNull($purged->purged_at);
        $this->assertNull($purged->kyc_document_front_path);
        $this->assertNull($purged->consent_snapshot);
        $this->assertNull($purged->subject_snapshot);
        $this->assertNull($purged->purged_by, 'cron, not an admin');
        Storage::disk('local')->assertMissing(["kyc-retained/{$due->user_id}/front-a.jpg", "kyc-retained/{$due->user_id}/selfie-b.jpg"]);

        $kept = $future->fresh();
        $this->assertNull($kept->purged_at);
        Storage::disk('local')->assertExists("kyc-retained/{$future->user_id}/front-a.jpg");

        $audit = AuditLog::where('model_type', KycRetention::class)->where('model_id', $due->id)->where('action', 'updated')->firstOrFail();
        $this->assertArrayHasKey('purged_at', $audit->new_values);
        $this->assertSame('[REDACTED]', $audit->old_values['kyc_document_front_path']);
    }

    public function test_dry_run_and_kill_switch_delete_nothing(): void
    {
        $due = $this->archive(today()->subDay()->toDateString());

        $this->artisan('kyc:purge-retained', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($due->fresh()->purged_at);
        Storage::disk('local')->assertExists("kyc-retained/{$due->user_id}/front-a.jpg");

        PlatformSetting::set('kyc_retention_purge_enabled', false);
        $this->artisan('kyc:purge-retained')->assertSuccessful();
        $this->assertNull($due->fresh()->purged_at);
    }

    public function test_a_rerun_is_a_no_op_and_a_missing_file_is_tolerated(): void
    {
        $due = $this->archive(today()->subDay()->toDateString());
        Storage::disk('local')->delete("kyc-retained/{$due->user_id}/front-a.jpg");

        $this->artisan('kyc:purge-retained')->assertSuccessful();
        $purgedAt = $due->fresh()->purged_at;
        $this->assertNotNull($purgedAt);

        $this->artisan('kyc:purge-retained')->assertSuccessful();
        $this->assertEquals($purgedAt, $due->fresh()->purged_at);
        $this->assertFalse(app(KycRetentionService::class)->purge($due->fresh()), 'a purged row is never purged twice');
    }

    public function test_a_concurrent_run_is_refused_by_the_lock(): void
    {
        $due = $this->archive(today()->subDay()->toDateString());
        $lock = Cache::lock('kyc:purge-retained', 600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('kyc:purge-retained')->assertFailed();
            $this->assertNull($due->fresh()->purged_at);
        } finally {
            $lock->release();
        }
    }
}
