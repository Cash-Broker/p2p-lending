<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\ConsentRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    // ── Consent tracking ──

    public function test_registration_creates_consent_records(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Consent User',
            'email' => 'consent@test.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ]);

        $user = User::where('email', 'consent@test.com')->first();

        $this->assertCount(3, $user->consentRecords);

        $types = $user->consentRecords->pluck('type')->sort()->values()->toArray();
        $this->assertEquals([
            ConsentRecord::TYPE_PRIVACY,
            ConsentRecord::TYPE_RISK,
            ConsentRecord::TYPE_TERMS,
        ], $types);
    }

    public function test_consent_records_capture_ip_and_user_agent(): void
    {
        $this->postJson('/api/register', [
            'name' => 'IP User',
            'email' => 'ip@test.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ]);

        $consent = ConsentRecord::first();
        $this->assertNotNull($consent->ip_address);
        $this->assertNotNull($consent->user_agent);
        $this->assertNotNull($consent->accepted_at);
    }

    public function test_registration_fails_without_terms_accepted(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'No Terms',
            'email' => 'noterms@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_registration_fails_with_terms_false(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'No Terms',
            'email' => 'noterms@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');
    }

    // ── Audit logging ──

    public function test_audit_log_created_on_user_registration(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Audit User',
            'email' => 'audit@test.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ]);

        $log = AuditLog::where('model_type', User::class)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('audit@test.com', $log->new_values['email']);
    }

    public function test_audit_log_redacts_password(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Redact User',
            'email' => 'redact@test.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ]);

        $log = AuditLog::where('model_type', User::class)
            ->where('action', 'created')
            ->first();

        $this->assertEquals('[REDACTED]', $log->new_values['password']);
    }

    public function test_audit_log_created_on_wallet_update(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create();

        $wallet->forceFill(['available' => 100.00])->save();

        $log = AuditLog::where('model_type', \App\Models\Wallet::class)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
    }

    // ── PII encryption ──

    public function test_borrower_pii_is_encrypted_in_database(): void
    {
        $borrower = Borrower::factory()->create([
            'full_name' => 'Иван Петров',
            'address' => 'ул. Граф Игнатиев 1',
            'phone' => '+359888123456',
        ]);

        // Raw DB values should NOT be plaintext
        $raw = \DB::table('borrowers')->where('id', $borrower->id)->first();
        $this->assertNotEquals('Иван Петров', $raw->full_name);
        $this->assertNotEquals('ул. Граф Игнатиев 1', $raw->address);
        $this->assertNotEquals('+359888123456', $raw->phone);

        // But model accessor decrypts correctly
        $fresh = $borrower->fresh();
        $this->assertEquals('Иван Петров', $fresh->full_name);
        $this->assertEquals('ул. Граф Игнатиев 1', $fresh->address);
        $this->assertEquals('+359888123456', $fresh->phone);
    }

    public function test_iban_is_encrypted_in_database(): void
    {
        $withdrawal = WithdrawalRequest::factory()->create([
            'iban' => 'BG80BNBG96611020345678',
        ]);

        $raw = \DB::table('withdrawal_requests')->where('id', $withdrawal->id)->value('iban');
        $this->assertNotEquals('BG80BNBG96611020345678', $raw);

        $this->assertEquals('BG80BNBG96611020345678', $withdrawal->fresh()->iban);
    }

    // ── KYC enforcement ──

    public function test_kyc_middleware_blocks_non_approved_users(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'kyc_status' => 'pending',
        ]);

        // Currently no endpoints behind 'kyc' middleware are active,
        // but we test the middleware directly
        $middleware = new \App\Http\Middleware\EnsureKycApproved();

        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['requires_kyc']);
    }

    public function test_kyc_middleware_allows_approved_users(): void
    {
        $user = User::factory()->kycApproved()->create();

        $middleware = new \App\Http\Middleware\EnsureKycApproved();

        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }
}
