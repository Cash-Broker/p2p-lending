<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Deposit codes are non-expiring (2026-07-17), so account deletion must
 * retire the unused placeholder code — otherwise it would stay creditable
 * forever against an account whose wallet no longer exists.
 */
class AccountDeletionDepositCodeTest extends TestCase
{
    use RefreshDatabase;

    private function deletableInvestor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_deletion_retires_the_unused_deposit_code(): void
    {
        $user = $this->deletableInvestor();
        $placeholder = app(DepositService::class)->getOrCreateActiveCode($user->id);

        app(AccountDeletionService::class)->deleteAccount($user, 'password');

        $fresh = $placeholder->fresh();
        $this->assertEquals('rejected', $fresh->status);
        $this->assertStringContainsString('акаунтът е закрит', $fresh->admin_note);
        // The retired code no longer resolves in the admin credit flow.
        $this->assertEquals(
            0,
            DepositRequest::where('reference_code', $placeholder->reference_code)
                ->where('status', 'pending')
                ->count(),
        );
    }

    public function test_deletion_is_still_blocked_by_a_funded_pending_deposit(): void
    {
        $user = $this->deletableInvestor();
        DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => '500.00',
            'status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);
        app(AccountDeletionService::class)->deleteAccount($user, 'password');
    }
}
