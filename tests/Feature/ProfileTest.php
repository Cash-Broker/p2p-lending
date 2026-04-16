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

    public function test_kyc_submit_rejects_svg_file(): void
    {
        Storage::fake('local');
        $user = $this->createVerifiedInvestor();

        // SVG with embedded XSS payload — must be rejected.
        $svgContent = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" '
                    . 'onload="alert(document.domain)"><script>alert(1)</script></svg>';
        $svg = UploadedFile::fake()->createWithContent('id-card.svg', $svgContent);

        $response = $this->actingAs($user)->postJson('/api/profile/kyc', [
            'document' => $svg,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document');
        $this->assertEquals('pending', $user->fresh()->kyc_status);
        $this->assertNull($user->fresh()->kyc_document_path);
    }

    public function test_kyc_submit_rejects_php_file(): void
    {
        Storage::fake('local');
        $user = $this->createVerifiedInvestor();

        // PHP webshell — extension not in allow-list, must be rejected.
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php phpinfo(); ?>');

        $response = $this->actingAs($user)->postJson('/api/profile/kyc', [
            'document' => $php,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_kyc_submit_rejects_html_file(): void
    {
        Storage::fake('local');
        $user = $this->createVerifiedInvestor();

        // HTML with embedded script — extension not in allow-list, must be rejected.
        $html = UploadedFile::fake()->createWithContent('xss.html', '<script>alert(1)</script>');

        $response = $this->actingAs($user)->postJson('/api/profile/kyc', [
            'document' => $html,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_kyc_submit_accepts_pdf(): void
    {
        Storage::fake('local');
        $user = $this->createVerifiedInvestor();

        // Minimal valid PDF with proper magic bytes — Laravel's mimes: rule reads
        // headers via finfo, dummy `create()` bytes won't pass.
        $pdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
                    . "2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\n"
                    . "xref\n0 3\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n"
                    . "trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n110\n%%EOF\n";
        $pdf = UploadedFile::fake()->createWithContent('id-card.pdf', $pdfContent);

        $response = $this->actingAs($user)->postJson('/api/profile/kyc', [
            'document' => $pdf,
        ]);

        $response->assertOk();
        $this->assertEquals('submitted', $user->fresh()->kyc_status);
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

    // ── Account deletion (GDPR) ──

    public function test_delete_account_anonymizes_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
        ]);
        $user->wallet()->create();
        $user->savedIbans()->create(['iban' => 'BG80BNBG96611020345678']);

        $response = $this->actingAs($user)->postJson('/api/profile/delete', [
            'password' => 'Password123!',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertStringStartsWith('Изтрит потребител', $user->name);
        $this->assertStringContainsString('deleted_', $user->email);
        $this->assertNull($user->phone);
        $this->assertNull($user->wallet);
        $this->assertEquals(0, $user->savedIbans()->count());
    }

    public function test_delete_account_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
        ]);
        $user->wallet()->create();

        $response = $this->actingAs($user)->postJson('/api/profile/delete', [
            'password' => 'WrongPass!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_delete_account_fails_with_active_investments(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
        ]);
        $wallet = $user->wallet()->create();
        $wallet->forceFill(['invested' => 1000])->save();

        $response = $this->actingAs($user)->postJson('/api/profile/delete', [
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('account');
    }

    public function test_delete_account_fails_with_remaining_balance(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
        ]);
        $wallet = $user->wallet()->create();
        $wallet->forceFill(['available' => 500])->save();

        $response = $this->actingAs($user)->postJson('/api/profile/delete', [
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('account');
    }

    public function test_delete_account_preserves_transaction_history(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
        ]);
        $user->wallet()->create();
        \App\Models\Transaction::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/profile/delete', [
            'password' => 'Password123!',
        ]);

        // Transaction record preserved even after account deletion
        $this->assertDatabaseHas('transactions', ['user_id' => $user->id]);
    }
}
