<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Day-count conventions for interest that stops mid-period.
 *
 * Only 30/360 (US/NASD) is implemented, and deliberately so: the whole
 * amortization plan of the platform is built on it, so an early closure priced
 * on any other calendar would drift a few cents away from the schedule the
 * investor is looking at — and those cents are what support has to explain.
 * Confirmed with Reni 2026-08-18 after she was shown the alternative
 * (actual/365, ~1.4% less per day, but 28-day February counted as 30).
 */
final class DayCount
{
    public const BASIS_DAYS_PER_YEAR = '360';

    /**
     * Days between two dates on the 30/360 (US/NASD) convention.
     *
     * Every month counts as 30 days and the year as 360. The two end-of-month
     * adjustments are the standard ones: a 31st start becomes the 30th, and a
     * 31st end becomes the 30th only when the start was already on the 30th
     * (or 31st) — otherwise January 31 → March 31 would lose a day.
     *
     * Never negative: a `to` before `from` yields 0 rather than negative
     * interest.
     */
    public static function thirty360(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $d1 = min($from->day, 30);
        $d2 = $to->day;

        if ($d2 === 31 && $d1 === 30) {
            $d2 = 30;
        }

        return 360 * ($to->year - $from->year)
            + 30 * ($to->month - $from->month)
            + ($d2 - $d1);
    }

    /**
     * Simple interest for a stub period: principal × annual% × days / 360.
     * Truncated to 2 decimals, bcmath only — never floats.
     */
    public static function interestFor(string $principal, string $annualRatePct, int $days): string
    {
        if ($days <= 0 || bccomp($principal, '0', 2) <= 0) {
            return '0.00';
        }

        $rate = bcdiv($annualRatePct, '100', 10);
        $yearFraction = bcdiv((string) $days, self::BASIS_DAYS_PER_YEAR, 10);

        return bcadd(bcmul(bcmul($principal, $rate, 10), $yearFraction, 10), '0', 2);
    }
}
