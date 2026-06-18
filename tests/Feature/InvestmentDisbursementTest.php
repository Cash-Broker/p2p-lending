<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentDisbursementService;
use App\Services\InvestmentService;
use App\Services\OfferProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The differential payout engine: at activation each investment gets its own
 * schedule shaped by its offer, and disbursement credits each investor exactly
 * what their offer promised. One investor splits 1000€ across all three offers.
 */
class InvestmentDisbursementTest extends TestCase
{
    use RefreshDatabase;

    private function activeLoanWithSplitInvestor(): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 3000, 'investable_amount' => 3000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        $service = app(InvestmentService::class);
        foreach (PayoutType::cases() as $type) {
            $offerId = $loan->offers()->where('payout_type', $type)->value('id');
            $service->invest($user, $loan->fresh(), '1000.00', "inv-{$type->value}", $offerId);
        }

        // 3 × 1000 == investable 3000 → loan auto-transitioned to FUNDED.
        $loan->refresh();
        $this->assertSame(Loan::STATUS_FUNDED, $loan->status);

        $loan->transitionTo(Loan::STATUS_ACTIVE); // generates investment_schedules

        return [$loan->refresh(), $user];
    }

    public function test_activation_generates_per_investment_schedules_per_offer(): void
    {
        [$loan, $user] = $this->activeLoanWithSplitInvestor();

        $this->assertSame(0, $loan->amortizationSchedules()->count(), 'offer loan must NOT use the legacy per-loan schedule');

        $byType = $loan->investments()->with('schedules')->get()
            ->keyBy(fn ($i) => $i->payout_type->value);

        // amortizing + interest-only → 12 rows; capitalized → 1 row (maturity).
        $this->assertCount(12, $byType['amortizing']->schedules);
        $this->assertCount(12, $byType['interest_only']->schedules);
        $this->assertCount(1, $byType['capitalized']->schedules);

        // Each investment's principal sums back to its 1000 € stake.
        foreach ($byType as $investment) {
            $sum = $investment->schedules->reduce(fn ($c, $r) => bcadd($c, (string) $r->principal, 2), '0.00');
            $this->assertSame('1000.00', $sum);
        }
    }

    public function test_disbursement_credits_each_investor_per_their_offer(): void
    {
        [$loan, $user] = $this->activeLoanWithSplitInvestor();

        // Expected profit = Σ summary interest across the three offers.
        $proj = new OfferProjectionService();
        $expectedEarned = '0.00';
        foreach ([[PayoutType::Amortizing, '12.00'], [PayoutType::InterestOnly, '16.00'], [PayoutType::Capitalized, '20.00']] as [$type, $rate]) {
            $expectedEarned = bcadd($expectedEarned, $proj->summary('1000.00', $rate, 12, $type)['total_interest'], 2);
        }

        // Pay everything due across the whole term.
        $result = app(InvestmentDisbursementService::class)->disburseDue($loan->id, now()->addDays(400));

        $this->assertSame(25, $result['paid_count']); // 12 + 12 + 1
        $this->assertSame(0, $loan->investmentSchedules()->whereIn('status', ['pending', 'late'])->count());

        $wallet = $user->wallet->fresh();
        // All principal (3 × 1000) returned to available; invested bucket emptied.
        $this->assertSame('0.00', $wallet->invested);
        // Earned == total interest across all three structures (the profit shown).
        $this->assertSame($expectedEarned, $wallet->earned);
        // available = 5000 start − 3000 invested + 3000 principal + earned interest.
        $this->assertSame(bcadd('5000.00', $expectedEarned, 2), $wallet->available);
    }

    public function test_disbursement_pays_only_installments_due(): void
    {
        [$loan, $user] = $this->activeLoanWithSplitInvestor();

        // As-of ~1.5 months in: only the first monthly installments of the
        // amortizing + interest-only investments are due; capitalized (maturity
        // only) and later months are not.
        $result = app(InvestmentDisbursementService::class)->disburseDue($loan->id, now()->addDays(45));

        $this->assertSame(2, $result['paid_count']);
        $this->assertGreaterThan(0, $loan->investmentSchedules()->where('status', 'pending')->count());
    }
}
