<?php

namespace Tests\Feature\AuditFixes2026;

use App\Enums\PayoutType;
use App\Exceptions\InsufficientBalanceException;
use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\PlatformMetric;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DepositService;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * Audit 2026-09-01, package A2 — approval and closure controls.
 *
 * Controls that were missing around admin decisions and account lifecycle.
 * None of them changes what the client asked for; each one refuses a state
 * the product never intended (paying out to a revoked KYC, closing an account
 * with money in flight, a stale form save moving a funded loan back to draft).
 */
class ApprovalControlsTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    private function createVerifiedInvestor(array $walletOverrides = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!')]);
        $wallet = $user->wallet()->create();
        if ($walletOverrides) {
            $wallet->forceFill($walletOverrides)->save();
        }

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ── PAY-14: approval re-checks the investor, not just the request row ──

    public function test_withdrawal_approval_is_refused_when_the_investor_is_no_longer_verified(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => '1000.00']);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '400.00', $this->confirmedIban($user));

        // Compliance withdraws the verification after the request was made.
        $user->forceFill(['kyc_status' => 'rejected'])->save();

        try {
            $service->approve($withdrawal->id, 1);
            $this->fail('Approving a withdrawal for an investor with revoked KYC must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('kyc', $e->errors());
        }

        $wallet = $user->wallet->fresh();
        $this->assertSame('400.00', (string) $wallet->reserved, 'the hold stays until an admin rejects the request');
        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id, 'type' => Transaction::TYPE_WITHDRAWAL]);
    }

    public function test_filament_approve_action_reports_the_kyc_refusal_as_a_toast_and_moves_no_money(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $user = $this->createVerifiedInvestor(['available' => '1000.00']);
        $withdrawal = app(WithdrawalService::class)->createRequest($user->id, '400.00', $this->confirmedIban($user));
        $user->forceFill(['kyc_status' => 'rejected'])->save();

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('approve', $withdrawal)
            ->assertNotified('Тегленето не може да бъде одобрено');

        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertSame('400.00', (string) $user->wallet->fresh()->reserved);
    }

    // ── PAY-45: no account closure while an approved wire is still in flight ──

    public function test_account_deletion_is_blocked_while_an_approved_withdrawal_is_unpaid(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => '400.00']);
        $service = app(WithdrawalService::class);
        $withdrawal = $service->createRequest($user->id, '400.00', $this->confirmedIban($user));
        $service->approve($withdrawal->id, 1);

        // Ledger says 0 everywhere — the only trace of the money in flight is
        // the approved (not yet processed) request.
        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);

        $this->actingAs($user)->postJson('/api/profile/delete', ['password' => 'Password123!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');

        $this->assertNotNull(Wallet::where('user_id', $user->id)->first(), 'the wallet must survive until the wire is confirmed');
        $this->assertSame($user->email, $user->fresh()->email);
    }

    // ── PAY-34: the KYC decision commits even when the investor mail fails ──

    public function test_kyc_approval_survives_a_notification_failure(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'submitted']);

        Event::listen(NotificationSending::class, function (): void {
            throw new RuntimeException('smtp down');
        });

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('approve_kyc');

        $this->assertSame('approved', $user->fresh()->kyc_status, 'the decision is money-gating; a mail outage must not roll it back');
    }

    // ── PAY-41: losing the wallet race is a 422, not a 500 ──

    public function test_invest_race_loser_gets_422_not_500(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        $user = $this->createVerifiedInvestor(['available' => '5000.00']);

        // The pre-check sees 5 000 €; the locked re-check inside WalletService
        // finds the money gone (another tab spent it) and throws.
        $this->mock(WalletService::class, function ($mock) {
            $mock->shouldReceive('invest')->once()->andThrow(new InsufficientBalanceException('Insufficient available balance.'));
        });

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 1000,
            'loan_offer_id' => $offerId,
        ], ['X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, Investment::where('user_id', $user->id)->count());
    }

    // ── PAY-38: money in the loan blocks the way back to draft, from every fundable status ──

    public function test_published_loan_with_money_cannot_go_back_to_draft(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => '5000.00', 'funded_amount' => '100.00']);

        $this->expectException(LogicException::class);
        $loan->update(['status' => Loan::STATUS_DRAFT]);
    }

    public function test_edit_form_save_is_evaluated_against_the_fresh_row(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());

        $loan = Loan::factory()->published()->create(['amount' => '5000.00', 'funded_amount' => '0.00']);
        $component = Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()]);

        // While the form is open an investor commits: published → funding, 500 € in.
        DB::table('loans')->where('id', $loan->id)->update(['status' => Loan::STATUS_FUNDING, 'funded_amount' => '500.00']);

        // The model guard fires against the FRESH row; EditLoan turns it into a BG
        // toast + Halt (review 2026-09-05) instead of Livewire's raw error dialog.
        $component->fillForm(['status' => Loan::STATUS_DRAFT])->call('save')
            ->assertNotified('Кредитът вече има инвестиции');

        $fresh = $loan->fresh();
        $this->assertSame(Loan::STATUS_FUNDING, $fresh->status);
        $this->assertSame('500.00', (string) $fresh->funded_amount);
    }

    // ── PAY-33: a replayed idempotency key must belong to the same investor ──

    public function test_idempotency_key_of_another_account_is_refused(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        $alice = $this->createVerifiedInvestor(['available' => '5000.00']);
        $bob = $this->createVerifiedInvestor(['available' => '5000.00']);
        $key = 'shared-key-'.uniqid();

        $this->actingAs($alice)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 1000, 'loan_offer_id' => $offerId,
        ], ['X-Idempotency-Key' => $key])->assertStatus(201);

        $this->actingAs($bob)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 1000, 'loan_offer_id' => $offerId,
        ], ['X-Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame('5000.00', (string) $bob->wallet->fresh()->available);
        $this->assertSame(0, Investment::where('user_id', $bob->id)->count());
        $this->assertSame(1, Investment::where('idempotency_key', $key)->count());
    }

    // ── PAY-32: the gateway refuses ledger types it does not know ──

    public function test_wallet_service_refuses_an_unknown_ledger_type(): void
    {
        $user = $this->createVerifiedInvestor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown ledger transaction type');

        app(WalletService::class)->credit($user->id, '10.00', 'mystery_movement', 'should never land');
    }

    // ── PAY-02: reconciliation still sees ledger rows whose wallet row is gone ──

    public function test_reconcile_flags_ledger_rows_without_a_wallet_that_do_not_net_to_zero(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor();
        $deposits = app(DepositService::class);
        $deposit = $deposits->createRequest($user->id, '100.00');
        $deposits->approve($deposit->id, 1);

        // Bypass every guard: the wallet row disappears, the 100 € deposit row stays.
        Wallet::where('user_id', $user->id)->delete();

        $this->assertSame(1, Artisan::call('ledger:reconcile'));
        $this->assertStringContainsString('wallet deleted', Artisan::output());
        $this->assertSame('mismatch', PlatformMetric::read('last_reconcile_status'), 'the health endpoint must see the mismatch');
        $this->assertSame('1', PlatformMetric::read('last_reconcile_mismatches'));
    }

    public function test_reconcile_accepts_a_deleted_wallet_whose_ledger_nets_to_zero(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor();
        $deposits = app(DepositService::class);
        $deposit = $deposits->createRequest($user->id, '100.00');
        $deposits->approve($deposit->id, 1);

        $withdrawals = app(WithdrawalService::class);
        $withdrawal = $withdrawals->createRequest($user->id, '100.00', $this->confirmedIban($user));
        $withdrawals->approve($withdrawal->id, 1);

        Wallet::where('user_id', $user->id)->delete();

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
        $this->assertSame('ok', PlatformMetric::read('last_reconcile_status'));
        $this->assertNotNull(PlatformMetric::measuredAt('last_reconcile_run_at'));
    }
}
