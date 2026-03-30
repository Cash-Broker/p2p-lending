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

    // ── API endpoints ──

    public function test_deposit_info_returns_reference_code_and_bank_details(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/deposit');

        $response->assertOk()
            ->assertJsonStructure(['reference_code', 'bank_details' => ['bank_name', 'iban', 'bic']]);
        $this->assertStringStartsWith('P2P-', $response->json('reference_code'));
    }

    public function test_deposit_reference_code_is_unique_per_user(): void
    {
        $user1 = $this->createVerifiedInvestor();
        $user2 = $this->createVerifiedInvestor();

        $ref1 = $this->actingAs($user1)->getJson('/api/deposit')->json('reference_code');
        $ref2 = $this->actingAs($user2)->getJson('/api/deposit')->json('reference_code');

        $this->assertNotEquals($ref1, $ref2);
    }

    public function test_deposit_history_returns_paginated_results(): void
    {
        $user = $this->createVerifiedInvestor();
        DepositRequest::factory()->count(3)->create(['user_id' => $user->id, 'amount' => 100]);

        $response = $this->actingAs($user)->getJson('/api/deposit/history');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_unauthenticated_cannot_access_deposit(): void
    {
        $this->getJson('/api/deposit')->assertStatus(401);
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
}
