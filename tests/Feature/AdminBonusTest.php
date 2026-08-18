<?php

namespace Tests\Feature;

use App\Filament\Resources\DepositRequestResource\Pages\ListDepositRequests;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\BonusGrant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BonusCreditedNotification;
use App\Notifications\BonusGrantedAdminNotification;
use App\Services\TelegramService;
use App\Services\WalletService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Начисли бонус» (boss 2026-08-09, reworked by Reni 2026-08-18): the admin
 * grants promotional credit without a deposit code. The money is spendable
 * balance at once — the investor may invest it — but a `bonus_grants` row
 * records the base it must be earned against before it can be WITHDRAWN.
 * The condition and the withdrawal floor are BonusLockTest's subject; this
 * file covers the grant itself.
 */
class AdminBonusTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '0.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_grants_bonus_via_view_user_action(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdmin();
        $otherAdmin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $user = $this->investor('50.00');

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: [
                'amount' => '150',
                'base_amount' => '5000',
                'reason' => 'Бонус за препоръчан клиент',
            ])
            ->assertHasNoActionErrors();

        // The bonus is investable at once — it joins the balance — while the
        // grant row keeps the condition that gates WITHDRAWING it.
        $this->assertSame('200.00', $user->wallet->fresh()->available);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BONUS_LOCKED,
            'amount' => '150.00',
            'description' => 'Бонус: Бонус за препоръчан клиент',
        ]);
        $this->assertDatabaseHas('bonus_grants', [
            'user_id' => $user->id,
            'amount' => '150.00',
            'base_amount' => '5000.00',
            'required_installments' => 3,
            'source' => BonusGrant::SOURCE_ADMIN,
            'granted_by' => $admin->id,
            'status' => BonusGrant::STATUS_LOCKED,
        ]);

        // Per-grant unique reference: admin id prefix + uuid suffix.
        $reference = Transaction::where('user_id', $user->id)->value('reference');
        $this->assertMatchesRegularExpression(
            "/^bonus:admin:{$admin->id}:[0-9a-f-]{36}$/",
            $reference,
        );

        Notification::assertSentTo($user, BonusCreditedNotification::class);
        // Internal control: the OTHER admins are alerted, the actor is not.
        Notification::assertSentTo($otherAdmin, BonusGrantedAdminNotification::class);
        Notification::assertNotSentTo($admin, BonusGrantedAdminNotification::class);
    }

    public function test_identical_grant_within_two_minutes_is_blocked(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $user = $this->investor();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '100', 'base_amount' => '2000', 'reason' => 'Реферал'])
            ->assertHasNoActionErrors();

        // Same (user, amount) again straight away — replay guard blocks it.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '100', 'base_amount' => '2000', 'reason' => 'Реферал'])
            ->assertHasNoActionErrors();

        $this->assertSame('100.00', $user->wallet->fresh()->available);
        $this->assertSame(1, Transaction::where('user_id', $user->id)->count());
        $this->assertSame(1, BonusGrant::where('user_id', $user->id)->count());

        // A DIFFERENT amount is not a replay — goes through immediately.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '50', 'base_amount' => '1000', 'reason' => 'Друга кампания'])
            ->assertHasNoActionErrors();

        $this->assertSame('150.00', $user->wallet->fresh()->available);
    }

    public function test_reason_longer_than_248_chars_is_rejected(): void
    {
        $this->actingAsAdmin();
        $user = $this->investor();

        // 249 chars + the 7-char «Бонус: » prefix would overflow the
        // VARCHAR(255) description column mid-transaction.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: [
                'amount' => '100', 'base_amount' => '1000', 'reason' => str_repeat('х', 249),
            ])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
    }

    public function test_bonus_action_rejects_missing_or_invalid_input(): void
    {
        $this->actingAsAdmin();
        $user = $this->investor();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '', 'base_amount' => '', 'reason' => ''])
            ->assertHasActionErrors(['amount', 'base_amount', 'reason']);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '-100', 'base_amount' => '1000', 'reason' => 'x'])
            ->assertHasActionErrors(['amount']);

        // Fat-finger guard: five-digit bonuses are almost certainly typos.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '10001', 'base_amount' => '20000', 'reason' => 'x'])
            ->assertHasActionErrors(['amount']);

        // A base below the bonus itself is nonsense — it would unlock the
        // bonus with an investment smaller than the reward.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '500', 'base_amount' => '100', 'reason' => 'x'])
            ->assertHasActionErrors(['base_amount']);

        $this->assertSame('0.00', $user->wallet->fresh()->available);
        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
    }

    public function test_bonus_action_hidden_for_admin_records(): void
    {
        $this->actingAsAdmin();
        $otherAdmin = User::factory()->admin()->create(['email_verified_at' => now()]);

        Livewire::test(ViewUser::class, ['record' => $otherAdmin->id])
            ->assertActionHidden('grant_bonus');
    }

    public function test_wallet_service_conditional_bonus_credits_the_balance(): void
    {
        $user = $this->investor('10.00');

        $tx = app(WalletService::class)->bonusLocked($user->id, '99.50', 'Бонус: тест', 'bonus:admin:1');

        $this->assertSame('109.50', $user->wallet->fresh()->available);
        // Own ledger type: conditional grants stay distinguishable from the
        // pre-2026-08-18 free ones.
        $this->assertSame(Transaction::TYPE_BONUS_LOCKED, $tx->type);
        $this->assertSame('99.50', (string) $tx->amount);
    }

    public function test_wallet_service_bonus_rejects_non_positive_amount(): void
    {
        $user = $this->investor();

        $this->expectException(InvalidArgumentException::class);
        app(WalletService::class)->bonusLocked($user->id, '0.00', 'Бонус: тест');
    }

    public function test_legacy_free_bonus_rows_still_credit_available(): void
    {
        // Bonuses granted before 2026-08-18 were spendable on arrival. Their
        // rows keep that meaning — terms are not rewritten retroactively.
        // Balance starts at zero so the ledger check below has nothing but the
        // bonus row to reconcile against.
        $user = $this->investor();

        $tx = app(WalletService::class)->bonus($user->id, '40.00', 'Бонус: заварен');

        $this->assertSame('40.00', $user->wallet->fresh()->available);
        $this->assertSame(Transaction::TYPE_BONUS, $tx->type);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_ledger_reconciles_with_bonus_rows(): void
    {
        $user = $this->investor();
        app(WalletService::class)->bonusLocked($user->id, '200.00', 'Бонус: реферал');

        // Default-deny reconciliation: an unmapped type would exit non-zero.
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_bonus_grant_posts_a_telegram_record(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $user = $this->investor();

        $this->mock(TelegramService::class)
            ->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $title, string $body) => $title === 'Начислен бонус'
                && str_contains($body, '100.00 €'))
            ->andReturn(true);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '100', 'base_amount' => '3000', 'reason' => 'Реферал'])
            ->assertHasNoActionErrors();
    }

    public function test_bonus_granted_from_deposits_page_via_user_picker(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $user = $this->investor('10.00');

        Livewire::test(ListDepositRequests::class)
            ->callAction(TestAction::make('grant_bonus')->table(), data: [
                'user_id' => $user->id,
                'amount' => '200',
                'base_amount' => '10000',
                'reason' => 'Доведен клиент',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('210.00', $user->wallet->fresh()->available);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BONUS_LOCKED,
            'amount' => '200.00',
            'description' => 'Бонус: Доведен клиент',
        ]);
        $this->assertDatabaseHas('bonus_grants', [
            'user_id' => $user->id,
            'base_amount' => '10000.00',
            'status' => BonusGrant::STATUS_LOCKED,
        ]);

        Notification::assertSentTo($user, BonusCreditedNotification::class);
    }

    public function test_deposits_page_bonus_requires_a_user_and_rejects_non_investors(): void
    {
        $admin = $this->actingAsAdmin();
        $user = $this->investor();

        // Missing user — form validation.
        Livewire::test(ListDepositRequests::class)
            ->callAction(TestAction::make('grant_bonus')->table(), data: [
                'user_id' => null,
                'amount' => '100',
                'base_amount' => '1000',
                'reason' => 'Тест',
            ])
            ->assertHasActionErrors(['user_id']);

        // An admin account is not a valid bonus target.
        Livewire::test(ListDepositRequests::class)
            ->callAction(TestAction::make('grant_bonus')->table(), data: [
                'user_id' => $admin->id,
                'amount' => '100',
                'base_amount' => '1000',
                'reason' => 'Тест',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('0.00', $user->wallet->fresh()->available);
        $this->assertSame(0, Transaction::count());
    }

    public function test_investor_sees_bonus_in_transactions_and_can_filter_it(): void
    {
        $user = $this->investor();
        app(WalletService::class)->bonusLocked($user->id, '120.00', 'Бонус: кампания');

        $response = $this->actingAs($user)->getJson('/api/transactions?type[]=bonus_locked');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.type', 'bonus_locked')
            ->assertJsonPath('data.0.amount', '120.00');
    }
}
