<?php

namespace App\Rules;

use Closure;
use IBAN\Core\IBAN;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates an IBAN per ISO 13616 (format, country, length, mod-97 checksum)
 * AND restricts the country to the SEPA zone.
 *
 * The platform settles in EUR over SEPA — withdrawals to non-SEPA countries
 * (US, CA, AU, etc.) are 99%+ fraud or user error and would be rejected at
 * the rails layer anyway. Failing fast at validation saves operational
 * effort and forensic noise.
 *
 * SEPA scheme participants (as of 2026-04):
 *   EU 27:  AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE
 *   EEA 3:  IS LI NO
 *   Other:  CH GB MC SM AD VA
 */
class ValidIban implements ValidationRule
{
    /**
     * @var list<string>
     */
    private const SEPA_COUNTRIES = [
        // EU 27
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        // EEA non-EU
        'IS', 'LI', 'NO',
        // Other SEPA participants
        'CH', 'GB', 'MC', 'SM', 'AD', 'VA',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Невалиден IBAN.');
            return;
        }

        // Normalise — strip spaces and uppercase, mirroring jschaedl's internal handling.
        $normalised = strtoupper(preg_replace('/\s+/', '', $value));

        try {
            $iban = new IBAN($normalised);
        } catch (\Throwable) {
            $fail('Невалиден IBAN.');
            return;
        }

        if (! $iban->validate()) {
            $fail('Невалиден IBAN (грешен формат, дължина или checksum).');
            return;
        }

        $country = substr($normalised, 0, 2);
        if (! in_array($country, self::SEPA_COUNTRIES, true)) {
            $fail("IBAN от държава {$country} не се поддържа. Платформата работи само със SEPA държави.");
            return;
        }
    }
}
