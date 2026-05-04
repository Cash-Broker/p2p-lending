<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bulgarian VAT number (ДДС № по ЗДДС) validator.
 *
 * Format: BG + 9 or 13 digits. The numeric body is the entity's EIK
 * (or, for sole traders, the 10-digit ЕГН — we permit that too with the
 * appropriate length). Full validity check delegates to ValidEik for the
 * 9/13-digit case; the 10-digit ЕГН-based variant is checked via ValidEgn.
 *
 * We do NOT call VIES — that requires a network round-trip and is rate-
 * limited. Format + checksum already eliminates the bulk of bad input;
 * VIES verification can be added later as a backend job.
 */
class ValidVat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Невалиден ДДС номер.');
            return;
        }

        $normalised = strtoupper(preg_replace('/\s+/', '', $value));

        if (! str_starts_with($normalised, 'BG')) {
            $fail('Български ДДС номер започва с "BG".');
            return;
        }

        $body = substr($normalised, 2);

        if (! ctype_digit($body)) {
            $fail('ДДС номерът трябва да съдържа само цифри след "BG".');
            return;
        }

        $len = strlen($body);

        if ($len === 9 || $len === 13) {
            // EIK-based VAT — defer checksum to ValidEik
            (new ValidEik)->validate($attribute, $body, $fail);
            return;
        }

        if ($len === 10) {
            // Sole-trader VAT — body is the trader's ЕГН
            (new ValidEgn)->validate($attribute, $body, $fail);
            return;
        }

        $fail('ДДС номерът има невалидна дължина (BG + 9, 10 или 13 цифри).');
    }
}
