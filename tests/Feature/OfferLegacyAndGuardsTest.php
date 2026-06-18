<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\Loans\BuybackExecutionService;
use App\Services\Loans\EarlyRepaymentExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Guards the additive boundary: legacy loans (investments with no offer) keep
 * the original per-loan amortization path untouched, and the lifecycle actions
 * that assume that single schedule (buyback / early repayment) refuse to run
 * on offer-based loans rather than mis-compute.
 */
class OfferLegacyAndGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    public function test_legacy_investment_without_offer_uses_amortization_schedule(): void
    {
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '10.00', 'term_months' => 12,
        ]);
        $user = $this->investor();

        // Invest via the service WITHOUT an offer → legacy path.
        app(InvestmentService::class)->invest($user, $loan, '1000.00', 'legacy-key');

        $loan->refresh();
        $this->assertFalse($loan->usesOffers());
        $this->assertSame(Loan::STATUS_FUNDED, $loan->status);

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        // Legacy per-loan amortization schedule generated; no investment schedules.
        $this->assertSame(12, $loan->amortizationSchedules()->count());
        $this->assertSame(0, $loan->investmentSchedules()->count());
    }

    private function activeOfferLoan(): Loan
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 3000, 'investable_amount' => 3000, 'funded_amount' => 0, 'term_months' => 12,
        ]);
        $user = $this->investor();
        $service = app(InvestmentService::class);

        foreach (PayoutType::cases() as $type) {
            $offerId = $loan->offers()->where('payout_type', $type)->value('id');
            $service->invest($user, $loan->fresh(), '1000.00', "g-{$type->value}", $offerId);
        }

        $loan->refresh()->transitionTo(Loan::STATUS_ACTIVE);

        return $loan->refresh();
    }

    public function test_early_repayment_blocked_for_offer_based_loan(): void
    {
        $loan = $this->activeOfferLoan();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('per-offer investor payouts');
        app(EarlyRepaymentExecutionService::class)->execute($loan->id, 1);
    }

    public function test_buyback_blocked_for_offer_based_loan(): void
    {
        $loan = $this->activeOfferLoan();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('per-offer investor payouts');
        app(BuybackExecutionService::class)->execute($loan->id, 1);
    }
}
