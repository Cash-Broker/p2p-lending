<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reni 2026-08-17: the portfolio must make clear WHICH offer each position
 * uses («колко кредита са ми в еди коя си оферта») and the loan page must show
 * the investor's OWN terms (chosen plan, snapshotted rate, personal schedule)
 * — not only what the loan offers.
 */
class PortfolioPlanClarityTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed:'.Str::uuid());

        return $user;
    }

    private function fundableLoan(): Loan
    {
        return Loan::factory()->published()->create([
            'amount' => 5000, 'investable_amount' => 5000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
    }

    private function investVia(User $user, Loan $loan, string $amount, PayoutType $type): Investment
    {
        $offerId = $loan->offers()->where('payout_type', $type)->value('id');

        return app(InvestmentService::class)
            ->invest($user, $loan->fresh(), $amount, (string) Str::uuid(), $offerId);
    }

    // ── «По план» разбивка в summary ──

    public function test_summary_breaks_positions_down_by_plan(): void
    {
        Notification::fake();

        $user = $this->investor();
        $loanA = $this->fundableLoan();
        $loanB = $this->fundableLoan();

        $this->investVia($user, $loanA, '300.00', PayoutType::Amortizing);
        // Second position in the SAME loan and plan — «колко кредита» counts
        // DISTINCT loans (Reni's wording), so this must NOT double-count.
        $this->investVia($user, $loanA, '100.00', PayoutType::Amortizing);
        $this->investVia($user, $loanA, '200.00', PayoutType::Capitalized);
        $this->investVia($user, $loanB, '100.00', PayoutType::Capitalized);
        // Legacy pre-offer position — no payout snapshot.
        Investment::factory()->create([
            'user_id' => $user->id,
            'loan_id' => Loan::factory()->create(['status' => Loan::STATUS_ACTIVE])->id,
            'amount' => '50.00',
        ]);

        $plans = collect($this->actingAs($user)->getJson('/api/portfolio/summary')
            ->assertOk()
            ->json('breakdown_by_plan'));

        $this->assertSame(
            ['amortizing', 'capitalized', null],
            $plans->pluck('payout_type')->all(),
            'sorted by plan position, legacy bucket last',
        );

        $amortizing = $plans->firstWhere('payout_type', 'amortizing');
        $this->assertSame('Анюитет', $amortizing['label']);
        $this->assertSame(1, $amortizing['count'], 'two positions in ONE loan = 1 кредит');
        $this->assertSame('400.00', $amortizing['amount'], 'the money still sums both positions');

        $capitalized = $plans->firstWhere('payout_type', 'capitalized');
        $this->assertSame('Капитализация', $capitalized['label']);
        $this->assertSame(2, $capitalized['count'], 'two loans = 2 кредита');
        $this->assertSame('300.00', $capitalized['amount']);

        $legacy = $plans->firstWhere('payout_type', null);
        $this->assertSame('Без оферта', $legacy['label']);
        $this->assertSame(1, $legacy['count']);
        $this->assertSame('50.00', $legacy['amount']);
    }

    public function test_summary_by_plan_is_scoped_to_the_authenticated_user(): void
    {
        Notification::fake();

        $user = $this->investor();
        $other = $this->investor();
        $loan = $this->fundableLoan();
        $this->investVia($other, $loan, '400.00', PayoutType::InterestOnly);

        $plans = $this->actingAs($user)->getJson('/api/portfolio/summary')
            ->assertOk()
            ->json('breakdown_by_plan');

        $this->assertSame([], $plans, "someone else's positions must not leak into my wheel");
    }

    // ── «Вашата инвестиция» на страницата на кредита ──

    public function test_my_investments_returns_own_positions_with_plan_and_schedule(): void
    {
        Notification::fake();

        $user = $this->investor();
        $loan = $this->fundableLoan();
        $this->investVia($user, $loan, '500.00', PayoutType::Amortizing);
        $this->investVia($user, $loan, '250.00', PayoutType::InterestOnly);
        $loan = $loan->fresh();
        if ($loan->status !== Loan::STATUS_ACTIVE) {
            // Partial funding leaves the loan open — activate to generate the
            // per-investment schedules the card must display.
            $loan->forceFill(['funded_amount' => $loan->amount])->save();
            $loan->fresh()->transitionTo(Loan::STATUS_FUNDED);
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        $data = $this->actingAs($user)->getJson("/api/loans/{$loan->id}/my-investments")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $byLabel = collect($data)->keyBy('payout_label');
        $this->assertSame('500.00', $byLabel['Анюитет']['amount']);
        $this->assertSame('250.00', $byLabel['Само лихва']['amount']);
        // Offer-based invests conclude a contract atomically — the card links it.
        $this->assertTrue($byLabel['Анюитет']['has_contract']);
        // The personal schedule with monthly installments is present.
        $this->assertCount(12, $byLabel['Анюитет']['schedule']);
        $this->assertCount(12, $byLabel['Само лихва']['schedule']);
        // The snapshotted (not loan-level) rate is what she sees.
        $this->assertNotNull($byLabel['Анюитет']['interest_rate']);
    }

    public function test_my_investments_excludes_other_users_positions(): void
    {
        Notification::fake();

        $user = $this->investor();
        $other = $this->investor();
        $loan = $this->fundableLoan();
        $this->investVia($user, $loan, '100.00', PayoutType::Amortizing);
        $this->investVia($other, $loan, '900.00', PayoutType::Capitalized);

        $data = $this->actingAs($user)->getJson("/api/loans/{$loan->id}/my-investments")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('100.00', $data[0]['amount']);
    }

    public function test_my_investments_is_empty_without_a_position(): void
    {
        $user = $this->investor();
        $loan = $this->fundableLoan();

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}/my-investments")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_my_investments_allowed_on_private_loan_through_own_position(): void
    {
        Notification::fake();

        $user = $this->investor();
        $loan = $this->fundableLoan();
        $this->investVia($user, $loan, '100.00', PayoutType::Amortizing);
        $loan->forceFill([
            'visibility' => Loan::VISIBILITY_PRIVATE,
            'share_token' => Loan::generateShareToken(),
        ])->save();

        $data = $this->actingAs($user)->getJson("/api/loans/{$loan->id}/my-investments")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
    }

    public function test_my_investments_forbidden_on_foreign_private_loan(): void
    {
        $user = $this->investor();
        $loan = $this->fundableLoan();
        $loan->forceFill([
            'visibility' => Loan::VISIBILITY_PRIVATE,
            'share_token' => Loan::generateShareToken(),
        ])->save();

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}/my-investments")
            ->assertForbidden();
    }
}
