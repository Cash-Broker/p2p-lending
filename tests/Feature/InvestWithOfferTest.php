<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The investor-facing half of the 3-offer feature: choosing an offer, the
 * snapshot it leaves on the investment, validation that the offer belongs to
 * the loan + is enabled (incl. the in-lock TOCTOU recheck), splitting across
 * all three, and idempotent retries preserving the original choice.
 */
class InvestWithOfferTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private function offerId(Loan $loan, PayoutType $type): int
    {
        return $loan->offers()->where('payout_type', $type)->value('id');
    }

    private function ownInvestments(User $user, Loan $loan)
    {
        return Investment::where('user_id', $user->id)->where('loan_id', $loan->id);
    }

    public function test_invest_records_offer_snapshot(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $user = $this->investor();
        $offerId = $this->offerId($loan, PayoutType::Capitalized); // 20%

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 1000,
            'loan_offer_id' => $offerId,
        ], ['X-Idempotency-Key' => 'snap-' . uniqid()])->assertStatus(201);

        $this->assertDatabaseHas('investments', [
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'loan_offer_id' => $offerId,
            'interest_rate' => '20.00',
            'payout_type' => 'capitalized',
        ]);
    }

    public function test_invest_rejects_offer_from_another_loan(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000]);
        $otherLoan = Loan::factory()->published()->create(['amount' => 10000]);
        $user = $this->investor();

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 100,
            'loan_offer_id' => $otherLoan->offers()->value('id'),
        ], ['X-Idempotency-Key' => 'foreign-' . uniqid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('loan_offer_id');
    }

    public function test_invest_rejects_disabled_offer(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000]);
        $offer = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->first();
        $offer->update(['is_enabled' => false]);
        $user = $this->investor();

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 100,
            'loan_offer_id' => $offer->id,
        ], ['X-Idempotency-Key' => 'disabled-' . uniqid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('loan_offer_id');
    }

    public function test_service_rechecks_disabled_offer_inside_lock(): void
    {
        // TOCTOU: offer passes request validation, then is disabled before the
        // service runs. The in-lock recheck must still reject it.
        $loan = Loan::factory()->published()->create(['amount' => 10000]);
        $offer = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->first();
        $offer->update(['is_enabled' => false]);
        $user = $this->investor();

        $this->expectException(ValidationException::class);
        app(InvestmentService::class)->invest($user, $loan, '100.00', 'toctou-key', $offer->id);
    }

    public function test_investor_can_split_across_all_three_offers(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $user = $this->investor();

        foreach (PayoutType::cases() as $type) {
            $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
                'amount' => 100,
                'loan_offer_id' => $this->offerId($loan, $type),
            ], ['X-Idempotency-Key' => "split-{$type->value}-" . uniqid()])->assertStatus(201);
        }

        $this->assertSame(3, $this->ownInvestments($user, $loan)->count());
        $this->assertEqualsCanonicalizing(
            ['amortizing', 'interest_only', 'capitalized'],
            $this->ownInvestments($user, $loan)->pluck('payout_type')->map(fn ($p) => $p->value)->all(),
        );
    }

    public function test_idempotent_retry_keeps_original_offer(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $user = $this->investor();
        $key = 'idem-offer-key';
        $firstOffer = $this->offerId($loan, PayoutType::Amortizing);
        $secondOffer = $this->offerId($loan, PayoutType::Capitalized);

        $r1 = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 100, 'loan_offer_id' => $firstOffer,
        ], ['X-Idempotency-Key' => $key])->assertStatus(201);

        // Same key, DIFFERENT offer → must return the original investment/offer.
        $r2 = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 100, 'loan_offer_id' => $secondOffer,
        ], ['X-Idempotency-Key' => $key])->assertStatus(201);

        $this->assertSame($r1->json('investment.id'), $r2->json('investment.id'));
        $this->assertSame(1, $this->ownInvestments($user, $loan)->count());
        $this->assertSame($firstOffer, $this->ownInvestments($user, $loan)->first()->loan_offer_id);
    }
}
