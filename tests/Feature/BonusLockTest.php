<?php

namespace Tests\Feature;

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
use App\Services\PromotionService;
use App\Services\WithdrawalService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conditional bonuses — the release rule (Reni 2026-08-18).
 *
 * «бонуса може да се тегли след направена инвестиция в необходимия размер и
 * след третия падеж»: the bonus unlocks when the investments made after the
 * grant, on which the investor has already RECEIVED three scheduled payments,
 * add up to the base it was calculated on. The amount may be spread over
 * several loans; a capitalized plan pays once, at maturity, and unlocks then.
 */
class BonusLockTest extends TestCase
{
    use RefreshDatabase;

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

        Artisan::call('bonuses:release-eligible');

        $wallet = $user->wallet->fresh();
        $this->assertSame('50.00', $wallet->available);
        $this->assertSame('0.00', $wallet->bonus_locked);

        $grant->refresh();
        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->status);
        $this->assertNotNull($grant->released_at);
        $this->assertDatabaseHas('transactions', [
            'id' => $grant->release_transaction_id,
            'type' => Transaction::TYPE_BONUS_RELEASED,
            'amount' => '50.00',
            'reference' => "bonus_grant:{$grant->id}:release",
        ]);

        Notification::assertSentTo($user, BonusReleasedNotification::class);
    }

    public function test_bonus_stays_locked_while_the_invested_sum_is_below_the_base(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        $this->investmentWithInstallments($user, '3000.00', total: 12, paid: 6);

        Artisan::call('bonuses:release-eligible');

        $this->assertSame('0.00', $user->wallet->fresh()->available);
        $this->assertSame('50.00', $user->wallet->fresh()->bonus_locked);
        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);
    }

    public function test_bonus_waits_for_the_third_installment(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');

        $investment = $this->investmentWithInstallments($user, '5000.00', total: 12, paid: 2);

        Artisan::call('bonuses:release-eligible');
        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);
        $this->assertSame('50.00', $user->wallet->fresh()->bonus_locked);

        // The third payout lands — the next sweep frees the bonus.
        $this->markInstallmentPaid($investment, 3);
        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_RELEASED, $grant->fresh()->status);
        $this->assertSame('50.00', $user->wallet->fresh()->available);
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
        $this->assertSame('50.00', $user->wallet->fresh()->available);
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
        $this->assertSame('50.00', $user->wallet->fresh()->bonus_locked);
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

        // 2% of 5000 — locked, not spendable.
        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->available);
        $this->assertSame('100.00', $wallet->bonus_locked);

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
        $this->assertSame('100.00', $user->wallet->fresh()->available);
    }

    // ── The money guarantees ──

    public function test_a_locked_bonus_cannot_be_withdrawn(): void
    {
        $user = $this->investor();
        $this->grant($user, '100.00', '5000.00');

        // 100 € sit in the wallet, but not one cent of it is withdrawable:
        // the withdrawal path reads `available`, which the bonus never entered.
        $this->expectException(ValidationException::class);
        app(WithdrawalService::class)->createRequest($user->id, '50.00', 'BG80BNBG96611020345678');
    }

    public function test_release_is_idempotent(): void
    {
        $user = $this->investor();
        $grant = $this->grant($user, '50.00', '5000.00');
        $this->investmentWithInstallments($user, '5000.00', total: 12, paid: 3);

        Artisan::call('bonuses:release-eligible');
        Artisan::call('bonuses:release-eligible');
        app(BonusService::class)->release($grant->fresh());

        $this->assertSame('50.00', $user->wallet->fresh()->available);
        $this->assertSame(1, Transaction::where('type', Transaction::TYPE_BONUS_RELEASED)->count());
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

        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->available);
        $this->assertSame('0.00', $wallet->bonus_locked);

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
        app(AccountDeletionService::class)->deleteAccount($user, 'secret-password');

        $this->assertSame(BonusGrant::STATUS_CANCELLED, $grant->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BONUS_CANCELLED,
            'amount' => '40.00',
        ]);
        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
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
        $this->assertSame('0.00', $user->wallet->fresh()->bonus_locked);
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

        $this->assertSame('0.00', $response->json('wallet.available'));
        $this->assertSame('50.00', $response->json('wallet.bonus_locked'));
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
            ->assertJsonPath('wallet.bonus_locked', '0.00');
    }
}
