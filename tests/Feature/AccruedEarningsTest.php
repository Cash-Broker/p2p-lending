<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccruedEarningsService;
use App\Services\InvestmentService;
use App\Services\PayoutAccrualService;
use App\Services\ScheduledPayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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

    // ── Portfolio aggregation across many loans and plans ──

    public function test_portfolio_sums_across_loans_and_plans(): void
    {
        Notification::fake();

        // One investor spread across three loans, one per payout plan — the
        // counter is the SUM of every plan's own accrual math (the client's
        // "може да е инвестирал в 100 кредита" case in miniature).
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed deposit');

        foreach ([PayoutType::InterestOnly, PayoutType::Amortizing, PayoutType::Capitalized] as $type) {
            $loan = Loan::factory()->published()->create([
                'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
                'interest_rate' => '12.00', 'term_months' => 12,
            ]);
            $offerId = $loan->offers()->where('payout_type', $type)->value('id');
            app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', "inv-mix-{$type->value}", $offerId);
            if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
                $loan->transitionTo(Loan::STATUS_ACTIVE);
            }
        }

        // A solo investor with an IDENTICAL capitalized position isolates the
        // calendar-dependent capitalized component, so the assertion stays
        // deterministic on any run date.
        [, $solo] = $this->investorWithOfferLoan(PayoutType::Capitalized);

        $service = app(AccruedEarningsService::class);
        $asOf = now()->addDays(15);

        // interest-only 6.66 + amortizing 5.00 + (capitalized, measured solo).
        $capitalized = $service->forUser($solo->id, $asOf)['amount_daily'];
        $expected = bcadd(bcadd('6.66', '5.00', 2), $capitalized, 2);

        $this->assertSame($expected, $service->forUser($user->id, $asOf)['amount_daily']);
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

    public function test_capitalized_month_end_maturity_follows_engine_milestone_not_calendar_row(): void
    {
        // Regression (adversarial review 2026-08-13): a day-31 maturity makes
        // subMonthsNoOverflow non-invertible — the engine's maturity milestone
        // (firstDue + term−1 months) lands 2027-05-30, one day BEFORE the
        // row's due_date. The counter must show the FULL interest from the
        // milestone on (no plateau/regression across the drift window).
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::Capitalized);

        $row = $loan->investments()->first()->schedules()->first();
        DB::table('investment_schedules')->where('id', $row->id)->update(['due_date' => '2027-05-31']);
        $rowInterest = (string) $row->interest;

        $service = app(AccruedEarningsService::class);

        // On the engine's milestone date (before the calendar due date):
        $atMilestone = $service->forUser($user->id, Carbon::parse('2027-05-30 00:30'));
        $this->assertSame($rowInterest, $atMilestone['amount_daily']);

        // The day before, the last month is still interpolating below target.
        $beforeMilestone = $service->forUser($user->id, Carbon::parse('2027-05-29 12:00'));
        $this->assertLessThan((float) $rowInterest, (float) $beforeMilestone['amount_daily']);
        $this->assertGreaterThan(0, (float) $beforeMilestone['amount_daily']);

        // And the engine agrees: it releases in full on that same drift date,
        // after which the counter drops to zero and «Изплатени» takes over.
        app(PayoutAccrualService::class)->processLoan($loan->id, Carbon::parse('2027-05-30 04:00'));

        $this->assertSame('0.00', $service->forUser($user->id, Carbon::parse('2027-05-30 05:00'))['amount_daily']);
        $this->assertSame($rowInterest, $user->wallet->fresh()->earned);
    }

    public function test_capitalized_term_edit_after_invest_does_not_move_the_milestones(): void
    {
        // Regression (adversarial review 2026-08-14, HIGH): the engine used
        // the LIVE loan.term_months against invest-frozen rows — an allowed
        // term edit minted phantom accrued interest. Milestones must follow
        // the FROZEN row (term reconstructed from its own figures).
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::Capitalized);

        $rowInterest = (string) $loan->investments()->first()->schedules()->first()->interest;

        // Admin doubles the term AFTER the money is in (allowed: full-edit
        // client decision 2026-08-10 — only a warning banner).
        $loan->forceFill(['term_months' => 24])->save();

        $engine = app(PayoutAccrualService::class);

        // ~6 months in: with the live-term bug elapsed would jump past the
        // frozen schedule and accrue MORE than the row's total interest.
        $engine->processLoan($loan->id, now()->addDays(185));
        $accrued = $user->wallet->fresh()->accrued;
        $this->assertGreaterThan(0, (float) $accrued);
        $this->assertLessThan((float) $rowInterest, (float) $accrued);

        // At the frozen maturity everything reconciles to the EXACT row
        // interest — nothing stranded in `accrued`, ledger clean.
        $engine->processLoan($loan->id, now()->addDays(400));
        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->accrued);
        $this->assertSame($rowInterest, $wallet->earned);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // The display mirrors the same frozen milestones.
        $display = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(185));
        $this->assertSame('0.00', $display['amount_daily']); // all released by now
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

    // ── Interest runs from the INVEST moment (Reni 2026-08-13) ──

    public function test_partial_investment_in_funding_loan_accrues_immediately(): void
    {
        Notification::fake();

        // 1 000 investable, only 500 invested — the loan stays in funding
        // («не всички кредити се запълват на 100% … не трябва да има
        // отношение») and the investor's counter runs anyway.
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed deposit');

        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');
        $investment = app(InvestmentService::class)->invest($user, $loan->fresh(), '500.00', 'inv-partial', $offerId);

        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);

        // The schedule exists from the invest click, anchored on today.
        $this->assertSame(12, $investment->schedules()->count());
        $this->assertSame(now()->addDays(30)->toDateString(), $investment->schedules()->orderBy('due_date')->first()->due_date->toDateString());

        // 500 @ 16% → 6.66/month; 15 of 30 days → 3.33.
        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(15));
        $this->assertSame('3.33', $result['amount_daily']);
    }

    public function test_payout_engine_pays_investors_of_a_funding_loan(): void
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed deposit');

        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '500.00', 'inv-partial-pay', $offerId);

        // First installment due 30 days in — the engine pays it although the
        // loan never left funding.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(30));

        $wallet = $user->wallet->fresh();
        $this->assertSame('6.66', $wallet->earned);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // …and the counter dropped back accordingly (nothing due-unpaid left).
        $this->assertSame('0.00', app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(30))['amount_daily']);
    }

    public function test_activation_does_not_duplicate_invest_time_schedules(): void
    {
        // Full funding auto-activates; the activation generator must skip the
        // rows already created at invest time.
        [$loan, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->status);
        $this->assertSame(12, $loan->investments()->first()->schedules()->count());
    }

    public function test_backfill_command_anchors_missing_schedules_at_invested_at(): void
    {
        Notification::fake();

        // Simulate a pre-change investment: offer investment without schedules
        // in a funding loan, invested 20 days ago.
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 500,
            'interest_rate' => '12.00', 'term_months' => 12,
            'status' => Loan::STATUS_FUNDING,
        ]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $investment = Investment::factory()->create([
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'loan_offer_id' => $offerId,
            'amount' => 500,
            'interest_rate' => '16.00',
            'payout_type' => PayoutType::InterestOnly,
            'invested_at' => now()->subDays(20),
        ]);

        $this->artisan('loans:backfill-investment-schedules')->assertSuccessful();

        // Anchored at invested_at: first due = invested_at + 30 days.
        $first = $investment->schedules()->orderBy('due_date')->first();
        $this->assertNotNull($first);
        $this->assertSame(now()->subDays(20)->addDays(30)->toDateString(), $first->due_date->toDateString());

        // 20 of 30 days already elapsed → the counter shows the catch-up.
        $result = app(AccruedEarningsService::class)->forUser($user->id);
        $this->assertSame('4.44', $result['amount_daily']); // 6.66 × 20/30

        // Idempotent: a second run adds nothing.
        $this->artisan('loans:backfill-investment-schedules')->assertSuccessful();
        $this->assertSame(12, $investment->schedules()->count());
    }

    public function test_activation_generates_schedules_for_co_investors_without_them(): void
    {
        Notification::fake();

        // Regression (adversarial review 2026-08-14): the funded→active
        // catch-up used a LOAN-level exists() guard — the closing investor's
        // invest-time rows made it true and a pre-change co-investor without
        // schedules was stranded. generate() must run unconditionally.
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 400,
            'interest_rate' => '12.00', 'term_months' => 12,
            'status' => Loan::STATUS_FUNDING,
        ]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');

        // Pre-change investor A: investment WITHOUT schedules.
        $userA = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $userA->wallet()->create();
        $investmentA = Investment::factory()->create([
            'user_id' => $userA->id, 'loan_id' => $loan->id, 'loan_offer_id' => $offerId,
            'amount' => 400, 'interest_rate' => '16.00', 'payout_type' => PayoutType::InterestOnly,
            'invested_at' => now()->subDays(10),
        ]);

        // Post-change investor B closes the loan through the real flow.
        $userB = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $userB->wallet()->create();
        app(WalletService::class)->credit($userB->id, '1000.00', Transaction::TYPE_DEPOSIT, 'seed');
        app(InvestmentService::class)->invest($userB, $loan->fresh(), '600.00', 'inv-closing', $offerId);

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);

        // BOTH investors have full schedules — B's from invest time, A's
        // generated at activation by the unconditional catch-up.
        $this->assertSame(12, $investmentA->schedules()->count());
        $this->assertSame(12, $loan->investments()->where('user_id', $userB->id)->first()->schedules()->count());
    }

    public function test_legacy_funding_loan_neither_pays_nor_accrues(): void
    {
        // Legacy world keeps the old scope (active/late only): the dispatcher
        // must no-op — not throw — and the counter must not show what the
        // legacy engine won't pay.
        $loan = Loan::factory()->create(['status' => Loan::STATUS_FUNDING, 'funded_amount' => 500]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $loan->id, 'amount' => 500, 'loan_offer_id' => null]);

        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => now()->subDay()->toDateString(),
            'principal' => '80.00',
            'interest' => '40.00',
            'total' => '120.00',
            'status' => 'pending',
        ]);

        $result = app(ScheduledPayoutService::class)->runForLoan($loan->fresh());
        $this->assertSame('legacy', $result['type']);
        $this->assertSame(0, $result['posted_count']);

        $this->assertSame('0.00', app(AccruedEarningsService::class)->forUser($user->id)['amount_daily']);
    }

    public function test_display_falls_back_to_projection_when_schedules_missing(): void
    {
        Notification::fake();

        // Pre-backfill state: no schedule rows at all — the counter still runs
        // from invested_at via the pure projection fallback.
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 500,
            'interest_rate' => '12.00', 'term_months' => 12,
            'status' => Loan::STATUS_FUNDING,
        ]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        Investment::factory()->create([
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'loan_offer_id' => $offerId,
            'amount' => 500,
            'interest_rate' => '16.00',
            'payout_type' => PayoutType::InterestOnly,
            'invested_at' => now()->subDays(15),
        ]);

        $result = app(AccruedEarningsService::class)->forUser($user->id);

        $this->assertSame('3.33', $result['amount_daily']); // 6.66 × 15/30
    }

    // ── Dashboard endpoint payload ──

    public function test_dashboard_exposes_earned_accrual_and_lifetime_totals(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        $wallet->forceFill(['earned' => 120.50])->save();

        Transaction::factory()->create(['user_id' => $user->id, 'type' => Transaction::TYPE_WITHDRAWAL, 'amount' => 200]);
        Transaction::factory()->create(['user_id' => $user->id, 'type' => Transaction::TYPE_WITHDRAWAL, 'amount' => 50.25]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'earned_accrual' => ['amount_daily', 'amount_live', 'daily_rate', 'hourly_rate', 'per_second_rate', 'as_of'],
                'lifetime_totals' => ['earned_paid', 'withdrawn_total'],
            ])
            ->assertJsonPath('lifetime_totals.earned_paid', '120.50')
            ->assertJsonPath('lifetime_totals.withdrawn_total', '250.25');
    }

    public function test_hourly_rate_is_daily_rate_over_24(): void
    {
        [, $user] = $this->investorWithOfferLoan(PayoutType::InterestOnly);

        $result = app(AccruedEarningsService::class)->forUser($user->id, now()->addDays(15));

        // 13.33/30 = 0.4443/day → 0.0185/hour (bc trunc at 4).
        $this->assertSame('0.0185', $result['hourly_rate']);
    }
}
