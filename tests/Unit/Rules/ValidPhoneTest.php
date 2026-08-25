<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidPhone;
use PHPUnit\Framework\TestCase;

/**
 * The rule guards a MANDATORY field (registration + profile update, both
 * account types, 2026-08-25) — so the acceptance side matters as much as the
 * rejections: over-tightening it locks real investors out at the door.
 */
class ValidPhoneTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        $failed = false;
        (new ValidPhone)->validate('phone', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_accepts_common_real_world_formats(): void
    {
        $valid = [
            '+359888123456',
            '+359 88 123 4567',
            '0888123456',
            '0888-123-456',
            '02/981-23-45',
            '(02) 981 23 45',
            '0044 7911 123456',
            '+1 (415) 555-2671',
            '(+359) 888 123 456', // paren-plus order — common BG spelling
            '123456',            // 6 digits — lower bound
            '+123456789012345',  // 15 digits — E.164 upper bound
        ];

        foreach ($valid as $phone) {
            $this->assertFalse($this->fails($phone), "Expected '{$phone}' to pass");
        }
    }

    public function test_rejects_text_and_malformed_input(): void
    {
        $invalid = [
            'нямам телефон',
            'call me maybe',
            '++359888123456',    // + only leading, exactly once
            '359-888+123',       // + not at the start
            '',
            '   ',
            'тел: 0888123456',
        ];

        foreach ($invalid as $phone) {
            $this->assertTrue($this->fails($phone), "Expected '{$phone}' to fail");
        }
    }

    public function test_rejects_digit_counts_outside_6_to_15(): void
    {
        $this->assertTrue($this->fails('12345'), '5 digits must fail');
        $this->assertTrue($this->fails('+1234567890123456'), '16 digits must fail');
        // Separators must not inflate the digit count past the limit.
        $this->assertTrue($this->fails('1-2-3-4-5'), '5 digits with separators must fail');
    }

    public function test_rejects_non_string_values(): void
    {
        $this->assertTrue($this->fails(null));
        $this->assertTrue($this->fails(888123456));
        $this->assertTrue($this->fails(['0888123456']));
    }
}
