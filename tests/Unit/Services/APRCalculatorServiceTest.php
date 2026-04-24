<?php

namespace Tests\Unit\Services;

use App\Models\Loan;
use App\Services\APRCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F5 — APRCalculatorService unit tests.
 *
 * The service is a pure reader in v1 (nominal pass-through from
 * `loans.interest_rate_annual`). These tests pin the exact contract
 * so that when the IRR solver lands in v1.1+ the swap doesn't break
 * existing callers.
 */
class APRCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_returns_formatted_interest_rate_annual(): void
    {
        $loan = Loan::factory()->make(['interest_rate_annual' => '10.50']);
        $svc = new APRCalculatorService();

        $this->assertSame('10.50', $svc->calculate($loan));
    }

    public function test_calculate_returns_null_for_zero_rate(): void
    {
        // Zero is a legitimate F1-L6 guard case — column exists, value
        // is 0, service returns null → UI renders "—".
        $loan = Loan::factory()->make(['interest_rate_annual' => 0]);
        $svc = new APRCalculatorService();

        $this->assertNull($svc->calculate($loan));
    }

    public function test_calculate_returns_null_for_negative_rate(): void
    {
        // DB CHECK doesn't prevent negative values on this column (no
        // CHECK was added for interest_rate_annual). Defensive guard.
        $loan = Loan::factory()->make(['interest_rate_annual' => -1.5]);
        $svc = new APRCalculatorService();

        $this->assertNull($svc->calculate($loan));
    }

    public function test_calculate_returns_null_for_null_rate(): void
    {
        // DB column is NOT NULL so this shouldn't happen in production,
        // but the service must still not blow up if it ever does.
        $loan = new Loan();
        $loan->interest_rate_annual = null;
        $svc = new APRCalculatorService();

        $this->assertNull($svc->calculate($loan));
    }

    public function test_precision_normalises_unpadded_single_decimal(): void
    {
        // Raw DB-level update (bypassing cast) could leave a value like
        // "12.5" in the row. Service must normalise to "12.50" on the
        // wire so Vue/JS parseFloat + toFixed doesn't show "12.5%".
        $loan = Loan::factory()->make(['interest_rate_annual' => '12.5']);
        $svc = new APRCalculatorService();

        $this->assertSame('12.50', $svc->calculate($loan));
    }

    public function test_pass_through_preserves_exact_two_decimals(): void
    {
        // Verify no rounding drift — "9.99" stays "9.99", "10.01"
        // stays "10.01", covers both under- and over-the-nines.
        $low = Loan::factory()->make(['interest_rate_annual' => '9.99']);
        $high = Loan::factory()->make(['interest_rate_annual' => '10.01']);
        $svc = new APRCalculatorService();

        $this->assertSame('9.99', $svc->calculate($low));
        $this->assertSame('10.01', $svc->calculate($high));
    }

    public function test_large_rate_within_column_bounds_preserved(): void
    {
        // The DB column is decimal(5,2) → max 999.99. Anything up to
        // that should pass through without corruption.
        $loan = Loan::factory()->make(['interest_rate_annual' => '999.99']);
        $svc = new APRCalculatorService();

        $this->assertSame('999.99', $svc->calculate($loan));
    }
}
