<?php

namespace Tests\Unit;

use App\Rules\ValidEik;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidEikTest extends TestCase
{
    /**
     * Drive the rule through Laravel's ValidationRule contract and capture
     * any failure so the test can assert pass/fail without depending on the
     * full Validator factory.
     */
    private function check(string $eik): bool
    {
        $failed = false;
        $closure = function () use (&$failed) {
            $failed = true;
            return new class {
                public function translate(): self { return $this; }
            };
        };
        // The contract types `$fail` as Closure(string,?string=):
        // PotentiallyTranslatedString. Our test closure ignores the
        // translation pathway and just records the call.
        (new ValidEik)->validate('eik', $eik, $closure);
        return ! $failed;
    }

    public static function eikProvider(): array
    {
        return [
            // Real-world valid EIKs (mod-11 verified by hand)
            'Vama Asset (current operator)' => ['201035515', true],
            'leading-zero block boundary'   => ['000000000', true],

            // Format problems
            'empty'                  => ['', false],
            'too short'              => ['12345', false],
            'too long (10)'          => ['1234567890', false],
            'mixed alpha'            => ['12345678X', false],
            'whitespace alpha'       => ['1 2 3', false],

            // Wrong checksum
            'random invalid'         => ['123456789', false],
            'all 1s'                 => ['111111111', false],
        ];
    }

    #[DataProvider('eikProvider')]
    public function test_eik_validation(string $eik, bool $expected): void
    {
        $this->assertSame($expected, $this->check($eik), "EIK '{$eik}' expected " . ($expected ? 'valid' : 'invalid'));
    }

    public function test_branch_eik_13_digits_valid(): void
    {
        // 9-digit base 201035515 + 4-digit branch suffix with valid checksum.
        // We compute the branch checksum here so the test mirrors the rule's
        // expectation rather than hard-coding a magic number.
        $base = '201035515';
        $branchPrefix = '000'; // pick first three branch digits
        $full = $base . $branchPrefix; // 12 digits so far
        $d = array_map('intval', str_split($full));

        // Branch positions are 8..11 (last digit of base + 3 branch digits)
        $w1 = [2, 7, 3, 5];
        $sum = 0;
        for ($i = 0; $i < 4; $i++) {
            $sum += $d[8 + $i] * $w1[$i];
        }
        $check = $sum % 11;
        if ($check === 10) {
            $w2 = [4, 9, 5, 7];
            $sum2 = 0;
            for ($i = 0; $i < 4; $i++) {
                $sum2 += $d[8 + $i] * $w2[$i];
            }
            $check = $sum2 % 11;
            if ($check === 10) {
                $check = 0;
            }
        }

        $this->assertTrue($this->check($full . $check));
    }
}
