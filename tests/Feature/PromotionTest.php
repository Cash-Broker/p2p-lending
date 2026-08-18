<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\PromotionResource\Pages\CreatePromotion;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanPromotion;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BonusCreditedNotification;
use App\Notifications\PromoStartedNotification;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flash promos (Reni 2026-08-14): invest during the window → upfront
 * TYPE_BONUS_LOCKED lands in the `bonus_locked` bucket atomically with the
 * investment (Reni 2026-08-18: promo bonuses are conditional too — released
 * once the investment has served its installments, see BonusLockTest). These
 * tests pin the money math, the window/budget guards, idempotency, the
 * ledger, the API feed, and the admin flow with its bell fan-out.
 */
class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private function fundableLoan(float $investable = 5000): Loan
    {
        return Loan::factory()->published()->create([
            'amount' => $investable, 'investable_amount' => $investable, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
    }

    private function investor(string $deposit = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, $deposit, Transaction::TYPE_DEPOSIT, 'seed deposit');

        return $user;
    }

    private function runningPromo(Loan $loan, string $percent = '2.00', ?string $cap = null): LoanPromotion
    {
        return LoanPromotion::create([
            'loan_id' => $loan->id,
            'bonus_percent' => $percent,
            'budget_cap' => $cap,
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addMinutes(55),
        ]);
    }

    private function invest(User $user, Loan $loan, string $amount, string $key): Investment
    {
        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');

        return app(InvestmentService::class)->invest($user, $loan->fresh(), $amount, $key, $offerId);
    }

    // ── Money math ──

    public function test_invest_during_window_pays_the_exact_upfront_bonus(): void
    {
        Notification::fake();

        $loan = $this->fundableLoan();
        $promo = $this->runningPromo($loan, '2.00');
        $user = $this->investor();

        $investment = $this->invest($user, $loan, '1000.00', 'promo-inv-1');

        // The ACTUAL granted amount is surfaced to the API response layer.
        $this->assertSame('20.00', $investment->promoBonusGranted);

        $investmentId = $loan->investments()->first()->id;

        // 1000 × 2% = 20.00 — exact, bcmath, as a TYPE_BONUS_LOCKED row.
        $bonusTx = Transaction::where('type', Transaction::TYPE_BONUS_LOCKED)
            ->where('reference', "promo:{$promo->id}:investment:{$investmentId}")
            ->first();
        $this->assertNotNull($bonusTx);
        $this->assertSame('20.00', (string) $bonusTx->amount);

        // Wallet: −1000 invested; the 20 € bonus is NOT spendable yet — it
        // waits in its own bucket until the position serves its installments.
        $wallet = $user->wallet->fresh();
        $this->assertSame('4000.00', $wallet->available);
        $this->assertSame('20.00', $wallet->bonus_locked);
        $this->assertSame('1000.00', $wallet->invested);
        $this->assertSame('20.00', (string) $promo->fresh()->bonus_paid_total);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // The investor hears about it after commit.
        Notification::assertSentTo($user, BonusCreditedNotification::class);
    }

    public function test_no_bonus_outside_the_window_or_when_cancelled(): void
    {
        Notification::fake();

        // Expired.
        $loanA = $this->fundableLoan();
        LoanPromotion::create([
            'loan_id' => $loanA->id, 'bonus_percent' => '2.00',
            'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
        ]);
        $userA = $this->investor();
        $this->invest($userA, $loanA, '1000.00', 'promo-expired');

        // Cancelled mid-window.
        $loanB = $this->fundableLoan();
        $promoB = $this->runningPromo($loanB);
        $promoB->forceFill(['cancelled_at' => now()])->save();
        $userB = $this->investor();
        $this->invest($userB, $loanB, '1000.00', 'promo-cancelled');

        $this->assertSame(0, Transaction::where('type', Transaction::TYPE_BONUS_LOCKED)->count());
    }

    public function test_budget_cap_trims_and_then_stops_bonuses(): void
    {
        Notification::fake();

        $loan = $this->fundableLoan();
        $promo = $this->runningPromo($loan, '2.00', '30.00');

        // 1st: full 20. 2nd: trimmed to the remaining 10. 3rd: nothing.
        $users = [$this->investor(), $this->investor(), $this->investor()];
        foreach ($users as $i => $user) {
            $this->invest($user, $loan->fresh(), '1000.00', "promo-cap-{$i}");
        }

        $bonuses = Transaction::where('type', Transaction::TYPE_BONUS_LOCKED)
            ->orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a);

        $this->assertSame(['20.00', '10.00'], $bonuses->all());
        $this->assertSame('30.00', (string) $promo->fresh()->bonus_paid_total);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_idempotent_invest_replay_grants_a_single_bonus(): void
    {
        Notification::fake();

        $loan = $this->fundableLoan();
        $this->runningPromo($loan);
        $user = $this->investor();

        $this->invest($user, $loan, '1000.00', 'promo-replay');
        $this->invest($user, $loan, '1000.00', 'promo-replay'); // same key → same investment

        $this->assertSame(1, $loan->investments()->count());
        $this->assertSame(1, Transaction::where('type', Transaction::TYPE_BONUS_LOCKED)->count());
    }

    // ── API feed ──

    public function test_active_endpoint_returns_running_promos_only(): void
    {
        $loan = $this->fundableLoan();
        $running = $this->runningPromo($loan, '3.50');

        // Noise that must NOT appear: expired + cancelled + non-fundable loan.
        $expiredLoan = $this->fundableLoan();
        LoanPromotion::create([
            'loan_id' => $expiredLoan->id, 'bonus_percent' => '2.00',
            'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
        ]);
        $activeLoan = Loan::factory()->active()->create();
        LoanPromotion::create([
            'loan_id' => $activeLoan->id, 'bonus_percent' => '2.00',
            'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addHour(),
        ]);
        // Private (link-only) loan — a running promo on it must NOT be
        // broadcast to the public feed.
        $privateLoan = $this->fundableLoan();
        $privateLoan->forceFill(['visibility' => Loan::VISIBILITY_PRIVATE, 'share_token' => Loan::generateShareToken()])->save();
        LoanPromotion::create([
            'loan_id' => $privateLoan->id, 'bonus_percent' => '2.00',
            'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addHour(),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $response = $this->actingAs($user)->getJson('/api/promotions/active')->assertOk();

        $list = $response->json('promotions');
        $this->assertCount(1, $list);
        $this->assertSame($running->id, $list[0]['id']);
        $this->assertSame('3.50', $list[0]['bonus_percent']);
        $this->assertSame($loan->id, $list[0]['loan']['id']);
        $this->assertArrayHasKey('ends_at', $list[0]);
        $this->assertArrayHasKey('offer_rate_range', $list[0]['loan']);
    }

    public function test_active_endpoint_requires_auth(): void
    {
        $this->getJson('/api/promotions/active')->assertStatus(401);
    }

    public function test_nearly_exhausted_budget_drops_the_promo_from_the_feed(): void
    {
        // Remaining budget below the bonus on a MINIMUM (50 €) investment —
        // the card must vanish rather than advertise a bonus it can't pay.
        $loan = $this->fundableLoan();
        $promo = $this->runningPromo($loan, '2.00', '30.00');
        $promo->forceFill(['bonus_paid_total' => '29.50'])->save(); // remaining 0.50 < 1.00 (2% от 50 €)

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/promotions/active')
            ->assertOk()
            ->assertJsonCount(0, 'promotions');
    }

    // ── Admin flow ──

    public function test_admin_creates_promo_and_investors_get_the_bell(): void
    {
        Notification::fake();

        $loan = $this->fundableLoan();
        $investor = User::factory()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();
        $unverified = User::factory()->unverified()->create();
        $unverified->wallet()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreatePromotion::class)
            ->fillForm([
                'loan_id' => $loan->id,
                'bonus_percent' => '2.5',
                'duration_minutes' => 60,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = LoanPromotion::firstOrFail();
        $this->assertSame($loan->id, $promo->loan_id);
        $this->assertTrue($promo->isRunning());
        $this->assertTrue($promo->ends_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame($admin->id, $promo->created_by);

        // The bell deadline is Bulgarian wall-clock (Europe/Sofia), never the
        // app timezone (UTC on prod) — review regression 2026-08-14.
        $expectedHour = $promo->ends_at->copy()->timezone('Europe/Sofia')->format('H:i');
        Notification::assertSentTo($investor, PromoStartedNotification::class,
            fn (PromoStartedNotification $n) => str_contains($n->toArray($investor)['message'], "до {$expectedHour} ч."));
        Notification::assertNotSentTo($unverified, PromoStartedNotification::class);
        Notification::assertNotSentTo($admin, PromoStartedNotification::class);
    }

    public function test_second_running_promo_on_the_same_loan_is_rejected(): void
    {
        Notification::fake();

        $loan = $this->fundableLoan();
        $this->runningPromo($loan);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreatePromotion::class)
            ->fillForm([
                'loan_id' => $loan->id,
                'bonus_percent' => '2.0',
                'duration_minutes' => 60,
            ])
            ->call('create')
            ->assertHasFormErrors(['loan_id']);

        $this->assertSame(1, LoanPromotion::count());
    }

    public function test_db_check_rejects_out_of_range_bonus_percent(): void
    {
        $loan = $this->fundableLoan();

        $this->expectException(QueryException::class);

        LoanPromotion::create([
            'loan_id' => $loan->id,
            'bonus_percent' => '25.00', // CHECK каps at 10
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
        ]);
    }
}
