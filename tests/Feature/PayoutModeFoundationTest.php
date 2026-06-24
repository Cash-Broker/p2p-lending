<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foundation for the scheduled-accrual payout feature (boss, 2026-06-23):
 *   • loans.payout_mode (manual default, editable after draft for existing loans)
 *   • wallets.accrued bucket + "текущо салдо" = invested + accrued
 */
class PayoutModeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_loan_defaults_to_manual_payout_mode(): void
    {
        $loan = Loan::factory()->create();

        $this->assertSame(Loan::PAYOUT_MODE_MANUAL, $loan->fresh()->payout_mode,
            'existing/new loans must default to manual so behavior is unchanged until an admin flips it');
    }

    public function test_payout_mode_is_editable_on_an_active_loan(): void
    {
        // The boss explicitly needs to switch ALREADY-UPLOADED (non-draft) loans,
        // so payout_mode must NOT be frozen by the post-draft immutability guard.
        $loan = Loan::factory()->active()->create();

        $loan->update(['payout_mode' => Loan::PAYOUT_MODE_AUTOMATIC]);

        $this->assertSame(Loan::PAYOUT_MODE_AUTOMATIC, $loan->fresh()->payout_mode);
    }

    public function test_payout_mode_is_not_a_frozen_financial_term(): void
    {
        $this->assertNotContains('payout_mode', Loan::IMMUTABLE_AFTER_DRAFT);
    }

    public function test_wallet_current_balance_is_invested_plus_accrued(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create();
        $wallet->forceFill(['invested' => '1000.00', 'accrued' => '50.00'])->save();

        $this->assertSame('1050.00', $wallet->fresh()->currentBalance());
    }

    public function test_accrued_defaults_to_zero_and_current_balance_equals_invested(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create();
        $wallet->forceFill(['invested' => '700.00'])->save();

        $wallet->refresh();
        $this->assertSame('0.00', (string) $wallet->accrued);
        $this->assertSame('700.00', $wallet->currentBalance());
    }

    public function test_db_rejects_negative_accrued(): void
    {
        // Last line of defense — the accrued bucket can never go negative.
        if (\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('CHECK constraints are not enforced on SQLite.');
        }

        $user = User::factory()->create();
        $wallet = $user->wallet()->create();

        $this->expectException(QueryException::class);
        $wallet->forceFill(['accrued' => '-1.00'])->save();
    }
}
