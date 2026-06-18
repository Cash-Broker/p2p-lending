<?php

namespace App\Enums;

/**
 * The three investor payout structures a loan can be offered under.
 *
 * Each loan auto-seeds one LoanOffer per case (see Loan::booted()). The
 * investor picks one at invest time and the choice is snapshotted onto the
 * Investment so later offer edits never rewrite history.
 *
 * The mechanics drive {@see \App\Services\OfferProjectionService}, which
 * computes the per-investment payout schedule + profit summary shown to the
 * client and persisted as investment_schedules at activation.
 */
enum PayoutType: string
{
    /** Monthly annuity — interest + principal every month. Today's AmortizationService behavior. */
    case Amortizing = 'amortizing';

    /** Interest only each month; full principal returned in the final month. */
    case InterestOnly = 'interest_only';

    /** Nothing until maturity; principal + monthly-compounded interest paid in one lump at the end. */
    case Capitalized = 'capitalized';

    /** Bulgarian label shown to admin + investor. */
    public function label(): string
    {
        return match ($this) {
            self::Amortizing => 'Анюитет',
            self::InterestOnly => 'Само лихва',
            self::Capitalized => 'Капитализация',
        };
    }

    /** One-line Bulgarian description of how this offer pays out. */
    public function description(): string
    {
        return match ($this) {
            self::Amortizing => 'Месечно теглене на вноска — лихва и главница всеки месец.',
            self::InterestOnly => 'Месечно теглене само на лихвата; цялата главница се връща в края.',
            self::Capitalized => 'Без теглене до края; главница и капитализирана лихва се изплащат наведнъж на падежа.',
        };
    }

    /** Default annual % the loan is seeded with (the boss freely overrides per loan). */
    public function defaultRate(): string
    {
        return match ($this) {
            self::Amortizing => '12.00',
            self::InterestOnly => '16.00',
            self::Capitalized => '20.00',
        };
    }

    /** Display/seed order (amortizing → interest-only → capitalized). */
    public function position(): int
    {
        return match ($this) {
            self::Amortizing => 1,
            self::InterestOnly => 2,
            self::Capitalized => 3,
        };
    }

    /**
     * The default offer set every loan is seeded with, in display order.
     *
     * @return array<int, self>
     */
    public static function defaults(): array
    {
        return [self::Amortizing, self::InterestOnly, self::Capitalized];
    }

    /** Map of value => Bulgarian label, for Filament/Select options. */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
