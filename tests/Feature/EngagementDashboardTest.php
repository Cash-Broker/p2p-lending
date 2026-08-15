<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanPromotion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Engagement pack (2026-08-14): «Докато те нямаше», следващо плащане,
 * работещи дни, what-if диапазон, социално доказателство. Display-only
 * blocks — these tests pin that every figure is exact ledger/schedule truth.
 */
class EngagementDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedInvestor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    private function investedUser(string $amount = '1000.00', PayoutType $type = PayoutType::InterestOnly): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 5000, 'investable_amount' => 5000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed');

        $offerId = $loan->offers()->where('payout_type', $type)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), $amount, 'eng-'.uniqid(), $offerId);

        return [$loan, $user];
    }

    // ── «Докато те нямаше…» ──

    public function test_first_visit_gets_no_welcome_back_block_and_stamps_seen_at(): void
    {
        $user = $this->verifiedInvestor();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('since_last_visit', null);

        $this->assertNotNull($user->fresh()->dashboard_seen_at);
    }

    public function test_seen_at_stamp_writes_no_audit_row_and_keeps_updated_at(): void
    {
        // Regression (review 2026-08-14): a model save() fired the Auditable
        // trait — one immutable audit_logs row per PAGE VIEW, forever, plus a
        // meaningless users.updated_at rewrite. The stamp must be silent.
        $user = $this->verifiedInvestor();
        $updatedAt = $user->fresh()->updated_at;

        $auditBefore = AuditLog::count();

        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertSame($auditBefore, AuditLog::count(), 'a dashboard view must not pollute the audit trail');
        $this->assertEquals($updatedAt, $user->fresh()->updated_at, 'a dashboard view must not rewrite updated_at');
        $this->assertNotNull($user->fresh()->dashboard_seen_at);
    }

    public function test_short_gap_shows_no_banner(): void
    {
        // Under the 1-hour gap (lowered from 6 h, Yordan 2026-08-14) the
        // banner stays quiet — ordinary same-session browsing must not fire it.
        $user = $this->verifiedInvestor();
        $user->forceFill(['dashboard_seen_at' => now()->subMinutes(20)])->save();

        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 10, 'created_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('since_last_visit', null);
    }

    public function test_ninety_minute_gap_with_income_shows_the_banner(): void
    {
        $user = $this->verifiedInvestor();
        $user->forceFill(['dashboard_seen_at' => now()->subMinutes(90)])->save();

        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 10, 'created_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('since_last_visit.received', '10.00');
    }

    public function test_long_gap_with_income_returns_the_exact_received_sum(): void
    {
        $user = $this->verifiedInvestor();
        $user->forceFill(['dashboard_seen_at' => now()->subDays(2)])->save();

        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 12.50, 'created_at' => now()->subDay(),
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_BONUS,
            'amount' => 5.00, 'created_at' => now()->subHours(10),
        ]);
        // Before the window — must NOT count.
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 99, 'created_at' => now()->subDays(3),
        ]);
        // Principal is not «постъпления от печалба».
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL,
            'amount' => 50, 'created_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('since_last_visit.received', '17.50');
    }

    public function test_banner_always_returns_after_the_gap_even_without_news(): void
    {
        // Reni: «винаги да има новини» — the block always comes back after the
        // gap; the frontend picks a truthful fallback headline.
        $user = $this->verifiedInvestor();
        $user->forceFill(['dashboard_seen_at' => now()->subHours(3)])->save();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertNotNull($response->json('since_last_visit'));
        $this->assertSame('0.00', $response->json('since_last_visit.received'));
        $this->assertSame('0.00', $response->json('since_last_visit.accrued_now'));
        $this->assertSame('0.00', $response->json('since_last_visit.available'));
    }

    public function test_accrual_growth_carries_the_banner_when_no_payout_landed(): void
    {
        // Reni 2026-08-14: monthly payouts are rare — the ticking profit
        // itself is the news. Schedule backdated 10 days so the 2-day window
        // sits mid-period: growth = 2 days × 13.33/30 ≈ 0.88 €.
        [$loan, $user] = $this->investedUser();
        DB::table('investment_schedules')
            ->where('loan_id', $loan->id)
            ->update(['due_date' => DB::raw('DATE_SUB(due_date, INTERVAL 10 DAY)')]);
        $user->forceFill(['dashboard_seen_at' => now()->subDays(2)])->save();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $growth = (float) $response->json('since_last_visit.accrual_growth');
        $this->assertGreaterThan(0.80, $growth);
        $this->assertLessThan(1.00, $growth);
        $this->assertSame('0.00', $response->json('since_last_visit.received'));
    }

    public function test_new_running_promo_counts_in_the_banner(): void
    {
        $user = $this->verifiedInvestor();
        $user->forceFill(['dashboard_seen_at' => now()->subDay()])->save();

        $loan = Loan::factory()->published()->create([
            'amount' => 5000, 'investable_amount' => 5000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        LoanPromotion::create([
            'loan_id' => $loan->id, 'bonus_percent' => '2.00',
            'starts_at' => now()->subHours(2), 'ends_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('since_last_visit.new_promos', 1);
    }

    // ── Следващо плащане ──

    public function test_next_payout_returns_nearest_due_row_sum_and_days(): void
    {
        [, $user] = $this->investedUser();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        // Invest-time schedule: first due in 30 days, 1000 @ 16% → 13.33.
        $response->assertJsonPath('next_payout.amount', '13.33');
        $this->assertEqualsWithDelta(30, $response->json('next_payout.days_left'), 1);
    }

    public function test_next_payout_shows_overdue_unpaid_installment_as_today(): void
    {
        // Manual-mode loan whose installment passed unpaid — the promise must
        // read «днес», not silently vanish (review 2026-08-14).
        [$loan, $user] = $this->investedUser();

        $loan->investments()->first()->schedules()
            ->orderBy('due_date')->first()
            ->forceFill(['due_date' => now()->subDays(3)->toDateString()])->save();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0, $response->json('next_payout.days_left'));
        $this->assertSame('13.33', $response->json('next_payout.amount'));
    }

    public function test_next_payout_is_null_without_investments(): void
    {
        $user = $this->verifiedInvestor();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('next_payout', null);
    }

    // ── Работещи дни (стрийк) ──

    public function test_working_days_counts_from_the_oldest_live_investment(): void
    {
        [, $user] = $this->investedUser();

        $user->investments()->first()->forceFill(['invested_at' => now()->subDays(47)])->save();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('working_days', 47);
    }

    public function test_working_days_flip_at_bulgarian_midnight_not_at_the_invest_hour(): void
    {
        // Reni 2026-08-15: «след 12 през нощта вече трябва да пише 5 дена» —
        // calendar days in Europe/Sofia, not a rolling 24h count. An
        // investment made 25 hours ago late in the evening spans TWO Sofia
        // midnights only if the calendar says so.
        [, $user] = $this->investedUser();

        // Invested «вчера» just after Sofia midnight → exactly 1 calendar day
        // regardless of the current hour (a rolling count would show 0 until
        // the invest hour comes around again).
        $yesterdaySofia = now()->timezone('Europe/Sofia')->subDay()->setTime(0, 30);
        $user->investments()->first()
            ->forceFill(['invested_at' => $yesterdaySofia->clone()->timezone('UTC')])
            ->save();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('working_days', 1);
    }

    public function test_working_days_null_without_live_investments(): void
    {
        $user = $this->verifiedInvestor();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('working_days', null);
    }

    // ── What-if диапазон ──

    public function test_market_rate_range_comes_from_public_fundable_interest_only_offers(): void
    {
        Loan::factory()->published()->create([
            'amount' => 5000, 'investable_amount' => 5000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = $this->verifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        // Default seeded interest-only offer is 16%.
        $this->assertSame('16.00', $response->json('market_rate_range.min'));
        $this->assertSame('16.00', $response->json('market_rate_range.max'));
    }

    public function test_market_rate_range_includes_private_loans(): void
    {
        // The platform currently sells by private link only (Yordan
        // 2026-08-14) — the bare percentage feeds the slider without leaking
        // any loan identity.
        $loan = Loan::factory()->published()->create([
            'amount' => 5000, 'investable_amount' => 5000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $loan->forceFill(['visibility' => Loan::VISIBILITY_PRIVATE, 'share_token' => Loan::generateShareToken()])->save();
        $loan->offers()->where('payout_type', PayoutType::InterestOnly)->update(['interest_rate' => '18.00']);

        $user = $this->verifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertSame('18.00', $response->json('market_rate_range.max'));
    }

    public function test_market_rate_range_falls_back_to_the_default_rate_without_open_loans(): void
    {
        // The dream never goes dark: with zero open loans the slider projects
        // at the standard seeded «само лихва» rate.
        $user = $this->verifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertSame('16.00', $response->json('market_rate_range.min'));
        $this->assertSame('16.00', $response->json('market_rate_range.max'));
    }

    // ── Социално доказателство ──

    public function test_loan_show_exposes_anonymous_last_invested_at(): void
    {
        [$loan, $user] = $this->investedUser();

        $response = $this->actingAs($user)->getJson("/api/loans/{$loan->id}")->assertOk();

        $this->assertNotNull($response->json('last_invested_at'));
        // ISO-8601 with offset — a raw «Y-m-d H:i:s» string would parse as
        // viewer-local time in browsers (hours of skew, review 2026-08-14).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:?\d{2}$/',
            $response->json('last_invested_at'),
        );
        // Anonymity: no investor identity anywhere near it.
        $this->assertArrayNotHasKey('investments', $response->json());
    }
}
