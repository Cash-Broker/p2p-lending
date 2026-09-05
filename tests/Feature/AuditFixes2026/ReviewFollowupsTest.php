<?php

namespace Tests\Feature\AuditFixes2026;

use App\Enums\PayoutType;
use App\Exceptions\InsufficientBalanceException;
use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Filament\Resources\LoanResource\RelationManagers\OffersRelationManager;
use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\Loan;
use App\Models\LoanEarlyClosure;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\PayoutRunFailedAdminNotification;
use App\Services\InvestmentService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\ScheduledPayoutService;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * Audit 2026-09-01 — findings of the 2026-09-03 adversarial review of the
 * A1/A2 batch, plus the A3/A4 items that live in the same files.
 */
class ReviewFollowupsTest extends TestCase
{
    use CreatesSavedIbans;
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

    /** @return array{0: Loan, 1: User} */
    private function activeOfferLoan(PayoutType $type = PayoutType::Amortizing, array $loanAttrs = []): array
    {
        Notification::fake();
        $loan = Loan::factory()->published()->create(array_merge([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ], $loanAttrs));
        $user = $this->createVerifiedInvestor();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');
        $offerId = $loan->offers()->where('payout_type', $type)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);
        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $user];
    }

    // ── PAY-41 (review): only the balance race is a 422 ──

    public function test_invest_answers_422_to_the_balance_race_and_keeps_platform_faults_loud(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        $user = $this->createVerifiedInvestor(['available' => '5000.00']);
        $payload = ['amount' => 1000, 'loan_offer_id' => $offerId];

        $this->mock(WalletService::class, function ($mock) {
            $mock->shouldReceive('invest')->once()->andThrow(new InsufficientBalanceException('Insufficient available balance.'));
        });
        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", $payload, ['X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        // Any other InvalidArgumentException from inside invest() is a platform
        // fault — it must NOT be dressed up as «insufficient balance».
        $this->mock(WalletService::class, function ($mock) {
            $mock->shouldReceive('invest')->once()->andThrow(new InvalidArgumentException('Term must be at least 1 month.'));
        });
        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", $payload, ['X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(500);
    }

    // ── PAY-40 (review): the impossible term is refused at admin time, never a 500 ──

    public function test_offer_quotes_answer_422_for_a_term_the_annuity_cannot_amortize(): void
    {
        $loan = Loan::factory()->published()->create([
            'amount' => 100, 'investable_amount' => 100, 'funded_amount' => 0, 'term_months' => 240,
        ]);
        $user = $this->createVerifiedInvestor();

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}/offer-quotes")
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_loan_form_refuses_a_term_the_annuity_cannot_amortize(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $loan = Loan::factory()->published()->create(['amount' => '5000.00', 'funded_amount' => '0.00', 'term_months' => 12]);

        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->fillForm(['term_months' => 240])
            ->call('save')
            ->assertHasFormErrors(['term_months']);
        $this->assertSame(12, (int) $loan->fresh()->term_months);

        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->fillForm(['term_months' => 60])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(60, (int) $loan->fresh()->term_months);
    }

    public function test_offer_rate_edit_is_refused_when_it_breaks_the_annuity(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $loan = Loan::factory()->published()->create(['funded_amount' => 0]);
        $loan->forceFill(['term_months' => 240])->save();
        $offer = $loan->offers()->where('payout_type', PayoutType::Amortizing)->first();

        Livewire::test(OffersRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => EditLoan::class])
            ->callTableAction('edit', $offer, data: ['interest_rate' => '12.00'])
            ->assertHasActionErrors(['interest_rate']);
    }

    // ── PAY-03: retried withdrawal submits reserve the money once ──

    public function test_withdrawal_replay_with_the_same_key_reserves_the_money_once(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => '1000.00']);
        $key = (string) Str::uuid();
        $payload = ['amount' => 100, 'saved_iban_id' => $this->confirmedIban($user)->id];

        $first = $this->actingAs($user)->postJson('/api/withdrawal', $payload, ['X-Idempotency-Key' => $key])->assertStatus(201);
        $second = $this->actingAs($user)->postJson('/api/withdrawal', $payload, ['X-Idempotency-Key' => $key])->assertStatus(201);

        $this->assertSame($first->json('withdrawal.id'), $second->json('withdrawal.id'));
        $this->assertSame(1, WithdrawalRequest::where('user_id', $user->id)->count());
        $this->assertSame('100.00', (string) $user->wallet->fresh()->reserved);

        $other = $this->createVerifiedInvestor(['available' => '1000.00']);
        $this->actingAs($other)->postJson('/api/withdrawal', ['amount' => 100, 'saved_iban_id' => $this->confirmedIban($other)->id], ['X-Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertSame('0.00', (string) $other->wallet->fresh()->reserved);
    }

    public function test_withdrawal_the_fee_would_swallow_is_refused_at_request_time(): void
    {
        Notification::fake();
        PlatformSetting::set('fees_withdrawal_enabled', true);
        PlatformSetting::set('fees_withdrawal_amount', '15.00');
        $user = $this->createVerifiedInvestor(['available' => '100.00']);

        $this->actingAs($user)->postJson('/api/withdrawal', ['amount' => 12, 'saved_iban_id' => $this->confirmedIban($user)->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved, 'nothing may sit reserved for a request that can never be paid');
        $this->assertSame(0, WithdrawalRequest::where('user_id', $user->id)->count());
    }

    // ── PAY-15: who approved, who wired ──

    public function test_approval_and_processing_record_the_acting_admin(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = $this->createVerifiedInvestor(['available' => '500.00']);
        $service = app(WithdrawalService::class);
        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));

        $service->approve($withdrawal->id, $admin->id);
        $fresh = $withdrawal->fresh();
        $this->assertEquals($admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);

        $this->actingAs($admin);
        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('mark_processed', $fresh)
            ->assertNotified('Обработено');

        $this->assertEquals($admin->id, $withdrawal->fresh()->processed_by);
        $this->assertSame('processed', $withdrawal->fresh()->status);
    }

    // ── PAY-04: one admin submit = one closure ──

    public function test_duplicate_closure_submit_is_refused_by_the_request_token(): void
    {
        [$loan] = $this->activeOfferLoan();
        $service = app(EarlyClosureExecutionService::class);
        $token = (string) Str::uuid();

        $service->execute($loan->id, $this->admin()->id, '100.00', null, null, $token);
        $this->assertSame(1, LoanEarlyClosure::where('loan_id', $loan->id)->where('request_token', $token)->count());

        try {
            $service->execute($loan->id, $this->admin()->id, '100.00', null, null, $token);
            $this->fail('the same form token must not execute a second closure');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already been executed', $e->getMessage());
        }

        $this->assertSame(1, LoanEarlyClosure::where('loan_id', $loan->id)->count());
    }

    // ── Private links can be withdrawn ──

    public function test_rotating_the_private_link_revokes_link_grants_but_not_investors(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        [$loan, $investor] = $this->activeOfferLoan();
        $loan->forceFill(['visibility' => Loan::VISIBILITY_PRIVATE, 'share_token' => Loan::generateShareToken()])->save();
        $oldToken = $loan->share_token;

        $viewer = $this->createVerifiedInvestor();
        $loan->grantAccessTo($viewer);
        $this->assertTrue($loan->isAccessibleBy($viewer));

        Livewire::test(ListLoans::class)
            ->callTableAction('rotate_share_link', $loan)
            ->assertNotified('Нов линк е генериран');

        $fresh = $loan->fresh();
        $this->assertNotSame($oldToken, $fresh->share_token);
        $this->assertFalse($fresh->isAccessibleBy($viewer), 'a link-only viewer loses access with the old link');
        $this->assertTrue($fresh->isAccessibleBy($investor), 'an investor keeps access through the position');
    }

    // ── PAY-16: a failed payout run reaches the admins ──

    public function test_payout_run_failures_reach_the_admins_by_mail_bell_and_telegram(): void
    {
        Notification::fake();
        $admin = $this->admin();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '7']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->mock(ScheduledPayoutService::class, function ($mock) {
            $mock->shouldReceive('runAllAutomatic')->once()->andReturn([
                'loans_processed' => 2, 'loans_failed' => 1, 'failed_loan_ids' => [42],
            ]);
        });

        $this->assertSame(1, Artisan::call('loans:process-payouts'));

        Notification::assertSentTo($admin, PayoutRunFailedAdminNotification::class, fn ($n) => $n->failedLoanIds === [42] && $n->loansFailed === 1);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '#42'));
        $this->assertSame('failure', PlatformMetric::read('last_payouts_status'));
    }
}
