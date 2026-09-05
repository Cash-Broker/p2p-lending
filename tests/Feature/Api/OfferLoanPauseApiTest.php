<?php

namespace Tests\Feature\Api;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AdminActionItemsNotification;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** PAY-13: what the investor API, the health endpoint and the digest expose. */
class OfferLoanPauseApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Loan, 1: User} */
    private function activeOfferLoan(): array
    {
        Notification::fake();
        PlatformSetting::set('borrower_tracker_auto_generate', true); // the tests exercise the tracker; prod ships OFF
        $loan = Loan::factory()->published()->create(['amount' => '1000.00', 'investable_amount' => '1000.00', 'funded_amount' => 0, 'interest_rate' => '12.00', 'term_months' => 12]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '1100.00', Transaction::TYPE_DEPOSIT, 'seed');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        return [$loan->fresh(), $user];
    }

    public function test_loan_show_hides_the_tracker_and_exposes_the_pause_flags(): void
    {
        [$loan, $user] = $this->activeOfferLoan();
        $this->assertSame(12, $loan->amortizationSchedules()->borrowerTracker()->count());

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonCount(0, 'amortization_schedule')
            ->assertJsonPath('payouts_paused', false)
            ->assertJsonMissingPath('payouts_paused_at');

        // Stamp without the setting: still not paused for the investor.
        $loan->forceFill(['payouts_paused_at' => now()])->save();
        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")->assertJsonPath('payouts_paused', false);

        PlatformSetting::set('payout_pause_enabled', true);
        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")
            ->assertJsonPath('payouts_paused', true)
            ->assertJsonStructure(['payouts_paused_at']);

        // A legacy loan still returns its per-loan schedule.
        Notification::fake();
        $legacy = Loan::factory()->published()->create(['amount' => '1000.00', 'investable_amount' => '1000.00', 'funded_amount' => 0, 'interest_rate' => '10.00', 'term_months' => 12]);
        $other = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $other->wallet()->create();
        app(WalletService::class)->credit($other->id, '1100.00', Transaction::TYPE_DEPOSIT, 'seed');
        app(InvestmentService::class)->invest($other, $legacy->fresh(), '1000.00', (string) Str::uuid());
        $this->actingAs($other)->getJson("/api/loans/{$legacy->id}")->assertOk()->assertJsonCount(12, 'amortization_schedule');
    }

    public function test_portfolio_days_overdue_reflects_the_borrower_tracker(): void
    {
        [$loan, $user] = $this->activeOfferLoan();
        $row = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail();
        $row->forceFill(['status' => 'late', 'became_late_at' => now(), 'days_late' => 17])->save();
        $loan->fresh()->transitionTo(Loan::STATUS_LATE);

        $response = $this->actingAs($user)->getJson('/api/portfolio')->assertOk()->json();
        $inv = collect($response['data'] ?? $response)->first(fn ($i) => ($i['loan']['id'] ?? null) === $loan->id);

        $this->assertSame(17, (int) $inv['loan']['days_overdue_max']);
        $this->assertFalse($inv['loan']['payouts_paused']);
        $this->assertTrue(collect($inv['schedule'])->every(fn ($r) => $r['withheld'] === false));
    }

    public function test_health_exposes_the_pause_counters_and_the_setting_without_degrading(): void
    {
        $response = $this->getJson('/api/health/scheduler');

        $response->assertJsonPath('payouts.payout_pause_enabled', false)
            ->assertJsonStructure(['payouts' => ['last_run_stats' => ['loans_paused', 'pause_newly_paused', 'pause_resumed']]]);
        $this->assertArrayNotHasKey('enabled', $response->json('payouts'));

        PlatformSetting::set('payout_pause_enabled', true);
        $this->getJson('/api/health/scheduler')->assertJsonPath('payouts.payout_pause_enabled', true);
    }

    public function test_an_unrecorded_overdue_borrower_installment_is_an_action_item_for_the_digest(): void
    {
        [$loan] = $this->activeOfferLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        Notification::fake();

        // Nothing else pending → no e-mail.
        $this->artisan('telegram:digest')->assertSuccessful();
        Notification::assertNotSentTo($admin, AdminActionItemsNotification::class);

        // One tracker row past due and never recorded → the daily reminder fires.
        $row = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail();
        $row->forceFill(['due_date' => now()->subDays(3)->toDateString()])->save();
        $this->artisan('telegram:digest')->assertSuccessful();
        Notification::assertSentToTimes($admin, AdminActionItemsNotification::class, 1);

        // Once 03:30 flips the row to `late` it is STILL past due and unrecorded — the reminder keeps coming.
        $row->forceFill(['status' => 'late', 'became_late_at' => now(), 'days_late' => 13])->save();
        $this->artisan('telegram:digest')->assertSuccessful();
        Notification::assertSentToTimes($admin, AdminActionItemsNotification::class, 2);
    }
}
