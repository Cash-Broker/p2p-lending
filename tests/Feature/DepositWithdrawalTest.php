<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DepositService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $walletBalances = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletBalances) {
            $wallet->forceFill($walletBalances)->save();
        }
        return $user;
    }

    // ── Deposit endpoints ──

    public function test_deposit_info_returns_reference_code_and_bank_details(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/deposit');

        $response->assertOk()
            ->assertJsonStructure(['reference_code', 'bank_details' => ['bank_name', 'iban', 'bic', 'beneficiary']]);

        $this->assertStringStartsWith('DEP-', $response->json('reference_code'));
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
        DepositRequest::factory()->count(3)->create([
            'user_id' => $user->id,
            'amount' => 500,
        ]);

        $response = $this->actingAs($user)->getJson('/api/deposit/history');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_unauthenticated_cannot_access_deposit(): void
    {
        $this->getJson('/api/deposit')->assertStatus(401);
    }

    // ── Deposit service (admin approve/reject) ──

    public function test_deposit_approve_credits_wallet(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 100]);
        $deposit = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 500,
            'status' => 'pending',
        ]);

        $service = app(DepositService::class);
        $service->approve($deposit->id, 1);

        $this->assertEquals('600.00', $user->wallet->fresh()->available);
        $this->assertEquals('approved', $deposit->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_DEPOSIT,
            'amount' => 500,
        ]);
    }

    public function test_deposit_reject_does_not_change_wallet(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 100]);
        $deposit = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 500,
            'status' => 'pending',
        ]);

        $service = app(DepositService::class);
        $service->reject($deposit->id, 1, 'Invalid transfer');

        $this->assertEquals('100.00', $user->wallet->fresh()->available);
        $this->assertEquals('rejected', $deposit->fresh()->status);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_DEPOSIT,
        ]);
    }

    // ── Withdrawal endpoint ──

    public function test_withdrawal_request_created_successfully(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => 1000,
            'iban' => 'BG80BNBG96611020345678',
        ]);

        $response->assertStatus(201)
            ->assertJson(['message' => 'Withdrawal request created successfully.']);

        $this->assertDatabaseHas('withdrawal_requests', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_withdrawal_fails_insufficient_balance(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 100]);

        $response = $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => 500,
            'iban' => 'BG80BNBG96611020345678',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_withdrawal_fails_kyc_not_approved(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $response = $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => 100,
            'iban' => 'BG80BNBG96611020345678',
        ]);

        $response->assertStatus(403);
    }

    public function test_withdrawal_fails_invalid_iban(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => 100,
            'iban' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('iban');
    }

    public function test_withdrawal_history_returns_paginated(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        // Create via endpoint so it goes through proper flow
        $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => 100, 'iban' => 'BG80BNBG96611020345678',
        ]);
        $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => 200, 'iban' => 'BG80BNBG96611020345678',
        ]);

        $response = $this->actingAs($user)->getJson('/api/withdrawal/history');

        $response->assertOk();
        $this->assertEquals(2, $response->json('meta.total'));
    }

    // ── Withdrawal service (admin approve/reject) ──

    public function test_withdrawal_approve_debits_wallet(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $service = app(WithdrawalService::class);
        $withdrawal = $service->createRequest($user->id, '1000.00', 'BG80BNBG96611020345678');

        $service->approve($withdrawal->id, 1);

        $this->assertEquals('4000.00', $user->wallet->fresh()->available);
        $this->assertEquals('approved', $withdrawal->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_WITHDRAWAL,
            'amount' => 1000,
        ]);
    }

    public function test_withdrawal_reject_does_not_change_wallet(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $service = app(WithdrawalService::class);
        $withdrawal = $service->createRequest($user->id, '1000.00', 'BG80BNBG96611020345678');

        $service->reject($withdrawal->id, 1, 'Suspicious activity');

        $this->assertEquals('5000.00', $user->wallet->fresh()->available);
        $this->assertEquals('rejected', $withdrawal->fresh()->status);
    }

    // ── Wallet endpoint ──

    public function test_wallet_endpoint_returns_balance(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 1234.56, 'invested' => 789.01, 'earned' => 45.67]);

        $response = $this->actingAs($user)->getJson('/api/wallet');

        $response->assertOk()
            ->assertJsonPath('available', '1234.56')
            ->assertJsonPath('invested', '789.01')
            ->assertJsonPath('earned', '45.67');
    }

    public function test_unauthenticated_cannot_access_wallet(): void
    {
        $this->getJson('/api/wallet')->assertStatus(401);
    }
}
