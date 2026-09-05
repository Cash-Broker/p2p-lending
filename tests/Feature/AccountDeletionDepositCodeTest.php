<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountDeletionDepositCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function deletableInvestor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    /** SEC-22 flow in one call: request → confirmation → waiting period → finalisation. */
    private function closeAccount(User $user): string
    {
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'password');
        $service->confirm($user->fresh());
        Carbon::setTestNow(now()->addDays(8));

        return $service->finalize($user->fresh());
    }

    public function test_deletion_retires_the_unused_deposit_code(): void
    {
        $user = $this->deletableInvestor();
        $placeholder = app(DepositService::class)->getOrCreateActiveCode($user->id);

        $this->assertSame('finalized', $this->closeAccount($user));

        $fresh = $placeholder->fresh();
        $this->assertEquals('rejected', $fresh->status);
        $this->assertStringContainsString('акаунтът е закрит', $fresh->admin_note);
        $this->assertEquals(
            0,
            DepositRequest::where('reference_code', $placeholder->reference_code)
                ->where('status', 'pending')
                ->count(),
        );
    }

    public function test_a_funded_pending_deposit_blocks_the_request(): void
    {
        $user = $this->deletableInvestor();
        DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => '500.00',
            'status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);
        app(AccountDeletionService::class)->requestDeletion($user, 'password');
    }

    public function test_a_deposit_credited_during_the_waiting_period_blocks_finalisation_and_cancels_the_request(): void
    {
        $user = $this->deletableInvestor();
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'password');
        $service->confirm($user->fresh());

        // A wire lands while the request waits.
        DepositRequest::factory()->create(['user_id' => $user->id, 'amount' => '500.00', 'status' => 'pending']);
        Carbon::setTestNow(now()->addDays(8));

        $this->assertSame('blocked', $service->finalize($user->fresh()));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->wallet);
        $this->assertNull($fresh->deletion_requested_at, 'a blocked finalisation cancels the request instead of retrying forever');
        $this->assertNull($fresh->deletion_finalized_at);
    }
}
