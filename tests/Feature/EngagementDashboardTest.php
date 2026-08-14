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
        $user = $this->verifiedInvestor();
        $user->forceFill(['dashboard_seen_at' => now()->subHour()])->save();

        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 10, 'created_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('since_last_visit', null);
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

    public function test_market_rate_range_null_without_fundable_loans(): void
    {
        $user = $this->verifiedInvestor();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('market_rate_range', null);
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
