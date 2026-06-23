<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money input normalizer — the bcmath-safe boundary for user-entered amounts.
 *
 * The platform stores money as DECIMAL(12,2) and does ALL arithmetic with
 * bcmath strings (never floats). The one place that guarantee historically
 * leaked was ingress: `number_format((float) $input, 2, '.', '')` round-trips
 * the value through an IEEE-754 double AND silently mis-parses locale input
 * (`number_format((float) "1,50")` → "1.00" — the stotinki vanish).
 *
 * {@see normalize} replaces that: it validates the raw input is a well-formed
 * plain decimal that fits DECIMAL(12,2), rejecting commas, exponents, blanks
 * and overflow, then canonicalises to a scale-2 string WITHOUT any float.
 */
class Money
{
    /** DECIMAL(12,2): 10 integer digits + 2 fractional → max 9,999,999,999.99. */
    public const MAX = '9999999999.99';

    public const MAX_INTEGER_DIGITS = 10;

    /**
     * Validate + canonicalise a user-entered amount to a bcmath-safe scale-2
     * string. Throws on anything that is not a plain non-negative decimal
     * within the DECIMAL(12,2) range.
     */
    public static function normalize(mixed $value): string
    {
        $raw = trim((string) $value);

        // Plain decimal only: optional digits, optional dot + 1-2 decimals.
        // Rejects commas (locale), exponents, signs, whitespace, junk.
        if (! preg_match('/^\d{1,' . self::MAX_INTEGER_DIGITS . '}(\.\d{1,2})?$/', $raw)) {
            throw new InvalidArgumentException(
                "Invalid money amount: '{$value}'. Use digits and a dot only "
                . '(e.g. 1234.56), up to ' . self::MAX . '.'
            );
        }

        $normalized = bcadd($raw, '0', 2); // canonical scale 2, no float

        if (bccomp($normalized, self::MAX, 2) > 0) {
            throw new InvalidArgumentException(
                "Money amount {$normalized} exceeds the maximum allowed (" . self::MAX . ').'
            );
        }

        return $normalized;
    }

    /**
     * Same as {@see normalize} but additionally requires a strictly positive
     * amount — for deposits/withdrawals/investments where zero is meaningless.
     */
    public static function normalizePositive(mixed $value): string
    {
        $normalized = self::normalize($value);

        if (bccomp($normalized, '0', 2) <= 0) {
            throw new InvalidArgumentException('Money amount must be greater than zero.');
        }

        return $normalized;
    }
}
