<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The boss's core requirement: offers stay editable on already-published
 * loans, then lock once funding has happened. Enforced by LoanOffer's model
 * guard (the Filament RelationManager mirrors it via status-gated actions).
 */
class LoanOfferAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_loan_is_seeded_with_three_default_offers(): void
    {
        $loan = Loan::factory()->create();

        $this->assertSame(3, $loan->offers()->count());
        $this->assertEqualsCanonicalizing(
            ['12.00', '16.00', '20.00'],
            $loan->offers()->pluck('interest_rate')->map(fn ($r) => (string) $r)->all(),
        );
    }

    public function test_offer_rate_is_editable_on_a_published_loan(): void
    {
        $loan = Loan::factory()->published()->create();
        $offer = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->first();

        $offer->update(['interest_rate' => '17.50']);

        $this->assertSame('17.50', (string) $offer->fresh()->interest_rate);
    }

    public function test_offer_can_be_disabled_while_funding(): void
    {
        $loan = Loan::factory()->funding()->create();
        $offer = $loan->offers()->where('payout_type', PayoutType::Capitalized)->first();

        $offer->update(['is_enabled' => false]);

        $this->assertFalse($offer->fresh()->is_enabled);
    }

    public function test_offer_stays_editable_on_active_loans(): void
    {
        // Reversed 2026-08-10 (client, explicit): offers edit in EVERY
        // status. Committed investors keep their snapshotted rate.
        $loan = Loan::factory()->active()->create();
        $offer = $loan->offers()->where('payout_type', PayoutType::Amortizing)->first();

        $offer->update(['interest_rate' => '13.00']);

        $this->assertSame('13.00', (string) $offer->fresh()->interest_rate);
    }
}
