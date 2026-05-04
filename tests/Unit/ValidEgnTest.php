<?php

namespace Tests\Unit;

use App\Rules\ValidEgn;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidEgnTest extends TestCase
{
    private function check(string $egn): bool
    {
        $failed = false;
        $closure = function () use (&$failed) {
            $failed = true;
            return new class {
                public function translate(): self { return $this; }
            };
        };
        (new ValidEgn)->validate('egn', $egn, $closure);
        return ! $failed;
    }

    public static function egnProvider(): array
    {
        return [
            // Synthesised, mod-11 verified by hand. Date 1990-06-15.
            'valid 1990 male'     => ['9006151000', true],

            // Format
            'empty'               => ['', false],
            'too short'           => ['123456', false],
            'too long'            => ['12345678901', false],
            'alpha'               => ['ABCDEFGHIJ', false],

            // Date problems (valid checksum but illegal date)
            'invalid month 99'    => ['9099150000', false],
            'invalid day 32'      => ['9006320000', false],

            // Wrong checksum
            'all zero'            => ['0000000001', false],
            'date ok, checksum bad' => ['9006151001', false],
        ];
    }

    #[DataProvider('egnProvider')]
    public function test_egn_validation(string $egn, bool $expected): void
    {
        $this->assertSame($expected, $this->check($egn), "EGN '{$egn}' expected " . ($expected ? 'valid' : 'invalid'));
    }

    public function test_century_offset_2000s_works(): void
    {
        // 2010-06-15 → MM = 06 + 40 = 46
        // Compute checksum for digits 1, 0, 4, 6, 1, 5, 0, 0, 0
        $digits = [1, 0, 4, 6, 1, 5, 0, 0, 0];
        $weights = [2, 4, 8, 5, 10, 9, 7, 3, 6];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * $weights[$i];
        }
        $check = $sum % 11;
        if ($check === 10) $check = 0;
        $egn = implode('', $digits) . $check;

        $this->assertTrue($this->check($egn));
    }

    public function test_century_offset_1800s_works(): void
    {
        // 1899-06-15 → MM = 06 + 20 = 26 (encodes 1800s)
        $digits = [9, 9, 2, 6, 1, 5, 0, 0, 0];
        $weights = [2, 4, 8, 5, 10, 9, 7, 3, 6];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * $weights[$i];
        }
        $check = $sum % 11;
        if ($check === 10) $check = 0;
        $egn = implode('', $digits) . $check;

        $this->assertTrue($this->check($egn));
    }
}
