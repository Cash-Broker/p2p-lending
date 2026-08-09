<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Bulgarian number-to-words for contract text («сума словом», «процент словом»).
 *
 * Grammar rules encoded here (БАН orthography for composite numerals):
 *  - «и» stands before the LAST component inside each 3-digit group
 *    (сто двадесет и три; сто и пет), and before the final group of the
 *    whole number when that group is a single word (хиляда и двеста,
 *    две хиляди и пет, два милиона и петстотин хиляди).
 *  - Gender agreement: masculine един/два (проценти, центове, месеци),
 *    feminine една/две (хиляди, стотни), neuter едно/две (евро).
 *  - 1000 alone is «хиляда», never «една хиляда»; thousands count in
 *    feminine (двадесет и една хиляди), millions in masculine
 *    (един милион / два милиона).
 *
 * Range: 0 .. 999 999 999 integer part — far above the platform's
 * 999 999.99 € investment ceiling. Input is bcmath decimal strings, in
 * line with the "never float for money" invariant.
 */
final class BulgarianNumberWords
{
    public const GENDER_MASCULINE = 'm';

    public const GENDER_FEMININE = 'f';

    public const GENDER_NEUTER = 'n';

    private const ONES = [
        'm' => ['', 'един', 'два', 'три', 'четири', 'пет', 'шест', 'седем', 'осем', 'девет'],
        'f' => ['', 'една', 'две', 'три', 'четири', 'пет', 'шест', 'седем', 'осем', 'девет'],
        'n' => ['', 'едно', 'две', 'три', 'четири', 'пет', 'шест', 'седем', 'осем', 'девет'],
    ];

    private const TEENS = [
        10 => 'десет', 11 => 'единадесет', 12 => 'дванадесет', 13 => 'тринадесет',
        14 => 'четиринадесет', 15 => 'петнадесет', 16 => 'шестнадесет',
        17 => 'седемнадесет', 18 => 'осемнадесет', 19 => 'деветнадесет',
    ];

    private const TENS = [
        2 => 'двадесет', 3 => 'тридесет', 4 => 'четиридесет', 5 => 'петдесет',
        6 => 'шестдесет', 7 => 'седемдесет', 8 => 'осемдесет', 9 => 'деветдесет',
    ];

    private const HUNDREDS = [
        1 => 'сто', 2 => 'двеста', 3 => 'триста', 4 => 'четиристотин', 5 => 'петстотин',
        6 => 'шестстотин', 7 => 'седемстотин', 8 => 'осемстотин', 9 => 'деветстотин',
    ];

    /**
     * Cardinal words for a non-negative integer given as a digit string.
     */
    public static function cardinal(string $integer, string $gender = self::GENDER_MASCULINE): string
    {
        if (! preg_match('/^\d+$/', $integer)) {
            throw new InvalidArgumentException("Not a non-negative integer string: {$integer}");
        }

        $integer = ltrim($integer, '0');
        if ($integer === '') {
            return 'нула';
        }
        if (strlen($integer) > 9) {
            throw new InvalidArgumentException('Numbers above 999 999 999 are not supported.');
        }

        $n = (int) $integer;
        $millions = intdiv($n, 1_000_000);
        $thousands = intdiv($n % 1_000_000, 1000);
        $units = $n % 1000;

        // Each group becomes one phrase (with its internal «и» already
        // placed); the scale word is part of the phrase.
        $phrases = [];

        if ($millions > 0) {
            $phrases[] = [
                'text' => $millions === 1
                    ? 'един милион'
                    : self::group($millions, self::GENDER_MASCULINE).' милиона',
                'single_chunk' => count(self::groupChunks($millions, self::GENDER_MASCULINE)) === 1,
            ];
        }

        if ($thousands > 0) {
            $phrases[] = [
                'text' => $thousands === 1
                    ? 'хиляда'
                    : self::group($thousands, self::GENDER_FEMININE).' хиляди',
                'single_chunk' => count(self::groupChunks($thousands, self::GENDER_FEMININE)) === 1,
            ];
        }

        if ($units > 0) {
            $phrases[] = [
                'text' => self::group($units, $gender),
                'single_chunk' => count(self::groupChunks($units, $gender)) === 1,
            ];
        }

        // Join groups. A final single-word group is preceded by «и»
        // (хиляда И двеста, две хиляди И пет); multi-word groups already
        // carry their internal «и» (две хиляди петстотин тридесет и четири).
        $result = $phrases[0]['text'];
        for ($i = 1; $i < count($phrases); $i++) {
            $isLast = $i === count($phrases) - 1;
            $glue = ($isLast && $phrases[$i]['single_chunk']) ? ' и ' : ' ';
            $result .= $glue.$phrases[$i]['text'];
        }

        return $result;
    }

    /**
     * Amount words for a scale-2 euro amount: «хиляда и петстотин евро и
     * петдесет цента». Euro counts in neuter (едно евро, две евро), cents
     * in masculine with бройна форма (един цент, два цента).
     */
    public static function euroAmount(string $amount): string
    {
        $normalized = Money::normalize($amount);
        [$whole, $cents] = explode('.', $normalized);

        $words = self::cardinal($whole, self::GENDER_NEUTER).' евро';

        if ($cents !== '00') {
            $centWord = $cents === '01' ? 'цент' : 'цента';
            $words .= ' и '.self::cardinal(ltrim($cents, '0'), self::GENDER_MASCULINE).' '.$centWord;
        }

        return $words;
    }

    /**
     * Percent words WITHOUT the «процента» noun (the contract template
     * carries it): «дванадесет», «дванадесет цяло и петдесет стотни».
     */
    public static function percent(string $rate): string
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $rate)) {
            throw new InvalidArgumentException("Not a valid rate string: {$rate}");
        }

        [$whole, $fraction] = array_pad(explode('.', $rate), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        $words = self::cardinal($whole, self::GENDER_MASCULINE);

        if ($fraction !== '00') {
            $noun = $fraction === '01' ? 'стотна' : 'стотни';
            $words .= ' цяло и '.self::cardinal(ltrim($fraction, '0'), self::GENDER_FEMININE).' '.$noun;
        }

        return $words;
    }

    /**
     * One 3-digit group as words with its internal «и» placed before the
     * last chunk (сто двадесет и три; сто и пет; двадесет и една).
     */
    private static function group(int $n, string $gender): string
    {
        $chunks = self::groupChunks($n, $gender);

        if (count($chunks) === 1) {
            return $chunks[0];
        }

        $last = array_pop($chunks);

        return implode(' ', $chunks).' и '.$last;
    }

    /**
     * Atomic word chunks of a 3-digit group. Teens are atomic
     * (единадесет), so 111 → [сто, единадесет] → «сто и единадесет».
     *
     * @return list<string>
     */
    private static function groupChunks(int $n, string $gender): array
    {
        $chunks = [];

        $h = intdiv($n, 100);
        $rem = $n % 100;

        if ($h > 0) {
            $chunks[] = self::HUNDREDS[$h];
        }

        if ($rem >= 10 && $rem <= 19) {
            $chunks[] = self::TEENS[$rem];
        } elseif ($rem >= 20) {
            $chunks[] = self::TENS[intdiv($rem, 10)];
            if ($rem % 10 > 0) {
                $chunks[] = self::ONES[$gender][$rem % 10];
            }
        } elseif ($rem > 0) {
            $chunks[] = self::ONES[$gender][$rem];
        }

        return $chunks;
    }
}
