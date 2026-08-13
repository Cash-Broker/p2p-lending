<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccruedEarningsService;
use App\Services\InvestmentService;
use App\Services\PayoutAccrualService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The dashboard «Спечелени» reference counter (2026-08-13): schedule-accrued
 * interest not yet paid out. Display-only — these tests pin that the numbers
 * mirror the payout engine (what it will pay next is what we show accruing,
 * and what it HAS paid leaves the counter for wallet `earned` / «Изплатени»).
 */
class AccruedEarningsTest extends TestCase
{
    use RefreshDatabase;

    private function investorWithOfferLoan(PayoutType $type, string $amount = '1000.00'): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => (float) $amount, 'investable_amount' => (float) $amount, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed deposit');

        $offerId = $loan->offers()->where('payout_type', $type)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), $amount, "inv-{$type->value}", $offerId);

        // Full funding auto-activates (2026-08-13); keep the manual fallback.
        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->refresh(), $user];
    }

    // ── Offer-based: amortizing / interest-only ──

    public function test_interest_only_accrues_pro_rata_by_day(): void
    {
        [, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);

        // 1000 @ 16% → 13.33/month. 15 of 30 days elapsed → 6.66 (bc trunc).
        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(15));

        $this->assertSame('6.66', $result['amount_daily']);
        // Second-granular figure sits within the same day of accrual.
        $this->assertGreaterThanOrEqual(6.66, (float) $result['amount_live']);
        $this->assertLessThan(6.67 + 13.33 / 30, (float) $result['amount_live']);
        // Pace: 13.33 € / 30 days.
        $this->assertSame('0.4443', $result['daily_rate']);
        $this->assertSame(bcdiv('0.4443333333', '86400', 10), $result['per_second_rate']);
    }

    public function test_amortizing_accrues_first_period_interest_pro_rata(): void
    {
        [, $user] = $this->investorWithOfferLoan(PayoutType::Amortizing);

        // 1000 @ 12% → first-row interest 10.00; half the period elapsed.
        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(15));

        $this->assertSame('5.00', $result['amount_daily']);
    }

    public function test_day_zero_shows_nothing_yet(): void
    {
        [, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);

        $result = app(AccruedEarningsService::class)->forUser($user->id);

        $this->assertSame('0.00', $result['amount_daily']);
        // …but the pace is already visible (motivation, per the client).
        $this->assertSame('0.4443', $result['daily_rate']);
    }

    public function test_overdue_unpaid_row_counts_in_full_until_paid_then_moves_to_earned(): void
    {
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);
        $service = app(AccruedEarningsService::class);
        $asOf = now()->addDays(45);

        // Row 1 (13.33) due-but-unpaid + 15/30 of row 2 (6.66) = 19.99.
        $this->assertSame('19.99', $service->forUser($user->id, $asOf)['amount_daily']);

        // The payout engine pays row 1 → it leaves the counter and lands in
        // wallet `earned` («Изплатени»). Conservation, no double counting.
        app(PayoutAccrualService::class)->processLoan($loan->id, $asOf);

        $this->assertSame('6.66', $service->forUser($user->id, $asOf)['amount_daily']);
        $this->assertSame('13.33', $user->wallet->fresh()->earned);
    }

    // ── Offer-based: capitalized ──

    public function test_capitalized_matured_unpaid_equals_exact_schedule_interest(): void
    {
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::Capitalized);

        $rowInterest = (string) $loan->investments()->first()->schedules()->first()->interest;

        // At maturity (row still unpaid) the counter is the EXACT schedule
        // interest — the same figure the engine releases.
        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(360));

        $this->assertSame($rowInterest, $result['amount_daily']);
        $this->assertSame(bcadd($rowInterest, '0', 6), $result['amount_live']);
    }

    public function test_capitalized_display_never_lags_behind_engine_accrual(): void
    {
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::Capitalized);
        $asOf = now()->addDays(185);

        // Engine recognises milestone targets; the display adds the current
        // month's linear progress on top — so display ≥ engine, both < total.
        app(PayoutAccrualService::class)->processLoan($loan->id, $asOf);
        $engineAccrued = $user->wallet->fresh()->accrued;

        $result = app(AccruedEarningsService::class)->forUser($user->id, $asOf);
        $rowInterest = (string) $loan->investments()->first()->schedules()->first()->interest;

        $this->assertGreaterThan(0, (float) $engineAccrued);
        $this->assertGreaterThanOrEqual((float) $engineAccrued, (float) $result['amount_daily']);
        $this->assertLessThan((float) $rowInterest, (float) $result['amount_daily']);
    }

    public function test_capitalized_fully_released_contributes_nothing(): void
    {
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::Capitalized);

        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(400));

        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(400));

        $this->assertSame('0.00', $result['amount_daily']);
    }

    // ── Legacy (pre-offer) loans ──

    public function test_legacy_investors_split_row_interest_by_invested_weight(): void
    {
        $loan = Loan::factory()->active()->create(['funded_amount' => 1000]);

        $userA = User::factory()->create(['email_verified_at' => now()]);
        $userB = User::factory()->create(['email_verified_at' => now()]);
        Investment::factory()->create(['user_id' => $userA->id, 'loan_id' => $loan->id, 'amount' => 750, 'loan_offer_id' => null]);
        Investment::factory()->create(['user_id' => $userB->id, 'loan_id' => $loan->id, 'amount' => 250, 'loan_offer_id' => null]);

        // One overdue unpaid installment: 40.00 interest → 30/10 by weight.
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => now()->subDays(5)->toDateString(),
            'principal' => '80.00',
            'interest' => '40.00',
            'total' => '120.00',
            'status' => 'pending',
        ]);

        $service = app(AccruedEarningsService::class);

        $this->assertSame('30.00', $service->forUser($userA->id)['amount_daily']);
        $this->assertSame('10.00', $service->forUser($userB->id)['amount_daily']);
    }

    // ── Scope: only loans the payout engine pays ──

    public function test_defaulted_loan_is_excluded(): void
    {
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);

        $loan->transitionTo(Loan::STATUS_LATE);
        $loan->transitionTo(Loan::STATUS_DEFAULT);

        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(45));

        $this->assertSame('0.00', $result['amount_daily']);
        $this->assertSame('0.0000', $result['daily_rate']);
    }

    public function test_late_loan_keeps_accruing(): void
    {
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);

        $loan->transitionTo(Loan::STATUS_LATE);

        // «По график» model: the platform keeps paying late loans on schedule,
        // so the counter keeps accruing too.
        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(15));

        $this->assertSame('6.66', $result['amount_daily']);
    }

    public function test_user_without_investments_gets_zeroes(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $result = app(AccruedEarningsService::class)->forUser($user->id);

        $this->assertSame('0.00', $result['amount_daily']);
        $this->assertSame('0.000000', $result['amount_live']);
        $this->assertSame('0.0000', $result['daily_rate']);
        $this->assertSame('0.0000000000', $result['per_second_rate']);
    }

    // ── Dashboard endpoint + the display-mode setting ──

    public function test_dashboard_exposes_earned_accrual_with_default_daily_mode(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('earned_accrual.mode', 'daily')
            ->assertJsonStructure([
                'earned_accrual' => ['mode', 'amount_daily', 'amount_live', 'daily_rate', 'per_second_rate', 'as_of'],
            ]);
    }

    public function test_dashboard_mode_follows_the_platform_setting(): void
    {
        PlatformSetting::set('dashboard_earned_mode', 'live');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('earned_accrual.mode', 'live');
    }

    public function test_missing_setting_falls_back_to_daily(): void
    {
        DB::table('platform_settings')->where('key', 'dashboard_earned_mode')->delete();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('earned_accrual.mode', 'daily');
    }

    public function test_db_check_rejects_invalid_mode(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('CHECK constraint is MySQL-only.');
        }

        $this->expectException(QueryException::class);

        DB::table('platform_settings')
            ->where('key', 'dashboard_earned_mode')
            ->update(['value' => 'hourly']);
    }
}
