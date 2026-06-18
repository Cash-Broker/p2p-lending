<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\User;
use App\Services\OfferProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client API surface for the 3 offers: the offers[] + rate range on the loan
 * payloads (legacy interest_rate preserved), and the /offer-quotes profit
 * comparison the boss wants visible across all three structures.
 */
class LoanOffersApiTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_loan_show_includes_offers_rate_range_and_legacy_rate(): void
    {
        $loan = Loan::factory()->published()->create(['interest_rate' => '13.00', 'term_months' => 12]);

        $response = $this->actingAs($this->investor())->getJson("/api/loans/{$loan->id}");

        $response->assertOk()
            ->assertJsonPath('interest_rate', '13.00') // legacy field preserved
            ->assertJsonPath('offer_rate_range', ['12.00', '20.00'])
            ->assertJsonCount(3, 'offers')
            ->assertJsonStructure(['offers' => [['id', 'payout_type', 'label', 'description', 'interest_rate']]]);
    }

    public function test_loan_index_includes_offers(): void
    {
        Loan::factory()->published()->create();

        $response = $this->actingAs($this->investor())->getJson('/api/loans');

        $response->assertOk()
            ->assertJsonPath('data.0.offer_rate_range', ['12.00', '20.00'])
            ->assertJsonCount(3, 'data.0.offers');
    }

    public function test_disabled_offer_is_hidden_from_client(): void
    {
        $loan = Loan::factory()->published()->create();
        $loan->offers()->where('payout_type', PayoutType::InterestOnly)->first()->update(['is_enabled' => false]);

        $this->actingAs($this->investor())->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonCount(2, 'offers');
    }

    public function test_offer_quotes_returns_profit_per_offer(): void
    {
        $loan = Loan::factory()->published()->create(['term_months' => 12]);
        $proj = new OfferProjectionService();

        $response = $this->actingAs($this->investor())
            ->getJson("/api/loans/{$loan->id}/offer-quotes?amount=1000");

        $response->assertOk()->assertJsonCount(3, 'data');

        $quotes = collect($response->json('data'))->keyBy('payout_type');

        $this->assertSame(
            $proj->summary('1000.00', '20.00', 12, PayoutType::Capitalized)['total_interest'],
            $quotes['capitalized']['total_interest'],
        );
        // The headline comparison: more profit as you move up the structures.
        $this->assertGreaterThan(
            (float) $quotes['amortizing']['total_interest'],
            (float) $quotes['capitalized']['total_interest'],
        );
        $this->assertNull($quotes['capitalized']['monthly_payment']);
        $this->assertNotEmpty($quotes['amortizing']['schedule']);
    }

    public function test_offer_quotes_defaults_to_investable_amount(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 5000, 'investable_amount' => 5000, 'term_months' => 12]);

        $this->actingAs($this->investor())->getJson("/api/loans/{$loan->id}/offer-quotes")
            ->assertOk()
            ->assertJsonPath('amount', '5000.00');
    }
}
