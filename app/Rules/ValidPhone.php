<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Permissive international phone validator.
 *
 * The number is a CONTACT channel, not an identity credential — we verify
 * plausibility (digits with common separators, optional leading +), never
 * carrier reality. Deliberately loose: investors may be foreign, and an
 * over-strict rule on a MANDATORY field locks real people out at the door.
 * Bounds: 6 digits (short national numbers) up to 15 (E.164 maximum).
 */
class ValidPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Невалиден телефонен номер.');

            return;
        }

        $phone = trim($value);

        // Optional leading + and opening parenthesis IN EITHER ORDER —
        // «+359…», «(02)…», «(+359)…» are all real-world spellings — then
        // digits with the separators people actually type: spaces, dashes,
        // dots, slashes, parentheses. Anything beyond that plausibility check
        // is the digit count below — this is a contact field, not an
        // identity one.
        if (! preg_match('/^\(?\+?\(?[0-9][0-9 ().\/-]*$/', $phone)) {
            $fail('Телефонът може да съдържа само цифри, интервали, скоби, тире и водещ „+“.');

            return;
        }

        $digits = strlen(preg_replace('/\D/', '', $phone));

        if ($digits < 6 || $digits > 15) {
            $fail('Телефонът трябва да съдържа между 6 и 15 цифри.');
        }
    }
}
