<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $walletOverrides = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletOverrides) {
            $wallet->forceFill($walletOverrides)->save();
        }
        return $user;
    }

    // ── /api/deposit endpoint ──

    public function test_deposit_info_returns_reference_code_and_bank_details(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/deposit');

        $response->assertOk()
            ->assertJsonStructure([
                'reference_code',
                'expires_at',
                'bank_details' => ['bank_name', 'iban', 'bic', 'beneficiary'],
            ]);

        // Code is the new random DEP-XXXXXXXX format (replaces sequential
        // P2P-{user_id}, audit C2 fix). 8 alphanumeric chars uppercase.
        $this->assertMatchesRegularExpression(
            '/^DEP-[A-Z0-9]{8}$/',
            $response->json('reference_code'),
        );
    }

    public function test_deposit_codes_differ_per_user(): void
    {
        $user1 = $this->createVerifiedInvestor();
        $user2 = $this->createVerifiedInvestor();

        $ref1 = $this->actingAs($user1)->getJson('/api/deposit')->json('reference_code');
        $ref2 = $this->actingAs($user2)->getJson('/api/deposit')->json('reference_code');

        $this->assertNotEquals($ref1, $ref2);
    }

    public function test_deposit_returns_same_code_on_repeated_calls(): void
    {
        // Idempotency contract: while the active code hasn't expired or been
        // approved/rejected, /api/deposit returns the SAME code. The user
        // should never see a different code mid-wire (would break their
        // bank reference paste).
        $user = $this->createVerifiedInvestor();

        $first = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');
        $second = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');
        $third = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');

        $this->assertEquals($first, $second);
        $this->assertEquals($first, $third);

        // Only one DepositRequest row was created — no orphan placeholder spam.
        $this->assertEquals(1, DepositRequest::where('user_id', $user->id)->count());
    }

    public function test_deposit_mints_new_code_after_expiry(): void
    {
        $user = $this->createVerifiedInvestor();

        $first = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');

        // Force expiry on the existing code
        DepositRequest::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

        $second = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');

        $this->assertNotEquals($first, $second);
        $this->assertEquals(2, DepositRequest::where('user_id', $user->id)->count());
    }

    public function test_deposit_mints_new_code_after_previous_was_approved(): void
    {
        // Once a code has been used (approved or rejected), the next visit
        // mints a fresh one — admin can't accidentally double-credit a
        // closed code.
        $user = $this->createVerifiedInvestor();

        $first = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');

        DepositRequest::where('user_id', $user->id)->update([
            'status' => 'approved',
            'amount' => '100.00',
            'confirmed_at' => now(),
        ]);

        $second = $this->actingAs($user)->getJson('/api/deposit')->json('reference_code');

        $this->assertNotEquals($first, $second);
    }

    public function test_unauthenticated_cannot_access_deposit(): void
    {
        $this->getJson('/api/deposit')->assertStatus(401);
    }

    // ── /api/deposit/history ──

    public function test_deposit_history_returns_paginated_results(): void
    {
        $user = $this->createVerifiedInvestor();
        DepositRequest::factory()->count(3)->create(['user_id' => $user->id, 'amount' => 100]);

        $response = $this->actingAs($user)->getJson('/api/deposit/history');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_deposit_history_excludes_unfunded_placeholder_codes(): void
    {
        // The placeholder DepositRequest created by getOrCreateActiveCode
        // (amount=null) is NOT a real deposit — it's just an issued code.
        // It must not show up in the user's deposit history.
        $user = $this->createVerifiedInvestor();

        // Trigger placeholder creation
        $this->actingAs($user)->getJson('/api/deposit');
        // One real deposit
        DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => 500]);

        $response = $this->actingAs($user)->getJson('/api/deposit/history');

        $this->assertEquals(1, $response->json('meta.total'));
    }

    // ── DepositService::getOrCreateActiveCode ──

    public function test_service_returns_existing_pending_code(): void
    {
        $user = $this->createVerifiedInvestor();
        $existing = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        $service = app(DepositService::class);
        $resolved = $service->getOrCreateActiveCode($user->id);

        $this->assertEquals($existing->id, $resolved->id);
        $this->assertEquals($existing->reference_code, $resolved->reference_code);
    }

    public function test_service_creates_new_code_when_none_exists(): void
    {
        $user = $this->createVerifiedInvestor();
        $this->assertEquals(0, DepositRequest::where('user_id', $user->id)->count());

        $service = app(DepositService::class);
        $created = $service->getOrCreateActiveCode($user->id);

        $this->assertNotNull($created->reference_code);
        $this->assertNull($created->amount);
        $this->assertEquals('pending', $created->status);
        $this->assertNotNull($created->expires_at);
        $this->assertTrue($created->expires_at->isFuture());
    }

    // ── Service: approve ──

    public function test_approve_deposit_credits_wallet(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 100]);
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => 500]);

        $service = app(DepositService::class);
        $service->approve($deposit->id, 1);

        $wallet = $user->wallet->fresh();
        $this->assertEquals('600.00', $wallet->available);
    }

    public function test_approve_deposit_creates_transaction(): void
    {
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => 250]);

        $service = app(DepositService::class);
        $service->approve($deposit->id, 1);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_DEPOSIT,
            'amount' => 250,
        ]);
    }

    public function test_approve_deposit_updates_status(): void
    {
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => 100]);

        $service = app(DepositService::class);
        $result = $service->approve($deposit->id, 1);

        $this->assertEquals('approved', $result->fresh()->status);
        $this->assertNotNull($result->fresh()->confirmed_at);
    }

    public function test_approve_deposit_rejects_null_amount(): void
    {
        // Defense-in-depth: a deposit issued by /api/deposit has amount=null
        // until admin fills it. Calling approve() before amount is set is a
        // programmer error that should fail loudly, not silently credit 0.
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        $service = app(DepositService::class);

        $this->expectException(\DomainException::class);
        $service->approve($deposit->id, 1);
    }

    public function test_db_unique_constraint_blocks_duplicate_bank_reference(): void
    {
        // Audit H5: same bank wire cannot be applied to two deposits.
        // The DB UNIQUE constraint on `bank_reference` is the source-of-truth
        // guard — it fires even if both Filament pre-flight AND the
        // service-level check are bypassed (e.g. raw SQL admin intervention).
        // This test pins that bottom-layer protection.
        $user1 = $this->createVerifiedInvestor();
        $user2 = $this->createVerifiedInvestor();

        DepositRequest::factory()->create([
            'user_id' => $user1->id,
            'amount' => 500,
            'bank_reference' => 'WIRE-2026-04-27-XYZ',
            'status' => 'approved',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DepositRequest::factory()->create([
            'user_id' => $user2->id,
            'amount' => 500,
            'bank_reference' => 'WIRE-2026-04-27-XYZ',
            'status' => 'pending',
        ]);
    }

    // Note: the service-level bank_reference guard in DepositService::approve()
    // is defense-in-depth — the DB UNIQUE constraint fires first in any
    // realistic flow. The above test exercises the source-of-truth guard.

    // ── Service: reject ──

    public function test_reject_deposit_does_not_change_wallet(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 100]);
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => 500]);

        $service = app(DepositService::class);
        $service->reject($deposit->id, 1, 'Invalid reference');

        $this->assertEquals('100.00', $user->wallet->fresh()->available);
    }

    public function test_reject_deposit_updates_status_with_note(): void
    {
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => 100]);

        $service = app(DepositService::class);
        $result = $service->reject($deposit->id, 1, 'Unmatched transfer');

        $this->assertEquals('rejected', $result->fresh()->status);
        $this->assertEquals('Unmatched transfer', $result->fresh()->admin_note);
    }

    // ── DepositRequest model ──

    public function test_model_auto_generates_dep_code_and_expiry(): void
    {
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'amount' => 100,
            'status' => 'pending',
        ]);

        $this->assertMatchesRegularExpression('/^DEP-[A-Z0-9]{8}$/', $deposit->reference_code);
        $this->assertNotNull($deposit->expires_at);
        $this->assertTrue($deposit->expires_at->isFuture());
    }

    public function test_is_expired_returns_false_for_legacy_null_expires_at(): void
    {
        // Legacy rows from before the refactor have NULL expires_at and must
        // continue to work (treated as never-expiring). This guards against
        // a regression that breaks production rows on first deploy.
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id]);
        $deposit->forceFill(['expires_at' => null])->save();

        $this->assertFalse($deposit->fresh()->isExpired());
    }

    public function test_is_expired_returns_true_for_past_expiry(): void
    {
        $user = $this->createVerifiedInvestor();
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id]);
        $deposit->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertTrue($deposit->fresh()->isExpired());
    }
}
