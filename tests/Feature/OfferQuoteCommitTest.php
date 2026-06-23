<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Quote-vs-commit protection (audit MEDIUM): the projection endpoint reads the
 * LIVE offer rate while the binding payout uses the rate snapshotted at commit.
 * If the boss edits the rate mid-funding, an investor could be paid materially
 * less than the quote they just saw. When the client passes the rate it quoted,
 * the commit now rejects a drifted rate instead of silently committing.
 */
class OfferQuoteCommitTest extends TestCase
{
    use RefreshDatabase;

    private function fundingLoanWithOffer(string $rate): array
    {
        $loan = Loan::factory()->create([
            'status' => Loan::STATUS_PUBLISHED,
            'amount' => '1000.00',
            'investable_amount' => '1000.00',
            'funded_amount' => '0.00',
            'published_at' => now(),
        ]);

        // Loans auto-seed three offers; drive one to the quoted rate.
        $offer = $loan->offers()->where('is_enabled', true)->orderBy('position')->firstOrFail();
        $offer->update(['interest_rate' => $rate]);

        return [$loan->fresh(), $offer->fresh()];
    }

    private function investorWith(string $available): User
    {
        $user = User::factory()->create();
        $user->wallet()->create();
        $user->wallet->forceFill(['available' => $available])->save();

        return $user;
    }

    public function test_commit_succeeds_when_quoted_rate_matches_live_offer(): void
    {
        [$loan, $offer] = $this->fundingLoanWithOffer('20.00');
        $investor = $this->investorWith('1000.00');

        $investment = app(InvestmentService::class)->invest(
            $investor, $loan, '500.00', (string) Str::uuid(), $offer->id, '20.00',
        );

        $this->assertSame('20.00', (string) $investment->interest_rate);
    }

    public function test_commit_rejects_a_stale_quote_after_boss_edits_the_rate(): void
    {
        [$loan, $offer] = $this->fundingLoanWithOffer('20.00');
        $service = app(InvestmentService::class);

        // First investor commits at 20% → loan moves to funding (offers still editable).
        $first = $this->investorWith('1000.00');
        $service->invest($first, $loan, '500.00', (string) Str::uuid(), $offer->id, '20.00');

        // Boss edits the offer down to 16% mid-funding.
        $offer->fresh()->update(['interest_rate' => '16.00']);

        // A second investor who was quoted 20% must NOT be silently paid 16%.
        $second = $this->investorWith('1000.00');

        try {
            $service->invest($second, $loan->fresh(), '300.00', (string) Str::uuid(), $offer->id, '20.00');
            $this->fail('Expected a stale-quote rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_interest_rate', $e->errors());
        }

        // Re-confirming at the current rate succeeds.
        $investment = $service->invest($second, $loan->fresh(), '300.00', (string) Str::uuid(), $offer->id, '16.00');
        $this->assertSame('16.00', (string) $investment->interest_rate);
    }

    public function test_legacy_callers_without_expected_rate_are_unaffected(): void
    {
        [$loan, $offer] = $this->fundingLoanWithOffer('18.00');
        $investor = $this->investorWith('1000.00');

        // No expected rate passed → prior behavior (snapshot the live rate).
        $investment = app(InvestmentService::class)->invest(
            $investor, $loan, '500.00', (string) Str::uuid(), $offer->id,
        );

        $this->assertSame('18.00', (string) $investment->interest_rate);
    }
}
