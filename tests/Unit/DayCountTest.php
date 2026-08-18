<?php

namespace Tests\Unit;

use App\Support\DayCount;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * 30/360 (US/NASD) — the basis Reni confirmed for interest that stops
 * mid-period, because the whole amortization plan is built on it.
 */
class DayCountTest extends TestCase
{
    private function days(string $from, string $to): int
    {
        return DayCount::thirty360(Carbon::parse($from), Carbon::parse($to));
    }

    public function test_whole_months_count_thirty_days_each(): void
    {
        $this->assertSame(30, $this->days('2026-01-15', '2026-02-15'));
        $this->assertSame(90, $this->days('2026-01-15', '2026-04-15'));
        $this->assertSame(360, $this->days('2026-01-15', '2027-01-15'));
    }

    public function test_february_is_counted_as_a_full_month(): void
    {
        // The point of the convention: 28 real days, 30 counted.
        $this->assertSame(30, $this->days('2026-02-01', '2026-03-01'));
    }

    public function test_end_of_month_adjustments(): void
    {
        // A 31st start is pulled back to the 30th: 31 Jan → 28 Feb is 28 days,
        // not 28 minus a phantom extra day.
        $this->assertSame(28, $this->days('2026-01-31', '2026-02-28'));

        // A 31st END collapses to the 30th only when the start was already on
        // the 30th — so month-end to month-end is two clean months…
        $this->assertSame(60, $this->days('2026-01-31', '2026-03-31'));
        $this->assertSame(60, $this->days('2026-01-30', '2026-03-31'));

        // …while a 29th start keeps the extra days it really has.
        $this->assertSame(62, $this->days('2026-01-29', '2026-03-31'));
    }

    public function test_backwards_and_same_day_are_zero(): void
    {
        $this->assertSame(0, $this->days('2026-03-10', '2026-03-10'));
        $this->assertSame(0, $this->days('2026-03-10', '2026-02-10'));
    }

    public function test_interest_is_truncated_bcmath_never_floats(): void
    {
        // 1000 € at 16% for 45 days = 1000 × 0.16 × 45/360 = 20.00
        $this->assertSame('20.00', DayCount::interestFor('1000.00', '16.00', 45));
        // 5000 € at 12% for 18 days = 30.00
        $this->assertSame('30.00', DayCount::interestFor('5000.00', '12.00', 18));
        // A third of a cent is dropped, never rounded up — the platform must
        // not pay a cent it did not owe.
        $this->assertSame('0.27', DayCount::interestFor('100.00', '10.00', 10));
        $this->assertSame('0.00', DayCount::interestFor('1000.00', '16.00', 0));
    }
}
