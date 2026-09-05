<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\SavedIban;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use Database\Factories\BorrowerAnonymizedProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

class PreLaunchFixesTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    private function createVerifiedInvestor(array $walletBalances = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletBalances) {
            $wallet->forceFill($walletBalances)->save();
        }

        return $user;
    }

    // ── Policy enforcement ──

    public function test_portfolio_requires_investor_role(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $admin->wallet()->create();

        $response = $this->actingAs($admin)->getJson('/api/portfolio');
        $response->assertStatus(403);
    }

    public function test_transactions_scoped_to_own_user(): void
    {
        $investor = $this->createVerifiedInvestor();
        $otherInvestor = $this->createVerifiedInvestor();

        Transaction::factory()->create(['user_id' => $otherInvestor->id, 'type' => 'deposit', 'amount' => 1000]);
        Transaction::factory()->create(['user_id' => $investor->id, 'type' => 'deposit', 'amount' => 500]);

        $response = $this->actingAs($investor)->getJson('/api/transactions');
        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_cannot_delete_other_users_iban_via_policy(): void
    {
        $investor = $this->createVerifiedInvestor();
        $otherInvestor = $this->createVerifiedInvestor();

        $iban = SavedIban::create([
            'user_id' => $otherInvestor->id,
            'iban' => 'BG80BNBG96611020345678',
            'label' => 'Test',
        ]);

        $response = $this->actingAs($investor)->deleteJson("/api/profile/ibans/{$iban->id}");
        $response->assertStatus(403);
    }

    public function test_dashboard_requires_investor(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $admin->wallet()->create();

        $response = $this->actingAs($admin)->getJson('/api/dashboard');
        $response->assertStatus(403);
    }

    public function test_wallet_endpoint_scoped_to_own(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '1234.56']);

        $response = $this->actingAs($investor)->getJson('/api/wallet');
        $response->assertOk()
            ->assertJsonPath('available', '1234.56');
    }

    public function test_withdrawal_create_requires_investor(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $admin->wallet()->create();

        $response = $this->actingAs($admin)->postJson('/api/withdrawal', [
            'amount' => 100,
            'saved_iban_id' => $this->confirmedIban($admin)->id,
        ]);

        // Admin should be blocked by 'investor' middleware before reaching policy
        $response->assertStatus(403);
    }

    // ── Idempotency race condition ──

    public function test_idempotency_unique_constraint_returns_existing(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '5000.00']);
        $originator = Originator::factory()->create();
        $borrower = Borrower::factory()->create();
        $borrower->anonymizedProfile()->create(BorrowerAnonymizedProfileFactory::new()->definition());

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'amount' => '10000.00',
            'funded_amount' => '0.00',
            'status' => Loan::STATUS_PUBLISHED,
        ]);

        $service = app(InvestmentService::class);
        $key = 'race-test-'.uniqid();

        // First investment succeeds
        $investment1 = $service->invest($investor, $loan, '500.00', $key);
        $this->assertNotNull($investment1->id);

        // Simulate race: create same key directly (what DB constraint would catch)
        // The service should catch UniqueConstraintViolation and return existing
        $investment2 = $service->invest($investor, $loan->fresh(), '500.00', $key);
        $this->assertEquals($investment1->id, $investment2->id);

        // Only one investment should exist
        $this->assertEquals(1, Investment::where('idempotency_key', $key)->count());
    }

    // ── Rate limiting ──

    public function test_invest_endpoint_is_rate_limited(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '99999.00']);
        $originator = Originator::factory()->create();
        $borrower = Borrower::factory()->create();
        $borrower->anonymizedProfile()->create(BorrowerAnonymizedProfileFactory::new()->definition());

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'amount' => '999999.00',
            'funded_amount' => '0.00',
            'status' => Loan::STATUS_PUBLISHED,
        ]);

        // Send 11 requests (limit is 10 per minute)
        $lastResponse = null;
        for ($i = 0; $i < 11; $i++) {
            $lastResponse = $this->actingAs($investor)->postJson("/api/loans/{$loan->id}/invest", [
                'amount' => 50,
            ], ['X-Idempotency-Key' => 'rate-limit-'.$i]);
        }

        // The 11th request should be rate limited
        $lastResponse->assertStatus(429);
    }

    public function test_withdrawal_endpoint_is_rate_limited(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '99999.00']);

        $lastResponse = null;
        for ($i = 0; $i < 6; $i++) {
            $lastResponse = $this->actingAs($investor)->postJson('/api/withdrawal', [
                'amount' => 10,
                'saved_iban_id' => $this->confirmedIban($investor)->id,
            ]);
        }

        // The 6th request should be rate limited (limit is 5 per minute)
        $lastResponse->assertStatus(429);
    }

    // ── Notification controller ──

    public function test_mark_nonexistent_notification_returns_404(): void
    {
        $investor = $this->createVerifiedInvestor();

        $response = $this->actingAs($investor)->postJson('/api/notifications/nonexistent-uuid/read');
        $response->assertStatus(404);
    }

    public function test_delete_nonexistent_notification_returns_404(): void
    {
        $investor = $this->createVerifiedInvestor();

        $response = $this->actingAs($investor)->deleteJson('/api/notifications/nonexistent-uuid');
        $response->assertStatus(404);
    }

    // ── Email verification ──

    public function test_email_verification_rejects_legacy_sha1_hash(): void
    {
        // The sha1(email) fallback was dead code (removed — see LOW-2 fix).
        // Even with a valid signed URL, a sha1-hashed link must now be rejected
        // because the hash check is HMAC-SHA256 only.
        $user = User::factory()->create(['email_verified_at' => null]);

        $sha1Hash = sha1($user->getEmailForVerification());

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => $sha1Hash]
        );

        $response = $this->get($url);
        $response->assertStatus(403);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_works_with_new_hmac_hash(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        // Generate new HMAC hash
        $hmacHash = hash_hmac('sha256', $user->getEmailForVerification(), config('app.key'));

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => $hmacHash]
        );

        $response = $this->get($url);
        $response->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_fails_with_wrong_hash(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'completely-wrong-hash']
        );

        $response = $this->get($url);
        $response->assertStatus(403);
    }
}
