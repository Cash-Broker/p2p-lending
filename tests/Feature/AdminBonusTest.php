<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BonusCreditedNotification;
use App\Notifications\BonusGrantedAdminNotification;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Начисли бонус» (boss 2026-08-09): admin grants promotional credit into
 * the investor's available balance without a deposit code. New TYPE_BONUS
 * ledger row — spendable like a deposit, excluded from bank-statement
 * reconciliation, visible to the investor as «Бонус».
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
            ->callAction('grant_bonus', data: ['amount' => '150', 'reason' => 'Бонус за препоръчан клиент'])
            ->assertHasNoActionErrors();

        $this->assertSame('200.00', $user->wallet->fresh()->available);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BONUS,
            'amount' => '150.00',
            'description' => 'Бонус: Бонус за препоръчан клиент',
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
            ->callAction('grant_bonus', data: ['amount' => '100', 'reason' => 'Реферал'])
            ->assertHasNoActionErrors();

        // Same (user, amount) again straight away — replay guard blocks it.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '100', 'reason' => 'Реферал'])
            ->assertHasNoActionErrors();

        $this->assertSame('100.00', $user->wallet->fresh()->available);
        $this->assertSame(1, Transaction::where('user_id', $user->id)->count());

        // A DIFFERENT amount is not a replay — goes through immediately.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '50', 'reason' => 'Друга кампания'])
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
            ->callAction('grant_bonus', data: ['amount' => '100', 'reason' => str_repeat('х', 249)])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
    }

    public function test_bonus_action_rejects_missing_or_invalid_input(): void
    {
        $this->actingAsAdmin();
        $user = $this->investor();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '', 'reason' => ''])
            ->assertHasActionErrors(['amount', 'reason']);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '-100', 'reason' => 'x'])
            ->assertHasActionErrors(['amount']);

        // Fat-finger guard: five-digit bonuses are almost certainly typos.
        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('grant_bonus', data: ['amount' => '10001', 'reason' => 'x'])
            ->assertHasActionErrors(['amount']);

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

    public function test_wallet_service_bonus_credits_available_and_writes_ledger_row(): void
    {
        $user = $this->investor('10.00');

        $tx = app(WalletService::class)->bonus($user->id, '99.50', 'Бонус: тест', 'bonus:admin:1');

        $this->assertSame('109.50', $user->wallet->fresh()->available);
        $this->assertSame(Transaction::TYPE_BONUS, $tx->type);
        $this->assertSame('99.50', (string) $tx->amount);
    }

    public function test_wallet_service_bonus_rejects_non_positive_amount(): void
    {
        $user = $this->investor();

        $this->expectException(InvalidArgumentException::class);
        app(WalletService::class)->bonus($user->id, '0.00', 'Бонус: тест');
    }

    public function test_ledger_reconciles_with_bonus_rows(): void
    {
        $user = $this->investor();
        app(WalletService::class)->bonus($user->id, '200.00', 'Бонус: реферал');

        // Default-deny reconciliation: an unmapped type would exit non-zero.
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_investor_sees_bonus_in_transactions_and_can_filter_it(): void
    {
        $user = $this->investor();
        app(WalletService::class)->bonus($user->id, '120.00', 'Бонус: кампания');

        $response = $this->actingAs($user)->getJson('/api/transactions?type[]=bonus');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.type', 'bonus')
            ->assertJsonPath('data.0.amount', '120.00');
    }
}
