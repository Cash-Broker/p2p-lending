<?php

namespace Tests\Feature;

use App\Filament\Resources\DepositRequestResource\Pages\ListDepositRequests;
use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Захрани сметка" (credit_account) table-header action — the only
 * admin entry point for crediting deposits. Pins the 2026-07-17 client
 * decision end-to-end on the admin side: a pending code is creditable
 * regardless of any old-policy expires_at, and the flow fails cleanly
 * (no half-written rows) when the code can't be credited.
 */
class DepositCreditAccountActionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function investorWithPendingCode(array $walletOverrides = []): array
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletOverrides) {
            $wallet->forceFill($walletOverrides)->save();
        }
        $deposit = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        return [$user, $deposit];
    }

    public function test_admin_can_credit_a_code_with_old_policy_expiry_date(): void
    {
        $this->actingAs($this->admin());
        [$user, $deposit] = $this->investorWithPendingCode(['available' => 100]);
        // Old-policy row: stale expires_at must NOT block crediting.
        $deposit->forceFill(['expires_at' => now()->subMonths(2)])->save();

        Livewire::test(ListDepositRequests::class)
            ->callAction(TestAction::make('credit_account')->table(), data: [
                'reference_code' => $deposit->reference_code,
                'amount' => '250.00',
                'bank_reference' => 'STMT-2026-07-17-001',
            ])
            ->assertHasNoErrors();

        $fresh = $deposit->fresh();
        $this->assertEquals('approved', $fresh->status);
        $this->assertEquals('250.00', $fresh->amount);
        $this->assertEquals('STMT-2026-07-17-001', $fresh->bank_reference);
        $this->assertEquals('350.00', $user->wallet->fresh()->available);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_DEPOSIT,
            'amount' => 250,
        ]);
    }

    public function test_consumed_code_is_refused_without_double_credit(): void
    {
        $this->actingAs($this->admin());
        [$user, $deposit] = $this->investorWithPendingCode();
        $deposit->update(['amount' => '100.00', 'status' => 'approved', 'confirmed_at' => now()]);

        Livewire::test(ListDepositRequests::class)
            ->callAction(TestAction::make('credit_account')->table(), data: [
                'reference_code' => $deposit->reference_code,
                'amount' => '250.00',
                'bank_reference' => 'STMT-2026-07-17-002',
            ])
            ->assertHasNoErrors();

        // No credit happened, the approved row was not overwritten.
        $fresh = $deposit->fresh();
        $this->assertEquals('100.00', $fresh->amount);
        $this->assertNull($fresh->bank_reference);
        $this->assertEquals('0.00', $user->wallet->fresh()->available);
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_deleted_account_code_is_refused_without_burning_bank_reference(): void
    {
        // Legacy zombie code: pending row whose account was GDPR-deleted
        // (wallet hard-deleted) before deletion started retiring codes.
        $this->actingAs($this->admin());
        [$user, $deposit] = $this->investorWithPendingCode();
        $user->wallet()->delete();

        Livewire::test(ListDepositRequests::class)
            ->callAction(TestAction::make('credit_account')->table(), data: [
                'reference_code' => $deposit->reference_code,
                'amount' => '250.00',
                'bank_reference' => 'STMT-2026-07-17-003',
            ])
            ->assertHasNoErrors();

        // Refused cleanly BEFORE any write — the wire's bank_reference is
        // still free to be credited elsewhere (manual/refund handling).
        $fresh = $deposit->fresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->amount);
        $this->assertNull($fresh->bank_reference);
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_non_admin_cannot_open_the_deposits_page(): void
    {
        [$user] = $this->investorWithPendingCode();
        $this->actingAs($user);

        $this->get('/admin/deposit-requests')->assertForbidden();
    }
}
