<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bulgarian ЕГН (national identification number) validator.
 *
 * 10 digits: YYMMDD + 3-digit serial + 1-digit mod-11 checksum.
 * Month carries a century offset:
 *   - 1900..1999 → month 1..12 (no offset)
 *   - 1800..1899 → month + 20
 *   - 2000..2099 → month + 40
 *
 * We validate format, encoded date, and checksum. We do NOT validate that
 * the date is in the past or that the person is ≥ 18 — that's a separate
 * business rule applied at the calling site.
 */
class ValidEgn implements ValidationRule
{
    private const WEIGHTS = [2, 4, 8, 5, 10, 9, 7, 3, 6];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Невалидно ЕГН.');
            return;
        }

        $digits = preg_replace('/\s+/', '', $value);

        if (! ctype_digit($digits) || strlen($digits) !== 10) {
            $fail('ЕГН трябва да е 10 цифри.');
            return;
        }

        $d = array_map('intval', str_split($digits));

        if (! self::isValidEncodedDate($d)) {
            $fail('ЕГН съдържа невалидна дата.');
            return;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $d[$i] * self::WEIGHTS[$i];
        }
        $check = $sum % 11;
        if ($check === 10) {
            $check = 0;
        }

        if ($check !== $d[9]) {
            $fail('Невалидно ЕГН (грешен контролен код).');
            return;
        }
    }

    private static function isValidEncodedDate(array $d): bool
    {
        $year  = $d[0] * 10 + $d[1];
        $month = $d[2] * 10 + $d[3];
        $day   = $d[4] * 10 + $d[5];

        // Decode century from month offset
        if ($month >= 1 && $month <= 12) {
            $fullYear = 1900 + $year;
        } elseif ($month >= 21 && $month <= 32) {
            $fullYear = 1800 + $year;
            $month -= 20;
        } elseif ($month >= 41 && $month <= 52) {
            $fullYear = 2000 + $year;
            $month -= 40;
        } else {
            return false;
        }

        return checkdate($month, $day, $fullYear);
    }
}
