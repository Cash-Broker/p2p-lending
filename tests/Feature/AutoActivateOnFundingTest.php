<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\User;
use App\Services\InvestmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Активирай» is gone (client decision 2026-08-13, Reni): the last euro
 * activates the loan, and repayment is counted from the funding date.
 *
 * The consequence she accepted explicitly — «това са кредити, няма
 * връщане» — is that `funded` is no longer a state a loan rests in, so
 * there is no checkpoint between a loan filling up and the platform owing
 * money on a schedule.
 */
class AutoActivateOnFundingTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '20000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private function offerLoan(array $attributes = []): Loan
    {
        return Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '10.00', 'term_months' => 12,
            ...$attributes,
        ]);
    }

    private function offerId(Loan $loan, PayoutType $type): int
    {
        return $loan->offers()->where('payout_type', $type)->value('id');
    }

    public function test_fully_funding_activates_the_loan_and_generates_schedules(): void
    {
        $loan = $this->offerLoan();

        app(InvestmentService::class)->invest(
            $this->investor(), $loan, '1000.00', 'fund-'.uniqid(),
            $this->offerId($loan, PayoutType::Amortizing),
        );

        $loan->refresh();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->status);
        $this->assertSame(12, $loan->investmentSchedules()->count());
    }

    public function test_repayment_is_counted_from_the_funding_date(): void
    {
        $loan = $this->offerLoan();

        app(InvestmentService::class)->invest(
            $this->investor(), $loan, '1000.00', 'fund-'.uniqid(),
            $this->offerId($loan, PayoutType::Amortizing),
        );

        $first = $loan->investmentSchedules()->orderBy('due_date')->first();

        // The whole point of dropping the button: the clock starts when the
        // loan fills up, not whenever an admin gets round to it.
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $first->due_date->toDateString(),
        );
    }

    public function test_partial_funding_does_not_activate(): void
    {
        $loan = $this->offerLoan();

        app(InvestmentService::class)->invest(
            $this->investor(), $loan, '400.00', 'partial-'.uniqid(),
            $this->offerId($loan, PayoutType::InterestOnly),
        );

        $loan->refresh();

        $this->assertSame(Loan::STATUS_FUNDING, $loan->status);
        $this->assertSame(0, $loan->investmentSchedules()->count());
    }

    public function test_every_investor_gets_their_own_plan_at_activation(): void
    {
        $loan = $this->offerLoan(['amount' => 1000, 'investable_amount' => 1000]);
        $service = app(InvestmentService::class);

        $service->invest($this->investor(), $loan, '600.00', 'a-'.uniqid(),
            $this->offerId($loan, PayoutType::Amortizing));
        $service->invest($this->investor(), $loan->fresh(), '400.00', 'b-'.uniqid(),
            $this->offerId($loan, PayoutType::Capitalized));

        $loan->refresh();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->status);
        // Amortizing → 12 rows; capitalized → a single maturity row.
        $this->assertSame(13, $loan->investmentSchedules()->count());
    }

    public function test_activation_is_recorded_as_a_system_event(): void
    {
        $loan = $this->offerLoan();

        app(InvestmentService::class)->invest(
            $this->investor(), $loan, '1000.00', 'fund-'.uniqid(),
            $this->offerId($loan, PayoutType::Amortizing),
        );

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('to_status', Loan::STATUS_ACTIVE)
            ->firstOrFail();

        $this->assertSame(Loan::STATUS_FUNDED, $event->from_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_SYSTEM, $event->triggered_by);
        $this->assertNull($event->triggered_by_user_id);
        $this->assertSame('auto_activated_on_funding', $event->metadata['reason']);
    }

    public function test_legacy_loans_also_activate_and_get_their_amortization_schedule(): void
    {
        $loan = $this->offerLoan();

        // No offer id → legacy path.
        app(InvestmentService::class)->invest($this->investor(), $loan, '1000.00', 'legacy-'.uniqid());

        $loan->refresh();

        $this->assertFalse($loan->usesOffers());
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->status);
        $this->assertSame(12, $loan->amortizationSchedules()->count());
        $this->assertSame(0, $loan->investmentSchedules()->count());
    }

    public function test_no_money_moves_at_activation(): void
    {
        $investor = $this->investor('5000.00');
        $loan = $this->offerLoan();

        app(InvestmentService::class)->invest(
            $investor, $loan, '1000.00', 'fund-'.uniqid(),
            $this->offerId($loan, PayoutType::Amortizing),
        );

        $wallet = $investor->wallet->fresh();

        // Only the investment itself moved money: available → invested.
        // Activation adds schedule rows, never a wallet mutation.
        $this->assertSame('4000.00', $wallet->available);
        $this->assertSame('1000.00', $wallet->invested);
        $this->assertSame('0.00', $wallet->accrued);
    }
}
