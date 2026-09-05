<?php

namespace Tests\Feature;

use App\Filament\Resources\KycRetentionResource\Pages\ListKycRetentions;
use App\Filament\Resources\KycRetentionResource\Pages\ViewKycRetention;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\KycRetention;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * SEC-16 (owner 2026-09-03): at closure the identity documents and consent
 * records move to a compliance-only archive with a ЗМИП clock.
 */
class KycRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: array<int, string>} */
    private function investorWithKyc(): array
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!'), 'phone' => '+359881234567']);
        $user->wallet()->create();
        $files = [
            UploadedFile::fake()->image('front.jpg')->store('kyc-documents', 'local'),
            UploadedFile::fake()->image('back.jpg')->store('kyc-documents', 'local'),
            UploadedFile::fake()->image('selfie.jpg')->store('kyc-documents', 'local'),
        ];
        $user->forceFill([
            'kyc_document_front_path' => $files[0],
            'kyc_document_back_path' => $files[1],
            'kyc_selfie_path' => $files[2],
        ])->save();
        foreach ([[ConsentRecord::TYPE_TERMS, 'v1.2'], [ConsentRecord::TYPE_PRIVACY, 'v1.1'], [ConsentRecord::TYPE_BIOMETRIC, 'v1.0']] as [$type, $version]) {
            $user->consentRecords()->create(['type' => $type, 'version' => $version, 'ip_address' => '203.0.113.9', 'user_agent' => 'test', 'accepted_at' => now()]);
        }

        return [$user, $files];
    }

    private function close(User $user): string
    {
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'Password123!');
        $service->confirm($user->fresh());
        Carbon::setTestNow(now()->addDays(8));

        return $service->finalize($user->fresh());
    }

    public function test_closure_copies_the_files_and_snapshots_the_consents_into_the_archive(): void
    {
        [$user, $files] = $this->investorWithKyc();
        $email = $user->email;

        $this->assertSame('finalized', $this->close($user));

        Storage::disk('local')->assertMissing($files);
        $row = KycRetention::where('user_id', $user->id)->firstOrFail();
        foreach (KycRetention::KIND_COLUMNS as $kind => $column) {
            $this->assertStringStartsWith("kyc-retained/{$user->id}/{$kind}-", $row->{$column});
            Storage::disk('local')->assertExists($row->{$column});
        }
        $this->assertSame(5, (int) $row->retention_years);
        $this->assertSame(today()->addYears(5)->toDateString(), $row->retained_until->toDateString());
        $this->assertSame('approved', $row->kyc_status_at_deletion);
        $this->assertCount(3, $row->consent_snapshot);
        $this->assertSame('v1.2', collect($row->consent_snapshot)->firstWhere('type', ConsentRecord::TYPE_TERMS)['version']);
        $this->assertSame($email, $row->subject_snapshot['email']);
        $this->assertSame(0, $user->consentRecords()->count());

        // Encrypted at rest: the raw column holds neither the IP nor the e-mail.
        $raw = DB::table('kyc_retentions')->where('id', $row->id)->first();
        $this->assertStringNotContainsString('203.0.113.9', (string) $raw->consent_snapshot);
        $this->assertStringNotContainsString($email, (string) $raw->subject_snapshot);
        // …and the audit row of the archive creation carries no snapshot ciphertext.
        $created = AuditLog::where('model_type', KycRetention::class)->where('model_id', $row->id)->where('action', 'created')->firstOrFail();
        $this->assertSame('[REDACTED]', $created->new_values['consent_snapshot']);
    }

    public function test_the_retention_clock_comes_from_the_setting_in_force_at_closure(): void
    {
        PlatformSetting::set('kyc_retention_years', 7);
        [$user] = $this->investorWithKyc();

        $this->close($user);

        $row = KycRetention::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(7, (int) $row->retention_years);
        $this->assertSame(today()->addYears(7)->toDateString(), $row->retained_until->toDateString());
    }

    public function test_an_account_without_documents_or_consents_leaves_no_archive(): void
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!')]);
        $user->wallet()->create();

        $this->assertSame('finalized', $this->close($user));

        $this->assertDatabaseMissing('kyc_retentions', ['user_id' => $user->id]);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc-retained'));
    }

    public function test_a_blocked_finalisation_leaves_no_partial_archive_and_keeps_the_originals(): void
    {
        [$user, $files] = $this->investorWithKyc();
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'Password123!');
        $service->confirm($user->fresh());
        app(WalletService::class)->credit($user->id, '50.00', Transaction::TYPE_DEPOSIT, 'late wire');
        Carbon::setTestNow(now()->addDays(8));

        $this->assertSame('blocked', $service->finalize($user->fresh()));

        Storage::disk('local')->assertExists($files);
        $this->assertDatabaseMissing('kyc_retentions', ['user_id' => $user->id]);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc-retained'));
        $this->assertSame(3, $user->consentRecords()->count());
    }

    public function test_the_retained_route_serves_admins_only_with_an_audit_row_and_no_store(): void
    {
        [$user] = $this->investorWithKyc();
        $this->close($user);
        $row = KycRetention::where('user_id', $user->id)->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get("/admin/kyc-retained/{$row->id}/front");
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $audit = AuditLog::where('action', 'viewed')->where('model_type', KycRetention::class)->latest('id')->firstOrFail();
        $this->assertEquals($row->id, $audit->model_id);
        $this->assertEquals($admin->id, $audit->user_id);
        $this->assertSame('kyc_front', $audit->new_values['document']);
        $this->assertEquals($user->id, $audit->new_values['subject_user_id']);
        $this->assertStringNotContainsString('front-', json_encode($audit->new_values), 'no path in the trail');

        $this->actingAs($admin)->get("/admin/kyc-retained/{$row->id}/passport")->assertNotFound();
        $this->actingAs($admin)->get('/admin/kyc-retained/999999/front')->assertNotFound();

        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $this->actingAs($investor)->get("/admin/kyc-retained/{$row->id}/front")->assertForbidden();

        // The legacy path-based route cannot reach the archive.
        $this->actingAs($admin)->get('/admin/kyc-document/../kyc-retained/'.$user->id.'/front-x.jpg')->assertForbidden();
    }

    public function test_a_row_whose_path_escapes_the_archive_directory_is_not_served(): void
    {
        [$user] = $this->investorWithKyc();
        $this->close($user);
        $row = KycRetention::where('user_id', $user->id)->firstOrFail();
        DB::table('kyc_retentions')->where('id', $row->id)->update(['kyc_document_front_path' => 'kyc-documents/other.jpg']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get("/admin/kyc-retained/{$row->id}/front")->assertNotFound();
        $this->assertSame(0, AuditLog::where('action', 'viewed')->where('model_type', KycRetention::class)->count());
    }

    public function test_archive_rows_are_immutable_except_for_the_purge_transition(): void
    {
        [$user] = $this->investorWithKyc();
        $this->close($user);
        $row = KycRetention::where('user_id', $user->id)->firstOrFail();
        $originalUntil = $row->retained_until->toDateString();

        try {
            $row->update(['retained_until' => today()->addYears(9)]);
            $this->fail('the clock must not be editable');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $row->fresh()->delete();
            $this->fail('archive rows are never deleted');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('kyc_retentions', ['id' => $row->id, 'retained_until' => $originalUntil]);
    }

    public function test_the_filament_archive_pages_are_admin_only_and_the_view_is_audited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        [$user] = $this->investorWithKyc();
        $this->close($user);
        $row = KycRetention::where('user_id', $user->id)->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(ListKycRetentions::class)->assertOk()->assertCanSeeTableRecords([$row]);

        Livewire::test(ViewKycRetention::class, ['record' => $row->id])->assertOk();
        $audit = AuditLog::where('action', 'viewed')->where('model_type', KycRetention::class)->latest('id')->firstOrFail();
        $this->assertEquals($row->id, $audit->model_id);
        $this->assertEquals($admin->id, $audit->user_id);
        $this->assertSame('kyc_retention_record', $audit->new_values['document']);
        $this->assertEquals($user->id, $audit->new_values['subject_user_id']);

        // ViewUser links to the archive only for closed accounts that have one.
        Livewire::test(ViewUser::class, ['record' => $user->id])->assertActionVisible('kyc_archive');
        $other = User::factory()->create();
        Livewire::test(ViewUser::class, ['record' => $other->id])->assertActionHidden('kyc_archive');
    }

    public function test_a_failure_after_the_copy_discards_the_partial_archive_and_keeps_the_originals(): void
    {
        [$user, $files] = $this->investorWithKyc();
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'Password123!');
        $service->confirm($user->fresh());
        Carbon::setTestNow(now()->addDays(8));

        // The anonymising write — AFTER retain() copied the files — blows up.
        User::saving(function (User $u): void {
            if ($u->isDirty('deletion_finalized_at')) {
                throw new RuntimeException('disk full');
            }
        });

        try {
            $service->finalize($user->fresh());
            $this->fail('the failure must propagate to the command');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        Storage::disk('local')->assertExists($files);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc-retained'), 'no orphan copies outside any row');
        $this->assertDatabaseMissing('kyc_retentions', ['user_id' => $user->id]);
        $this->assertSame('scheduled', $user->fresh()->deletionState(), 'still scheduled — retried next night');
        $this->assertSame(3, $user->consentRecords()->count());
    }
}
