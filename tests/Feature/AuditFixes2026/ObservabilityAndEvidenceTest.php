<?php

namespace Tests\Feature\AuditFixes2026;

use App\Enums\PayoutType;
use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\BeneficialOwner;
use App\Models\Investment;
use App\Models\InvestmentContract;
use App\Models\InvestmentSchedule;
use App\Models\LegalEntityProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\PlatformMetric;
use App\Models\SavedIban;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\DepositService;
use App\Services\InvestmentContractService;
use App\Services\InvestmentService;
use App\Services\TelegramService;
use App\Services\WalletService;
use App\Traits\Auditable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Audit 2026-09-01, packages A3 (observation) and A4 (evidence).
 *
 * Nothing here changes what the platform pays or when; it changes what the
 * platform REMEMBERS and what it TELLS the admin.
 */
class ObservabilityAndEvidenceTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array{0: User, 1: Investment, 2: InvestmentContract} */
    private function investedContract(): array
    {
        Notification::fake();
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $user = $this->createVerifiedInvestor();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '500.00', (string) Str::uuid(), $offerId);

        $investment = Investment::where('user_id', $user->id)->firstOrFail();

        return [$user, $investment, $investment->contract];
    }

    // ── A3: Telegram / queue ──

    public function test_telegram_failure_log_never_contains_the_bot_token(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: https://api.telegram.org/botSECRET-TOKEN-123/sendMessage timed out');
        });
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Telegram exception'
                && ! str_contains($context['error'], 'SECRET-TOKEN-123')
                && str_contains($context['error'], '[bot-token]');
        });

        $this->assertFalse((new TelegramService('SECRET-TOKEN-123', '42'))->sendMessage('hello'));
    }

    public function test_queue_busy_event_reaches_telegram_and_the_health_metric(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '7']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        event(new QueueBusy('database', 'default', 150));

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '150'));
        $this->assertSame('150', PlatformMetric::read('last_queue_busy_size'));
        $this->assertNotNull(PlatformMetric::measuredAt('last_queue_busy_at'));
    }

    // ── A4: contract template integrity ──

    public function test_contract_records_the_template_hash_and_refuses_to_render_after_a_template_edit(): void
    {
        [, , $contract] = $this->investedContract();

        $expected = hash_file('sha256', resource_path('views/contracts/investment-v1.blade.php'));
        $this->assertSame($expected, $contract->template_hash);
        $this->assertSame($expected, InvestmentContractService::templateHash(InvestmentContract::TEMPLATE_VERSION_V1));

        // A silent edit of v1 after conclusion: the file no longer matches what was accepted.
        DB::table('investment_contracts')->where('id', $contract->id)->update(['template_hash' => str_repeat('0', 64)]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('modified after contract');
        app(InvestmentContractService::class)->renderPdf($contract->fresh());
    }

    public function test_contracts_concluded_before_the_hash_existed_still_render(): void
    {
        [, , $contract] = $this->investedContract();
        DB::table('investment_contracts')->where('id', $contract->id)->update(['template_hash' => null]);

        $pdf = app(InvestmentContractService::class)->renderPdf($contract->fresh());

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    // ── A4: who looked at what ──

    public function test_contract_views_leave_an_audit_trail_for_admin_and_investor(): void
    {
        [$user, $investment, $contract] = $this->investedContract();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/admin/investment-contract/{$investment->id}")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id, 'action' => 'viewed',
            'model_type' => InvestmentContract::class, 'model_id' => $contract->id,
        ]);

        $this->actingAs($user)->getJson("/api/investments/{$investment->id}/contract")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id, 'action' => 'viewed',
            'model_type' => InvestmentContract::class, 'model_id' => $contract->id,
        ]);
    }

    public function test_kyc_document_view_is_audited_and_not_cacheable(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kyc-documents/front-test.jpg', 'binary');
        $investor = User::factory()->create();
        $investor->forceFill(['kyc_document_front_path' => 'kyc-documents/front-test.jpg'])->save();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/admin/kyc-document/front-test.jpg');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $row = AuditLog::where('action', 'viewed')->where('model_type', User::class)->latest('id')->firstOrFail();
        $this->assertEquals($investor->id, $row->model_id);
        $this->assertEquals($admin->id, $row->user_id);
        $this->assertSame('kyc_front', $row->new_values['document']);
        $this->assertStringNotContainsString('front-test.jpg', json_encode($row->new_values), 'the path itself is not written to the trail');
    }

    // ── A4: audit trail coverage and redaction ──

    public function test_pii_and_schedule_models_are_audited_and_identifiers_are_redacted(): void
    {
        foreach ([SavedIban::class, InvestmentSchedule::class, AmortizationSchedule::class, Originator::class, LegalEntityProfile::class, BeneficialOwner::class] as $model) {
            $this->assertContains(Auditable::class, class_uses_recursive($model), "{$model} must carry the Auditable trait");
        }

        $user = $this->createVerifiedInvestor();
        $this->actingAs($user);

        $iban = $user->savedIbans()->create(['iban' => 'BG80BNBG96611020345678', 'label' => 'main']);
        $row = AuditLog::where('model_type', SavedIban::class)->where('model_id', $iban->id)->where('action', 'created')->firstOrFail();
        $this->assertSame('[REDACTED]', $row->new_values['iban']);
        $this->assertStringNotContainsString('BG80BNBG', json_encode($row->new_values));

        $user->update(['email' => 'renamed@example.com']);
        $row = AuditLog::where('model_type', User::class)->where('model_id', $user->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('r***@example.com', $row->new_values['email']);
        $this->assertStringNotContainsString('renamed@', json_encode($row->new_values));
    }

    // ── A4: one canonical IBAN form at rest ──

    public function test_saved_and_withdrawal_ibans_are_normalised_at_the_boundary(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => '1000.00']);

        $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => 'bg80 bnbg 9661 1020 3456 78', 'label' => 'x'])
            ->assertStatus(201);
        $this->assertSame('BG80BNBG96611020345678', SavedIban::where('user_id', $user->id)->firstOrFail()->iban);

        // SEC-01: the request copies the (already normalised) saved IBAN and records which row it was.
        $saved = SavedIban::where('user_id', $user->id)->firstOrFail();
        DB::table('saved_ibans')->where('id', $saved->id)->update(['confirmed_at' => now()->subDay()]);
        $this->actingAs($user)->postJson('/api/withdrawal', ['amount' => 100, 'saved_iban_id' => $saved->id])
            ->assertStatus(201);
        $request = WithdrawalRequest::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('BG80BNBG96611020345678', $request->iban);
        $this->assertEquals($saved->id, $request->saved_iban_id);
    }

    // ── A4: brute-force ceilings on the two account-critical endpoints ──

    public function test_password_change_and_account_deletion_are_rate_limited(): void
    {
        $user = $this->createVerifiedInvestor();
        $payload = ['current_password' => 'wrong-password', 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->putJson('/api/profile/password', $payload)->assertStatus(422);
        }
        $this->actingAs($user)->putJson('/api/profile/password', $payload)->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $status = $this->actingAs($user)->postJson('/api/profile/delete', ['password' => 'wrong-password'])->status();
            $this->assertContains($status, [401, 403, 422], 'a wrong password is refused, not throttled, for the first five attempts');
        }
        $this->actingAs($user)->postJson('/api/profile/delete', ['password' => 'wrong-password'])->assertStatus(429);
    }

    // ── A4: structured «who approved» on deposits ──

    public function test_deposit_approval_records_the_approving_admin(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor();
        $admin = $this->admin();
        $deposits = app(DepositService::class);

        $deposit = $deposits->createRequest($user->id, '100.00');
        $deposits->approve($deposit->id, $admin->id);

        $this->assertEquals($admin->id, $deposit->fresh()->approved_by);
    }
}
