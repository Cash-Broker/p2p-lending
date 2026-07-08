<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminKycReviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function submittedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'kyc_status' => 'submitted',
        ]);
    }

    // ── In-panel inbox: new KYC submissions notify the reviewers ──

    public function test_kyc_submission_sends_database_notification_to_admins(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $investor = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'pending']);
        $investor->consentRecords()->create([
            'type' => 'terms', 'version' => \App\Models\ConsentRecord::CURRENT_TERMS_VERSION,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'accepted_at' => now(),
        ]);
        $investor->consentRecords()->create([
            'type' => 'privacy', 'version' => \App\Models\ConsentRecord::CURRENT_PRIVACY_VERSION,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'accepted_at' => now(),
        ]);

        $this->actingAs($investor)->postJson('/api/profile/kyc', [
            'document_front' => UploadedFile::fake()->image('id-front.jpg', 800, 600),
            'document_back' => UploadedFile::fake()->image('id-back.jpg', 800, 600),
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
            'biometric_consent' => '1',
        ])->assertOk();

        $this->assertSame(1, $admin->notifications()->count());
        $data = $admin->notifications()->first()->data;
        $this->assertSame('Нова KYC заявка', $data['title']);
        $this->assertStringContainsString($investor->name, $data['body']);
        // Filament's inbox only renders its own format.
        $this->assertSame('filament', $data['format']);

        // Regular investors must NOT receive reviewer notifications.
        $this->assertSame(0, $investor->notifications()->where('data->title', 'Нова KYC заявка')->count());
    }

    // ── The users table stays clean: only the View action ──

    public function test_users_table_has_no_kyc_status_actions(): void
    {
        $this->actingAs($this->admin());
        $user = $this->submittedUser();

        Livewire::test(ListUsers::class)
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('review_kyc')
            ->assertTableActionDoesNotExist('approve_kyc')
            ->assertTableActionDoesNotExist('reject_kyc');
    }

    // ── Status actions: on the profile view page header ──

    public function test_admin_can_mark_submission_in_review_from_view_page(): void
    {
        $this->actingAs($this->admin());
        $user = $this->submittedUser();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('review_kyc')
            ->assertHasNoErrors();

        $this->assertSame('in_review', $user->fresh()->kyc_status);
    }

    public function test_admin_can_approve_directly_from_in_review(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'in_review']);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('approve_kyc')
            ->assertHasNoErrors();

        $this->assertSame('approved', $user->fresh()->kyc_status);
    }

    public function test_review_action_is_hidden_for_non_submitted_users(): void
    {
        $this->actingAs($this->admin());
        $pending = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'pending']);

        Livewire::test(ViewUser::class, ['record' => $pending->id])
            ->assertActionHidden('review_kyc');
    }

    public function test_admin_can_approve_from_the_user_view_page(): void
    {
        $this->actingAs($this->admin());
        $user = $this->submittedUser();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('approve_kyc')
            ->assertHasNoErrors();

        $this->assertSame('approved', $user->fresh()->kyc_status);
    }

    public function test_admin_can_reject_from_the_user_view_page(): void
    {
        $this->actingAs($this->admin());
        $user = $this->submittedUser();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('reject_kyc')
            ->assertHasNoErrors();

        $this->assertSame('rejected', $user->fresh()->kyc_status);
    }
}
