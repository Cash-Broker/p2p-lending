<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Integration smoke tests for F4 Batch A — WithdrawalService fee
 * branch-split at approve(). Verifies that the flag-off path is
 * byte-identical to pre-F4 (no new transaction, no reserved delta
 * change) and the flag-on path creates the expected TYPE_WITHDRAWAL
 * (net) + TYPE_FEE pair inside one transaction.
 *
 * Full coverage — flag-flip mid-approve, reserved-bucket invariants,
 * audit log sanity, description/reference format contracts — goes in
 * Step 5/6.
 */
class WithdrawalFeeTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $overrides = []): User
    {
        $user = User::factory()->kycApproved()->create();
        $user->wallet()->create();
        $user->wallet->forceFill(array_merge(['available' => 0, 'reserved' => 0], $overrides))->save();
        return $user;
    }

    public function test_flag_off_produces_single_type_withdrawal_transaction(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');
        $service->approve($withdrawal->id, 1);

        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_WITHDRAWAL)->count(),
            'Flag off: exactly one TYPE_WITHDRAWAL transaction.'
        );
        $this->assertSame(
            0,
            Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_FEE)->count(),
            'Flag off: zero TYPE_FEE transactions.'
        );
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_WITHDRAWAL,
            'amount' => 100,
            'reference' => "withdrawal_request:{$withdrawal->id}",
        ]);
    }

    public function test_flag_on_produces_withdrawal_net_and_separate_fee_transaction(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');
        $service->approve($withdrawal->id, 1);

        // Net withdrawal = 100 - 2.50 = 97.50
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_WITHDRAWAL,
            'amount' => 97.50,
            'reference' => "withdrawal_request:{$withdrawal->id}",
        ]);
        // Fee = 2.50 with :fee-suffixed reference
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_FEE,
            'amount' => 2.50,
            'reference' => "withdrawal_request:{$withdrawal->id}:fee",
        ]);
        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_FEE)->count(),
            'Flag on: exactly one TYPE_FEE transaction.'
        );
    }

    public function test_flag_on_clears_full_reserved_bucket_via_two_debits(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');

        $wallet = $user->wallet->fresh();
        $this->assertEquals('400.00', $wallet->available, 'available dropped by reserved amount');
        $this->assertEquals('100.00', $wallet->reserved, 'reserved = 100 after request');

        $service->approve($withdrawal->id, 1);

        $wallet = $user->wallet->fresh();
        $this->assertEquals('400.00', $wallet->available, 'available unchanged after approve');
        $this->assertEquals('0.00', $wallet->reserved, 'reserved = 0 after both debits');
    }

    public function test_flag_on_where_amount_does_not_exceed_fee_throws_validation(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 10]);
        $service = app(WithdrawalService::class);

        // Request 2.00 € — fee 2.50 € → net would be negative.
        $withdrawal = $service->createRequest($user->id, '2.00', 'BG80BNBG96611020345678');

        try {
            $service->approve($withdrawal->id, 1);
            $this->fail('Expected ValidationException when amount <= fee.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must exceed fee', $e->getMessage());
        }

        // Partial-commit sanity: no transactions created, withdrawal
        // still pending, reservation untouched.
        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertEquals('2.00', $user->wallet->fresh()->reserved);
    }

    // ── Batch B — extended integration coverage ──

    public function test_fee_transaction_description_mentions_iban_suffix_and_withdrawal_id(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');
        $service->approve($withdrawal->id, 1);

        $feeTx = Transaction::where('type', Transaction::TYPE_FEE)->firstOrFail();

        // Description must carry the withdrawal id for admin audit and
        // the IBAN suffix for quick operator cross-reference. No full
        // IBAN (PII) in the description.
        $this->assertStringContainsString("#{$withdrawal->id}", $feeTx->description);
        $this->assertStringContainsString('****5678', $feeTx->description);
        $this->assertStringNotContainsString('BG80BNBG9661', $feeTx->description);
    }

    public function test_multiple_sequential_withdrawals_get_distinct_fee_references(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 1000]);
        $service = app(WithdrawalService::class);

        $w1 = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');
        $service->approve($w1->id, 1);

        $w2 = $service->createRequest($user->id, '150.00', 'BG80BNBG96611020345678');
        $service->approve($w2->id, 1);

        $feeRefs = Transaction::where('type', Transaction::TYPE_FEE)
            ->orderBy('id')
            ->pluck('reference')
            ->toArray();

        $this->assertSame([
            "withdrawal_request:{$w1->id}:fee",
            "withdrawal_request:{$w2->id}:fee",
        ], $feeRefs);
    }

    public function test_fee_amount_uses_value_at_approval_time_not_request_time(): void
    {
        // Verifies the DECISIONS.md F4-01 "fee lookup inside DB::transaction"
        // contract: charge reflects the CURRENT flag state, not what was
        // live when the request was first created. Admin can defer an
        // approval across a config flip; the flip wins.
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        // Request created while fee is OFF.
        $withdrawal = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');

        // Admin enables fee BEFORE clicking approve.
        PlatformSetting::set('fees_withdrawal_enabled', true);

        $service->approve($withdrawal->id, 1);

        // Approval charged the fee because the flag was on AT approve time.
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type'    => Transaction::TYPE_FEE,
            'amount'  => 2.50,
        ]);
    }

    public function test_reconcile_ledger_sees_fee_sum_when_flag_on(): void
    {
        // Guards the existing ReconcileLedger report — fees must show up
        // in the TYPE_FEE sum bucket without any additional wiring. If
        // this test breaks, something was moved from TYPE_FEE to a new
        // constant and the daily reconciliation alert will go quiet.
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        $w1 = $service->createRequest($user->id, '100.00', 'BG80BNBG96611020345678');
        $service->approve($w1->id, 1);
        $w2 = $service->createRequest($user->id, '50.00', 'BG80BNBG96611020345678');
        $service->approve($w2->id, 1);

        $feeSum = Transaction::where('type', Transaction::TYPE_FEE)->sum('amount');

        $this->assertEquals('5.00', number_format((float) $feeSum, 2, '.', ''));
    }
}
