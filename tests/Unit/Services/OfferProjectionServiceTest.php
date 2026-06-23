<?php

namespace Tests\Unit\Services;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Services\AmortizationService;
use App\Services\OfferProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correctness backbone for the 3-offer payout math. Pins each structure's
 * exact rows + profit summary, the edge cases (zero rate, 1-month term), and
 * proves the AMORTIZING projection equals the live AmortizationService output.
 */
class OfferProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): OfferProjectionService
    {
        return new OfferProjectionService;
    }

    // ---- AMORTIZING --------------------------------------------------------

    public function test_amortizing_principal_sums_to_invested_amount(): void
    {
        $rows = $this->svc()->schedule('1000.00', '12.00', 12, PayoutType::Amortizing);

        $this->assertCount(12, $rows);
        $sum = array_reduce($rows, fn ($c, $r) => bcadd($c, $r['principal'], 2), '0.00');
        $this->assertSame('1000.00', $sum);
        // Every row total == principal + interest.
        foreach ($rows as $r) {
            $this->assertSame(bcadd($r['principal'], $r['interest'], 2), $r['total']);
        }
    }

    public function test_amortizing_projection_matches_live_amortization_service(): void
    {
        // Provable identity: the projection used for display must equal the
        // schedule the borrower path actually generates for the same inputs.
        $loan = Loan::factory()->create([
            'amount' => 1000,
            'investable_amount' => 1000,
            'interest_rate' => '12.00',
            'term_months' => 12,
        ]);
        app(AmortizationService::class)->generateSchedule($loan);

        $live = $loan->amortizationSchedules()->orderBy('due_date')->orderBy('id')->get();
        $proj = $this->svc()->schedule('1000.00', '12.00', 12, PayoutType::Amortizing);

        $this->assertCount($live->count(), $proj);
        foreach ($live as $i => $row) {
            $this->assertSame((string) $row->principal, $proj[$i]['principal'], "principal row {$i}");
            $this->assertSame((string) $row->interest, $proj[$i]['interest'], "interest row {$i}");
            $this->assertSame((string) $row->total, $proj[$i]['total'], "total row {$i}");
        }
    }

    // ---- INTEREST_ONLY -----------------------------------------------------

    public function test_interest_only_pays_interest_monthly_principal_at_end(): void
    {
        $rows = $this->svc()->schedule('1000.00', '12.00', 12, PayoutType::InterestOnly);

        $this->assertCount(12, $rows);
        // Months 1..11: interest 10.00 (1000 * 0.01), principal 0.
        for ($i = 0; $i < 11; $i++) {
            $this->assertSame('0.00', $rows[$i]['principal']);
            $this->assertSame('10.00', $rows[$i]['interest']);
            $this->assertSame('10.00', $rows[$i]['total']);
        }
        // Final month: full principal + last interest.
        $this->assertSame('1000.00', $rows[11]['principal']);
        $this->assertSame('10.00', $rows[11]['interest']);
        $this->assertSame('1010.00', $rows[11]['total']);
    }

    // ---- CAPITALIZED -------------------------------------------------------

    public function test_capitalized_single_row_at_maturity_with_compounded_interest(): void
    {
        $rows = $this->svc()->schedule('1000.00', '12.00', 12, PayoutType::Capitalized);

        $this->assertCount(1, $rows);
        // 1000 * (1.01)^12 = 1126.82 (compounded, truncated to 2dp).
        $this->assertSame('1000.00', $rows[0]['principal']);
        $this->assertSame('1126.82', $rows[0]['total']);
        $this->assertSame('126.82', $rows[0]['interest']);
    }

    public function test_capitalized_one_month_equals_simple_interest(): void
    {
        // No off-by-one in the compounding: (1+r)^1 interest == P*r.
        $rows = $this->svc()->schedule('1000.00', '12.00', 1, PayoutType::Capitalized);

        $this->assertCount(1, $rows);
        $this->assertSame('10.00', $rows[0]['interest']); // 1000 * (12/1200)
        $this->assertSame('1010.00', $rows[0]['total']);
    }

    // ---- ZERO RATE ---------------------------------------------------------

    public function test_zero_rate_all_modes_degrade_to_principal_only(): void
    {
        $amort = $this->svc()->schedule('1200.00', '0.00', 12, PayoutType::Amortizing);
        $this->assertSame('100.00', $amort[0]['principal']);
        $this->assertSame('0.00', $amort[0]['interest']);

        $io = $this->svc()->schedule('1200.00', '0.00', 12, PayoutType::InterestOnly);
        $this->assertSame('0.00', $io[0]['interest']);
        $this->assertSame('1200.00', $io[11]['principal']);

        $cap = $this->svc()->schedule('1200.00', '0.00', 12, PayoutType::Capitalized);
        $this->assertCount(1, $cap);
        $this->assertSame('1200.00', $cap[0]['total']);
        $this->assertSame('0.00', $cap[0]['interest']);
    }

    // ---- SUMMARY (profit shown to the client) ------------------------------

    public function test_summary_profit_per_structure(): void
    {
        $io = $this->svc()->summary('1000.00', '12.00', 12, PayoutType::InterestOnly);
        $this->assertSame('120.00', $io['total_interest']);  // P*r*n = 1000*0.01*12
        $this->assertSame('10.00', $io['monthly_payment']);
        $this->assertSame('1010.00', $io['maturity_payment']);

        $cap = $this->svc()->summary('1000.00', '12.00', 12, PayoutType::Capitalized);
        $this->assertSame('126.82', $cap['total_interest']); // P*((1+r)^n - 1)
        $this->assertNull($cap['monthly_payment']);          // nothing monthly

        $amort = $this->svc()->summary('1000.00', '12.00', 12, PayoutType::Amortizing);
        $this->assertSame('1000.00', $amort['total_principal']);
    }

    public function test_summary_ordering_capitalized_beats_interest_only_beats_amortizing(): void
    {
        // Default offer rates 12/16/20 over 24 months — the comparison the
        // client sees. Higher rate + later payout → strictly more profit.
        $amort = $this->svc()->summary('1000.00', '12.00', 24, PayoutType::Amortizing);
        $io = $this->svc()->summary('1000.00', '16.00', 24, PayoutType::InterestOnly);
        $cap = $this->svc()->summary('1000.00', '20.00', 24, PayoutType::Capitalized);

        $this->assertGreaterThan(0, bccomp($io['total_interest'], $amort['total_interest'], 2));
        $this->assertGreaterThan(0, bccomp($cap['total_interest'], $io['total_interest'], 2));
    }

    public function test_invalid_term_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->schedule('1000.00', '12.00', 0, PayoutType::Amortizing);
    }

    public function test_amortizing_monthly_payment_is_first_installment_not_a_constant(): void
    {
        // Documents the audit's LOW presentation finding: monthly_payment is
        // the FIRST installment; the final installment absorbs rounding drift,
        // so monthly_payment × term does NOT reconcile to total_repaid. The
        // true final payment is surfaced as maturity_payment instead.
        $amort = $this->svc()->summary('1000.00', '12.00', 12, PayoutType::Amortizing);

        $this->assertSame('88.84', $amort['monthly_payment']);
        $this->assertSame('88.90', $amort['maturity_payment']);
        $this->assertSame('1066.14', $amort['total_repaid']);

        // The naive reconciliation a user might attempt is intentionally off —
        // they must use maturity_payment for the last installment.
        $naive = bcmul($amort['monthly_payment'], '12', 2); // 1066.08
        $this->assertNotSame($naive, $amort['total_repaid']);
        $this->assertSame(
            $amort['total_repaid'],
            bcadd(bcmul($amort['monthly_payment'], '11', 2), $amort['maturity_payment'], 2),
            'total_repaid == 11 × monthly_payment + 1 × maturity_payment',
        );
    }
}
