<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalTest extends TestCase
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

    // ── API: create withdrawal ──

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
            'amount' => 1000,
            'status' => 'pending',
        ]);

        // Balance reservation: available reduced, reserved increased
        $wallet = $user->wallet->fresh();
        $this->assertEquals('4000.00', $wallet->available);
        $this->assertEquals('1000.00', $wallet->reserved);
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

    public function test_unauthenticated_cannot_withdraw(): void
    {
        $this->postJson('/api/withdrawal', [
            'amount' => 100,
            'iban' => 'BG80BNBG96611020345678',
        ])->assertStatus(401);
    }

    public function test_withdrawal_history_returns_paginated_results(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000]);
        WithdrawalRequest::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/withdrawal/history');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
        $this->assertEquals(3, $response->json('meta.total'));
    }

    // ── Service: approve ──

    public function test_approve_withdrawal_debits_wallet(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 5000]);
        $service = app(WithdrawalService::class);

        // Create via service to properly reserve balance
        $withdrawal = $service->createRequest($user->id, '1000.00', 'BG80BNBG96611020345678');

        $wallet = $user->wallet->fresh();
        $this->assertEquals('4000.00', $wallet->available);
        $this->assertEquals('1000.00', $wallet->reserved);

        $service->approve($withdrawal->id, 1);

        $wallet = $user->wallet->fresh();
        $this->assertEquals('4000.00', $wallet->available); // unchanged — debited from reserved
        $this->assertEquals('0.00', $wallet->reserved);
    }

    public function test_approve_withdrawal_creates_transaction(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 5000]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '500.00', 'BG80BNBG96611020345678');
        $service->approve($withdrawal->id, 1);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_WITHDRAWAL,
            'amount' => 500,
        ]);
    }

    public function test_approve_withdrawal_updates_status(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 5000]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '500.00', 'BG80BNBG96611020345678');
        $result = $service->approve($withdrawal->id, 1);

        $this->assertEquals('approved', $result->fresh()->status);
        $this->assertNotNull($result->fresh()->processed_at);
    }

    // ── Service: reject ──

    public function test_reject_withdrawal_restores_reserved_to_available(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 5000]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '500.00', 'BG80BNBG96611020345678');

        // After create: available=4500, reserved=500
        $wallet = $user->wallet->fresh();
        $this->assertEquals('4500.00', $wallet->available);
        $this->assertEquals('500.00', $wallet->reserved);

        $service->reject($withdrawal->id, 1, 'Suspicious activity');

        // After reject: available=5000, reserved=0
        $wallet = $user->wallet->fresh();
        $this->assertEquals('5000.00', $wallet->available);
        $this->assertEquals('0.00', $wallet->reserved);
    }

    public function test_reject_withdrawal_updates_status(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 5000]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '500.00', 'BG80BNBG96611020345678');
        $result = $service->reject($withdrawal->id, 1, 'Suspicious activity');

        $this->assertEquals('rejected', $result->fresh()->status);
        $this->assertEquals('Suspicious activity', $result->fresh()->admin_note);
    }
}
