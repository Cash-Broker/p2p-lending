<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\BonusGrantResource\Pages\ListBonusGrants;
use App\Models\BonusGrant;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanPromotion;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BonusReleasedNotification;
use App\Services\AccountDeletionService;
use App\Services\BonusService;
use App\Services\InvestmentService;
use App\Services\PromotionService;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * Conditional bonuses — the release rule (Reni 2026-08-18).
 *
 * «бонуса може да се тегли след направена инвестиция в необходимия размер и
 * след третия падеж»: the bonus is spendable balance from the start — it can
 * be INVESTED at once — but WITHDRAWING it waits until the investments made
 * after the grant, on which the investor has already RECEIVED three scheduled
 * payments, add up to the base it was calculated on. The amount may be spread
 * over several loans; a capitalized plan pays once, at maturity.
 */
class BonusLockTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    private function investor(string $available = '0.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function grant(User $user, string $amount = '50.00', string $base = '5000.00'): BonusGrant
    {
        $adminId = $this->admin()->id;

        return app(BonusService::class)->grantAdminBonus(
            $user, $amount, $base, 'Реферал', $adminId, "bonus:admin:{$adminId}:".uniqid(),
        );
    }

    /**
     * An investment with a generated schedule: $total rows, the first $paid of
     * them already settled — exactly the shape the payout engine leaves behind.
     */
    private function investmentWithInstallments(
        User $user,
        string $amount,
        int $total,
        int $paid,
        ?Carbon $investedAt = null,
    ): Investment {
        $loan = Loan::factory()->active()->create();

        $investment = Investment::factory()->create([
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'amount' => $amount,
            'invested_at' => $investedAt ?? now(),
        ]);

        for ($month = 1; $month <= $total; $month++) {
            InvestmentSchedule::create([
                'investment_id' => $investment->id,
                'loan_id' => $loan->id,
                'due_date' => now()->addMonths($month)->toDateString(),
                'principal' => '10.00',
                'interest' => '1.00',
                'total' => '11.00',
                'status' => $month <= $paid ? 'paid' : 'pending',
                'paid_at' => $month <= $paid ? now() : null,
            ]);
        }

        return $investment;
    }

    private function markInstallmentPaid(Investment $investment, int $month): void
    {
        InvestmentSchedule::where('investment_id', $investment->id)
            ->orderBy('due_date')
            ->skip($month - 1)
            ->take(1)
            ->get()
            ->each(fn (InvestmentSchedule $row) => $row->update(['status' => 'paid', 'paid_at' => now()]));
    }

    // ── The release rule ──

    public function test_bonus_is_released_when_investments_across_loans_reach_the_base(): void
    {
        Notification::fake();
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        // «не е задължително в 1» — the sum is what counts.
        $this->investmentWithInstallments($user, '3000.00', total: 12, paid: 3);
        $this->investmentWithInstallments($user, '2000.00', total: 12, paid: 3);

        // The money is in the balance from the grant, but none of it is
        // withdrawable while the condition is open.
        $this->assertSame('0.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));

        Artisan::call('bonuses:release-eligible');

        $grant->refresh();
        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->status);
        $this->assertNotNull($grant->released_at);

        // Releasing moves no money — it only drops the floor. A second ledger
        // row here would mint the bonus twice.
        $this->assertSame('50.00', $user->wallet->fresh()->available);
        $this->assertSame('50.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));
        $this->assertSame(0, Transaction::where('type', Transaction::TYPE_BONUS_RELEASED)->count());

        Notification::assertSentTo($user, BonusReleasedNotification::class);
    }

    public function test_bonus_stays_locked_while_the_invested_sum_is_below_the_base(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        $this->investmentWithInstallments($user, '3000.00', total: 12, paid: 6);

        Artisan::call('bonuses:release-eligible');

        $this->assertSame('50.00', $user->wallet->fresh()->available);
        $this->assertSame('0.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));
        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);
    }

    public function test_bonus_waits_for_the_third_installment(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        $investment = $this->investmentWithInstallments($user, '5000.00', total: 12, paid: 2);

        Artisan::call('bonuses:release-eligible');
        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);

        // The third payout lands — the next sweep frees the bonus.
        $this->markInstallmentPaid($investment, 3);
        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->fresh()->status);
        $this->assertSame('50.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));
    }

    public function test_capitalized_investment_unlocks_on_its_single_maturity_installment(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        // Capitalized plans generate ONE row, at maturity. Three installments
        // will never exist, so the last one is the trigger (Reni: «бонусът се
        // отключва [на падежа]»).
        $investment = $this->investmentWithInstallments($user, '5000.00', total: 1, paid: 0);

        Artisan::call('bonuses:release-eligible');
        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);

        $this->markInstallmentPaid($investment, 1);
        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->fresh()->status);
        $this->assertSame('50.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));
    }

    public function test_investments_made_before_the_grant_do_not_count(): void
    {
        $user = $this->investor();

        // An old position the investor already held: the bonus is meant to
        // bring NEW money in, so it must not be unlocked by history.
        $this->investmentWithInstallments(
            $user, '5000.00', total: 12, paid: 6, investedAt: now()->subMonths(6),
        );

        $grant = $this->grant($user, '50.00', '5000.00');

        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);
        $this->assertSame('0.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));
    }

    public function test_an_investment_without_a_schedule_yet_does_not_qualify(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        // Funding loan: money committed, schedules not generated yet — nothing
        // has been served, so nothing unlocks.
        Investment::factory()->create([
            'user_id' => $user->id,
            'loan_id' => Loan::factory()->funding()->create()->id,
            'amount' => '5000.00',
        ]);

        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);
    }

    // ── Promo bonuses follow the same rule ──

    public function test_promo_bonus_is_locked_and_unlocked_by_its_own_investment(): void
    {
        Notification::fake();
        $user = $this->investor();

        $investment = $this->investmentWithInstallments($user, '5000.00', total: 12, paid: 0);
        $promotion = LoanPromotion::create([
            'loan_id' => $investment->loan_id,
            'bonus_percent' => '2.00',
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addMinutes(30),
            'created_by' => User::factory()->admin()->create()->id,
        ]);

        app(PromotionService::class)->grantInvestBonus($investment, $investment->loan, $user);

        // 2% of 5000 — in the balance (investable), not withdrawable yet.
        $wallet = $user->wallet->fresh();
        $this->assertSame('100.00', $wallet->available);
        $this->assertSame('0.00', app(WalletService::class)->withdrawableBalance($wallet));

        $grant = BonusGrant::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(BonusGrant::SOURCE_PROMO, $grant->source);
        $this->assertSame($promotion->id, $grant->loan_promotion_id);
        // The investment that earned the bonus IS the qualifying one — the
        // investor does not have to invest a second time.
        $this->assertSame('5000.00', (string) $grant->base_amount);

        foreach ([1, 2, 3] as $month) {
            $this->markInstallmentPaid($investment, $month);
        }
        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->fresh()->status);
        $this->assertSame('100.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));
    }

    // ── The money guarantees ──

    public function test_a_locked_bonus_cannot_be_withdrawn(): void
    {
        $user = $this->investor('200.00');
        $this->grant($user, '100.00', '5000.00');

        // Balance 300 €, of which 100 € is an unearned bonus: the floor in
        // WalletService::reserve() keeps withdrawals to the other 200 €.
        $this->assertSame('300.00', $user->wallet->fresh()->available);
        $this->assertSame('200.00', app(WalletService::class)->withdrawableBalance($user->wallet->fresh()));

        app(WithdrawalService::class)->createRequest($user->id, '200.00', $this->confirmedIban($user));

        $this->expectException(ValidationException::class);
        app(WithdrawalService::class)->createRequest($user->id, '0.01', $this->confirmedIban($user));
    }

    public function test_a_locked_bonus_can_be_invested_immediately(): void
    {
        // Reni 2026-08-18: «може ли бонусът да се инвестира» — yes, from the
        // first second; only cashing out waits for the condition.
        $user = $this->investor('4900.00');
        $this->grant($user, '100.00', '5000.00');

        $loan = Loan::factory()->published()->create([
            'amount' => '10000.00', 'funded_amount' => 0, 'term_months' => 6,
        ]);

        $investment = app(InvestmentService::class)->invest(
            $user, $loan, '5000.00', 'bonus-invest-'.uniqid(),
            $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id'),
        );

        $this->assertSame('5000.00', (string) $investment->amount);
        $this->assertSame('0.00', $user->wallet->fresh()->available);
        $this->assertSame('5000.00', $user->wallet->fresh()->invested);
    }

    public function test_release_is_idempotent(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');
        $this->investmentWithInstallments($user, '5000.00', total: 12, paid: 3);

        Artisan::call('bonuses:release-eligible');
        Artisan::call('bonuses:release-eligible');
        app(BonusService::class)->release($grant->fresh());

        // Releasing is a status flip, so running it three times must still
        // leave one released grant and an untouched balance.
        $this->assertSame('50.00', $user->wallet->fresh()->available);
        $this->assertSame(1, BonusGrant::where('status', BonusGrant::STATUS_RELEASED)->count());
        $this->assertSame(1, Transaction::where('user_id', $user->id)->count());
    }

    public function test_ledger_reconciles_through_the_whole_bonus_lifecycle(): void
    {
        $released = $this->investor();
        $grant = $this->grant($released, '50.00', '5000.00');
        $this->investmentWithInstallments($released, '5000.00', total: 12, paid: 3);
        Artisan::call('bonuses:release-eligible');

        $cancelled = $this->investor();
        app(BonusService::class)->cancel($this->grant($cancelled, '30.00', '1000.00'), $this->admin()->id, 'Тест');

        $stillLocked = $this->investor();
        $this->grant($stillLocked, '20.00', '1000.00');

        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->fresh()->status);
        // Default-deny map: a bucket the map cannot explain fails the command.
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_cancel_writes_the_bonus_off_without_paying_it(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '75.00', '5000.00');

        app(BonusService::class)->cancel($grant, $this->admin()->id, 'Злоупотреба');

        // Debited back out of the balance — the grant is undone, not parked.
        $this->assertSame('0.00', $user->wallet->fresh()->available);

        $grant->refresh();
        $this->assertSame(BonusGrant::STATUS_CANCELLED, $grant->status);
        $this->assertSame('Злоупотреба', $grant->cancel_reason);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BONUS_CANCELLED,
            'amount' => '75.00',
        ]);

        // A cancelled grant is not releasable afterwards.
        $this->assertNull(app(BonusService::class)->release($grant));
    }

    public function test_account_closure_forfeits_a_locked_bonus_instead_of_blocking(): void
    {
        $user = User::factory()->kycApproved()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('secret-password'),
        ]);
        $user->wallet()->create();
        $grant = $this->grant($user, '40.00', '5000.00');

        // A locked bonus must not trap the investor inside the platform:
        // unlocking it would require investing.
        // SEC-22: the request-time check uses the WITHDRAWABLE balance, so the
        // locked bonus inside `available` does not refuse the request; the
        // forfeit itself happens at finalisation.
        $service = app(AccountDeletionService::class);
        $service->requestDeletion($user, 'secret-password');
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id, 'type' => Transaction::TYPE_BONUS_CANCELLED]);
        $service->confirm($user->fresh());
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame('finalized', $service->finalize($user->fresh()));
        Carbon::setTestNow();

        $this->assertSame(BonusGrant::STATUS_CANCELLED, $grant->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BONUS_CANCELLED,
            'amount' => '40.00',
        ]);
        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
    }

    public function test_account_closure_is_refused_with_the_normal_message_when_the_bonus_is_invested(): void
    {
        $user = User::factory()->kycApproved()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('secret-password'),
        ]);
        $user->wallet()->create();
        $this->grant($user, '50.00', '5000.00');

        // Bonus put to work: nothing left in the balance to write off. The
        // closure must still fail with the investor-facing message, not with a
        // raw exception from the write-off attempt.
        $user->wallet->forceFill(['available' => '0.00', 'invested' => '50.00'])->save();

        $this->expectException(ValidationException::class);
        app(AccountDeletionService::class)->requestDeletion($user, 'secret-password');
    }

    // ── What the admin sees ──

    public function test_admin_register_lists_grants_and_cancels_a_locked_one(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '60.00', '4000.00');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());

        Livewire::test(ListBonusGrants::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$grant])
            ->callAction(TestAction::make('cancel_bonus')->table($grant), data: ['reason' => 'Дублирано начисляване'])
            ->assertHasNoActionErrors();

        $this->assertSame(BonusGrant::STATUS_CANCELLED, $grant->fresh()->status);
        $this->assertSame('0.00', $user->wallet->fresh()->available);
    }

    public function test_released_grants_cannot_be_cancelled_from_the_register(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');
        $this->investmentWithInstallments($user, '5000.00', total: 12, paid: 3);
        Artisan::call('bonuses:release-eligible');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());

        // Released money is the investor's — the action is gone, not merely
        // guarded.
        Livewire::test(ListBonusGrants::class)
            ->assertActionHidden(TestAction::make('cancel_bonus')->table($grant->fresh()));
    }

    // ── What the investor sees ──

    public function test_dashboard_reports_the_locked_bonus_and_what_is_left(): void
    {
        $user = $this->investor();
        $this->grant($user, '50.00', '5000.00');
        $this->investmentWithInstallments($user, '2000.00', total: 12, paid: 3);

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertSame('50.00', $response->json('wallet.available'));
        // …none of which is withdrawable yet.
        $this->assertSame('0.00', $response->json('wallet.withdrawable'));
        $this->assertSame('50.00', $response->json('locked_bonus.amount'));
        $this->assertSame('5000.00', $response->json('locked_bonus.base_amount'));
        $this->assertSame('2000.00', $response->json('locked_bonus.qualified_amount'));
        $this->assertSame('3000.00', $response->json('locked_bonus.remaining_amount'));
        $this->assertSame(3, $response->json('locked_bonus.required_installments'));
    }

    public function test_dashboard_omits_the_block_when_nothing_is_locked(): void
    {
        $user = $this->investor();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('locked_bonus', null)
            ->assertJsonPath('wallet.withdrawable', '0.00');
    }
}
