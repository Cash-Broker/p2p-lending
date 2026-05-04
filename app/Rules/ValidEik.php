<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bulgarian EIK (BULSTAT) validator.
 *
 * EIK is 9 digits for legal entities and natural-person traders, or 13 digits
 * for branches/divisions of registered entities. Both variants carry a
 * mod-11 checksum that protects against typos and a class of fake numbers.
 *
 * Algorithm reference: BULSTAT regulation, art. 8 (Закон за регистър БУЛСТАТ).
 *
 * The checksum has a documented edge case: when the first-pass mod-11 == 10
 * a second pass with shifted weights is computed, and if THAT also yields 10
 * the check digit is 0. This is implemented faithfully below.
 */
class ValidEik implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Невалиден ЕИК.');
            return;
        }

        $digits = preg_replace('/\s+/', '', $value);

        if (! ctype_digit($digits)) {
            $fail('ЕИК трябва да съдържа само цифри.');
            return;
        }

        $len = strlen($digits);
        if ($len !== 9 && $len !== 13) {
            $fail('ЕИК трябва да е 9 или 13 цифри.');
            return;
        }

        if (! self::checkBase9(substr($digits, 0, 9))) {
            $fail('Невалиден ЕИК (грешен контролен код).');
            return;
        }

        // Branch suffix (positions 9..12) has its own checksum.
        if ($len === 13 && ! self::checkBranch4(substr($digits, 9, 4), $digits)) {
            $fail('Невалиден ЕИК на клон (грешен контролен код).');
            return;
        }
    }

    private static function checkBase9(string $base): bool
    {
        $d = array_map('intval', str_split($base));

        $w1 = [1, 2, 3, 4, 5, 6, 7, 8];
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += $d[$i] * $w1[$i];
        }
        $check = $sum % 11;

        if ($check === 10) {
            $w2 = [3, 4, 5, 6, 7, 8, 9, 10];
            $sum2 = 0;
            for ($i = 0; $i < 8; $i++) {
                $sum2 += $d[$i] * $w2[$i];
            }
            $check = $sum2 % 11;
            if ($check === 10) {
                $check = 0;
            }
        }

        return $check === $d[8];
    }

    private static function checkBranch4(string $branch, string $full): bool
    {
        // The branch checksum is computed over positions 8..11 (1-indexed 9..12),
        // which is the last digit of the base + first 3 of the branch suffix.
        // Reference: BULSTAT regulation, art. 8, ал. 4.
        $d = array_map('intval', str_split($full));

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

        return $check === $d[12];
    }
}
