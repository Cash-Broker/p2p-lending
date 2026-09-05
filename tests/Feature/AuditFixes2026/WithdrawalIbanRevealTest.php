<?php

namespace Tests\Feature\AuditFixes2026;

use App\Exceptions\AdminReauthenticationException;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\WithdrawalRequestResource;
use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AdminReauthenticationService;
use App\Services\WithdrawalService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * SEC-11 (audit 2026-09-01, owner 2026-09-03): «Покажи IBAN» — the wire can be
 * made from the panel, behind the admin's own password, with an audit row.
 */
class WithdrawalIbanRevealTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    private const IBAN = 'BG80BNBG96611020345678';

    private const ADMIN_PASSWORD = 'Admin-Pass-123!';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'password' => Hash::make(self::ADMIN_PASSWORD)]);
    }

    private function withdrawal(bool $approve = true): WithdrawalRequest
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '500.00'])->save();
        $service = app(WithdrawalService::class);
        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user, self::IBAN));
        if ($approve) {
            $service->approve($withdrawal->id, $this->admin->id);
        }

        return $withdrawal->fresh();
    }

    private function auditRows(): int
    {
        return AuditLog::where('action', 'viewed')->where('model_type', WithdrawalRequest::class)->count();
    }

    public function test_the_action_is_offered_only_where_a_wire_is_made_or_reconciled(): void
    {
        $pending = $this->withdrawal(approve: false);
        $approved = $this->withdrawal();
        $processed = $this->withdrawal();
        DB::table('withdrawal_requests')->where('id', $processed->id)->update(['status' => 'processed']);
        $rejected = $this->withdrawal(approve: false);
        app(WithdrawalService::class)->reject($rejected->id, $this->admin->id, 'тест');

        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionHidden(TestAction::make('reveal_iban')->table($pending))
            ->assertActionHidden(TestAction::make('reveal_iban')->table($rejected->fresh()))
            ->assertActionVisible(TestAction::make('reveal_iban')->table($approved))
            ->assertActionVisible(TestAction::make('reveal_iban')->table($processed->fresh()));

        $this->assertSame(['approved', 'processed'], WithdrawalRequestResource::IBAN_REVEALABLE_STATUSES);
    }

    public function test_the_list_never_shows_the_full_iban(): void
    {
        $this->withdrawal();

        Livewire::test(ListWithdrawalRequests::class)
            ->assertSee('****5678')
            ->assertDontSee(self::IBAN);
    }

    public function test_wrong_password_reveals_nothing_and_writes_no_audit_row(): void
    {
        $withdrawal = $this->withdrawal();

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => 'not-the-password'])
            ->assertNotified('Грешна парола')
            ->call('unmountAction')
            ->assertActionHidden(TestAction::make('show_iban')->table($withdrawal))
            ->assertDontSee(self::IBAN);

        $this->assertSame(0, $this->auditRows());
    }

    public function test_correct_password_reveals_the_iban_and_records_who_looked(): void
    {
        $withdrawal = $this->withdrawal();

        $component = Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD])
            ->assertMountedActionModalSee(self::IBAN)
            ->assertMountedActionModalSee('100,00');

        $row = AuditLog::where('action', 'viewed')->where('model_type', WithdrawalRequest::class)->firstOrFail();
        $this->assertEquals($withdrawal->id, $row->model_id);
        $this->assertEquals($this->admin->id, $row->user_id);
        $this->assertSame('****5678', $row->new_values['iban_suffix']);
        $this->assertSame('bank_transfer', $row->new_values['purpose']);
        $this->assertStringNotContainsString(self::IBAN, json_encode($row->new_values), 'the trail records the suffix, never the account number');

        // Re-opening within the grant on the SAME page needs no password and is not a second reveal.
        $component
            ->call('unmountAction')
            ->assertActionVisible(TestAction::make('show_iban')->table($withdrawal))
            ->mountTableAction('show_iban', $withdrawal)
            ->assertMountedActionModalSee(self::IBAN);
        $this->assertSame(1, $this->auditRows());
    }

    public function test_a_fresh_page_load_has_no_grant(): void
    {
        $withdrawal = $this->withdrawal();
        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD]);

        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionHidden(TestAction::make('show_iban')->table($withdrawal))
            ->assertDontSee(self::IBAN);
    }

    public function test_the_grant_cannot_be_forged_from_the_browser(): void
    {
        $withdrawal = $this->withdrawal();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ListWithdrawalRequests::class)
            ->set('ibanRevealGrant', ['id' => $withdrawal->id, 'expires' => PHP_INT_MAX]);
    }

    public function test_the_grant_expires_after_two_minutes_and_is_bound_to_one_request(): void
    {
        $a = $this->withdrawal();
        $b = $this->withdrawal();

        $component = Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $a, data: ['password' => self::ADMIN_PASSWORD])
            ->call('unmountAction')
            ->assertActionVisible(TestAction::make('show_iban')->table($a))
            ->assertActionHidden(TestAction::make('show_iban')->table($b));

        $this->travel(121)->seconds();

        $component->assertActionHidden(TestAction::make('show_iban')->table($a));
    }

    public function test_five_wrong_passwords_lock_the_admin_out_even_for_the_right_one(): void
    {
        $withdrawal = $this->withdrawal();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(ListWithdrawalRequests::class)
                ->callTableAction('reveal_iban', $withdrawal, data: ['password' => 'wrong-'.$i])
                ->assertNotified('Грешна парола');
        }

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD])
            ->assertNotified('Достъпът е временно заключен')
            ->assertDontSee(self::IBAN);

        $this->assertSame(0, $this->auditRows());
    }

    public function test_the_lockout_sends_one_telegram_alert_without_the_admin_name(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '7']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $service = app(AdminReauthenticationService::class);

        for ($i = 0; $i < 4; $i++) {
            try {
                $service->verify($this->admin, 'wrong', 'test');
            } catch (AdminReauthenticationException) {
            }
        }
        Http::assertNothingSent();

        try {
            $service->verify($this->admin, 'wrong', 'test');
        } catch (AdminReauthenticationException) {
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], "#{$this->admin->id}")
            && ! str_contains((string) $request['text'], $this->admin->name));
    }

    public function test_failures_count_per_admin_not_per_ip(): void
    {
        $other = $this->makeAdmin();
        $service = app(AdminReauthenticationService::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->verify($this->admin, 'wrong', 'test');
            } catch (AdminReauthenticationException) {
            }
        }

        // The other admin, same machine, is untouched; the locked one stays locked.
        $service->verify($other, self::ADMIN_PASSWORD, 'test');
        $this->expectException(AdminReauthenticationException::class);
        $service->verify($this->admin, self::ADMIN_PASSWORD, 'test');
    }

    public function test_the_status_gate_is_rechecked_inside_the_action(): void
    {
        // The modal was opened on an approved row; the row changed meanwhile.
        $withdrawal = $this->withdrawal();
        $component = Livewire::test(ListWithdrawalRequests::class)->mountTableAction('reveal_iban', $withdrawal);
        DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['status' => 'rejected']);

        // Filament re-resolves the row and drops the call once the action is no
        // longer visible; the in-closure status re-check is the belt behind it.
        $component
            ->setTableActionData(['password' => self::ADMIN_PASSWORD])
            ->callMountedTableAction()
            ->assertDontSee(self::IBAN);

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame(0, $this->auditRows());
    }

    public function test_the_modal_shows_the_net_amount_from_the_ledger_when_a_fee_applies(): void
    {
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $withdrawal = $this->withdrawal();
        $this->assertSame('2.50', (string) $withdrawal->fee_quoted);

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD])
            ->assertMountedActionModalSee('97,50')
            ->assertMountedActionModalSee('такса 2.50');
    }

    public function test_the_modal_names_the_previous_reveal(): void
    {
        $withdrawal = $this->withdrawal();

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD])
            ->assertMountedActionModalDontSee('Показван преди това');

        $second = $this->makeAdmin();
        $this->actingAs($second);
        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD])
            ->assertMountedActionModalSee('Показван преди това от '.$this->admin->name);

        $this->assertSame(2, $this->auditRows());
    }

    public function test_the_audit_log_page_describes_the_reveal_in_bulgarian(): void
    {
        $withdrawal = $this->withdrawal();
        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reveal_iban', $withdrawal, data: ['password' => self::ADMIN_PASSWORD]);

        Livewire::test(ListAuditLogs::class)
            ->assertSee("IBAN показан за теглене #{$withdrawal->id}")
            ->assertSee('Преглед')
            ->assertDontSee(self::IBAN);
    }
}
