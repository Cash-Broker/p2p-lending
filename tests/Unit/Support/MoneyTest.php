<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The bcmath-safe money ingress boundary — the fix for the audit's
 * "float-cast on every admin money input" finding. Guards locale-comma
 * mis-parsing, scientific notation, junk, and DECIMAL(12,2) overflow.
 */
class MoneyTest extends TestCase
{
    public function test_canonicalises_valid_amounts_to_scale_two(): void
    {
        $this->assertSame('1000.50', Money::normalize('1000.5'));
        $this->assertSame('1000.00', Money::normalize('1000'));
        $this->assertSame('0.07', Money::normalize('0.07'));
        $this->assertSame('9999999999.99', Money::normalize('9999999999.99')); // DECIMAL(12,2) max
    }

    public function test_rejects_locale_comma_input(): void
    {
        // The exact bug: number_format((float) "1,50") silently became "1.00".
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('1,50');
    }

    public function test_rejects_scientific_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('1e3');
    }

    public function test_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('1.234');
    }

    public function test_rejects_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('-5.00');
    }

    public function test_rejects_blank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('   ');
    }

    public function test_rejects_overflow_above_decimal_ceiling(): void
    {
        // 11 integer digits — overflows DECIMAL(12,2).
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('99999999999.99');
    }

    public function test_normalize_positive_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalizePositive('0.00');
    }

    public function test_normalize_positive_accepts_positive(): void
    {
        $this->assertSame('10.00', Money::normalizePositive('10'));
    }
}
