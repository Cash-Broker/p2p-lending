<?php

namespace Tests\Feature;

use App\Models\SavedIban;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        return $user;
    }

    // ── GET profile ──

    public function test_get_profile_returns_user_data(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('name', $user->name)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('kyc_status', 'pending');
    }

    public function test_unauthenticated_cannot_access_profile(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    // ── PUT profile ──

    public function test_update_profile_name_and_phone(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'Нов Име',
            'phone' => '+359888123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Нов Име')
            ->assertJsonPath('user.phone', '+359888123456');

        $this->assertEquals('Нов Име', $user->fresh()->name);
        $this->assertEquals('+359888123456', $user->fresh()->phone);
    }

    // ── Change password ──

    public function test_change_password_success(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('OldPass123!'),
        ]);
        $user->wallet()->create();

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'OldPass123!',
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Password changed successfully.']);
    }

    public function test_change_password_fails_wrong_current(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('OldPass123!'),
        ]);
        $user->wallet()->create();

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'WrongPass!',
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    // ── KYC ──

    public function test_kyc_submit_uploads_document(): void
    {
        Storage::fake('local');
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->postJson('/api/profile/kyc', [
            'document' => UploadedFile::fake()->image('id-card.jpg', 800, 600),
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'KYC document submitted successfully.']);

        $this->assertEquals('submitted', $user->fresh()->kyc_status);
        Storage::disk('local')->assertExists($user->fresh()->kyc_document_path);
    }

    public function test_kyc_submit_fails_without_file(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->postJson('/api/profile/kyc', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    // ── IBANs ──

    public function test_list_saved_ibans(): void
    {
        $user = $this->createVerifiedInvestor();
        $user->savedIbans()->create(['iban' => 'BG80BNBG96611020345678', 'label' => 'Основна']);

        $response = $this->actingAs($user)->getJson('/api/profile/ibans');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Основна', $response->json('data.0.label'));
    }

    public function test_add_iban(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->postJson('/api/profile/ibans', [
            'iban' => 'BG80BNBG96611020345678',
            'label' => 'Спестовна',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('saved_ibans', ['user_id' => $user->id]);
    }

    public function test_delete_iban(): void
    {
        $user = $this->createVerifiedInvestor();
        $iban = $user->savedIbans()->create(['iban' => 'BG80BNBG96611020345678']);

        $response = $this->actingAs($user)->deleteJson("/api/profile/ibans/{$iban->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('saved_ibans', ['id' => $iban->id]);
    }

    public function test_cannot_delete_other_users_iban(): void
    {
        $user1 = $this->createVerifiedInvestor();
        $user2 = $this->createVerifiedInvestor();
        $iban = $user2->savedIbans()->create(['iban' => 'BG80BNBG96611020345678']);

        $response = $this->actingAs($user1)->deleteJson("/api/profile/ibans/{$iban->id}");

        $response->assertStatus(403);
    }
}
