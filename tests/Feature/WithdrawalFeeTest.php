<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesSavedIbans;
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
    use CreatesSavedIbans, RefreshDatabase;

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

        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
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

        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
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

        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));

        $wallet = $user->wallet->fresh();
        $this->assertEquals('400.00', $wallet->available, 'available dropped by reserved amount');
        $this->assertEquals('100.00', $wallet->reserved, 'reserved = 100 after request');

        $service->approve($withdrawal->id, 1);

        $wallet = $user->wallet->fresh();
        $this->assertEquals('400.00', $wallet->available, 'available unchanged after approve');
        $this->assertEquals('0.00', $wallet->reserved, 'reserved = 0 after both debits');
    }

    public function test_flag_on_where_amount_does_not_exceed_fee_is_refused_at_request_time(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 10]);
        $service = app(WithdrawalService::class);

        // Request 2.00 € — fee 2.50 € → net would be negative. Since the
        // 2026-09-01 audit the request itself is refused: nothing may sit
        // reserved for a withdrawal that can never be paid.
        try {
            $service->createRequest($user->id, '2.00', $this->confirmedIban($user));
            $this->fail('Expected ValidationException when amount <= fee.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
        $this->assertSame(0, WithdrawalRequest::where('user_id', $user->id)->count());
        $this->assertEquals('0.00', $user->wallet->fresh()->reserved);
    }

    public function test_legacy_request_without_quoted_fee_is_refused_at_approval_when_amount_does_not_exceed_fee(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 10]);
        $service = app(WithdrawalService::class);

        // A row from before `fee_quoted` existed (NULL) meets a fee switched on later:
        // the live quote applies and 2.00 € cannot cover a 2.50 € fee.
        $withdrawal = $service->createRequest($user->id, '2.00', $this->confirmedIban($user));
        DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['fee_quoted' => null]);
        PlatformSetting::set('fees_withdrawal_enabled', true);

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

    public function test_fee_transaction_description_mentions_iban_suffix_and_withdrawal_id(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $user = $this->createVerifiedInvestor(['available' => 500]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
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

        $w1 = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
        $service->approve($w1->id, 1);

        $w2 = $service->createRequest($user->id, '150.00', $this->confirmedIban($user));
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

    public function test_fee_charged_is_the_fee_disclosed_at_request_time_not_the_flag_at_approval(): void
    {
        // Owner decision 2026-09-03 (audit PAY-24): the investor pays exactly the
        // fee shown when the request was made. A flag flip between request and
        // approval changes nothing for requests already in the queue.
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 1000]);
        $service = app(WithdrawalService::class);

        // Fee OFF at request → 0.00 disclosed → 0.00 charged even if switched on later.
        $w1 = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
        $this->assertSame('0.00', (string) $w1->fresh()->fee_quoted);
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $service->approve($w1->id, 1);
        $this->assertSame(0, Transaction::where('reference', "withdrawal_request:{$w1->id}:fee")->count());
        $this->assertSame('100.00', (string) Transaction::where('reference', "withdrawal_request:{$w1->id}")->value('amount'));

        // Fee ON at request → 2.50 disclosed → 2.50 charged even if switched off later.
        $w2 = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
        $this->assertSame('2.50', (string) $w2->fresh()->fee_quoted);
        PlatformSetting::set('fees_withdrawal_enabled', false);
        $service->approve($w2->id, 1);
        $this->assertSame('2.50', (string) Transaction::where('reference', "withdrawal_request:{$w2->id}:fee")->value('amount'));
        $this->assertSame('97.50', (string) Transaction::where('reference', "withdrawal_request:{$w2->id}")->value('amount'));
    }

    public function test_requests_created_before_fee_quoted_existed_use_the_live_quote(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => 1000]);
        $service = app(WithdrawalService::class);
        $w = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
        DB::table('withdrawal_requests')->where('id', $w->id)->update(['fee_quoted' => null]);
        PlatformSetting::set('fees_withdrawal_enabled', true);

        $service->approve($w->id, 1);

        $this->assertSame('2.50', (string) Transaction::where('reference', "withdrawal_request:{$w->id}:fee")->value('amount'));
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

        $w1 = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));
        $service->approve($w1->id, 1);
        $w2 = $service->createRequest($user->id, '50.00', $this->confirmedIban($user));
        $service->approve($w2->id, 1);

        $feeSum = Transaction::where('type', Transaction::TYPE_FEE)->sum('amount');

        $this->assertEquals('5.00', number_format((float) $feeSum, 2, '.', ''));
    }
}
