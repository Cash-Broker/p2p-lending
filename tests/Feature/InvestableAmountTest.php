<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use App\Services\AmortizationService;
use App\Services\InvestmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Loan-overhaul Phases 2 & 3 — the "available for investment" cap.
 *
 * Investors fund up to investable_amount (not the full loan amount), and the
 * on-platform amortization schedule amortizes the investable portion so
 * investors are repaid exactly the capital they invested.
 */
class InvestableAmountTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $balance): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        // Balance columns are guarded — set via forceFill like the rest of the suite.
        $wallet = $user->wallet()->create();
        $wallet->forceFill(['available' => $balance])->save();

        return $user;
    }

    public function test_schedule_amortizes_investable_amount_not_full_amount(): void
    {
        $loan = Loan::factory()->create([
            'amount' => '10000.00',
            'investable_amount' => '7000.00',
            'term_months' => 12,
            'interest_rate' => '10.00',
        ]);

        app(AmortizationService::class)->generateSchedule($loan);

        $sum = $loan->amortizationSchedules()->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');

        // Σ principal == investable (7000), NOT the full amount (10000).
        $this->assertSame('7000.00', $sum);
        $this->assertSame('7000.00', $loan->amortizationBase());
    }

    public function test_investor_cannot_fund_beyond_the_investable_cap(): void
    {
        $loan = Loan::factory()->published()->create([
            'amount' => '10000.00',
            'investable_amount' => '7000.00',
        ]);
        $investor = $this->investor('20000.00');

        try {
            app(InvestmentService::class)->invest($investor, $loan, '7001.00', 'key-over');
            $this->fail('Expected overfunding to be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('7000.00', $e->validator->errors()->first('amount'));
        }

        $this->assertSame('0.00', $loan->fresh()->funded_amount);
    }

    public function test_funding_to_the_investable_cap_transitions_to_funded(): void
    {
        $loan = Loan::factory()->published()->create([
            'amount' => '10000.00',
            'investable_amount' => '7000.00',
        ]);
        $investor = $this->investor('10000.00');

        app(InvestmentService::class)->invest($investor, $loan, '7000.00', 'key-cap');

        $fresh = $loan->fresh();
        $this->assertTrue($fresh->isFullyFunded());
        $this->assertEquals(Loan::STATUS_FUNDED, $fresh->status);
    }

    public function test_loan_without_cap_behaves_as_full_amount(): void
    {
        // Backward compatibility: investable_amount == amount → identical to pre-cap.
        $loan = Loan::factory()->create(['amount' => '5000.00', 'investable_amount' => '5000.00']);
        $this->assertSame('5000.00', $loan->investableAmount());
        $this->assertSame('5000.00', $loan->amortizationBase());
    }
}
