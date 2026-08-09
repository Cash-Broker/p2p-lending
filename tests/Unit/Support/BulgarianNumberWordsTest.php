<?php

namespace Tests\Unit\Support;

use App\Support\BulgarianNumberWords;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The contract's «сума словом» / «процент словом» generator. Reference
 * spellings follow БАН orthography for composite numerals — «и» before
 * the last component of each group, gender agreement for 1/2, feminine
 * thousands, masculine millions.
 */
class BulgarianNumberWordsTest extends TestCase
{
    public static function cardinalProvider(): array
    {
        return [
            ['0', 'm', 'нула'],
            ['1', 'm', 'един'],
            ['1', 'f', 'една'],
            ['1', 'n', 'едно'],
            ['2', 'm', 'два'],
            ['2', 'f', 'две'],
            ['2', 'n', 'две'],
            ['7', 'm', 'седем'],
            ['10', 'm', 'десет'],
            ['11', 'm', 'единадесет'],
            ['12', 'm', 'дванадесет'],
            ['16', 'm', 'шестнадесет'],
            ['19', 'm', 'деветнадесет'],
            ['20', 'm', 'двадесет'],
            ['21', 'm', 'двадесет и един'],
            ['21', 'n', 'двадесет и едно'],
            ['42', 'm', 'четиридесет и два'],
            ['100', 'm', 'сто'],
            ['105', 'm', 'сто и пет'],
            ['110', 'm', 'сто и десет'],
            ['111', 'm', 'сто и единадесет'],
            ['123', 'm', 'сто двадесет и три'],
            ['199', 'm', 'сто деветдесет и девет'],
            ['200', 'm', 'двеста'],
            ['600', 'm', 'шестстотин'],
            ['1000', 'm', 'хиляда'],
            ['1005', 'm', 'хиляда и пет'],
            ['1200', 'm', 'хиляда и двеста'],
            ['1234', 'm', 'хиляда двеста тридесет и четири'],
            ['2000', 'm', 'две хиляди'],
            ['2012', 'm', 'две хиляди и дванадесет'],
            ['2534', 'm', 'две хиляди петстотин тридесет и четири'],
            ['21000', 'm', 'двадесет и една хиляди'],
            ['100000', 'm', 'сто хиляди'],
            ['121121', 'n', 'сто двадесет и една хиляди сто двадесет и едно'],
            ['999999', 'n', 'деветстотин деветдесет и девет хиляди деветстотин деветдесет и девет'],
            ['1000000', 'm', 'един милион'],
            ['2500000', 'm', 'два милиона и петстотин хиляди'],
            ['1000005', 'm', 'един милион и пет'],
            // Leading zeros are tolerated (cents arrive as '05').
            ['05', 'm', 'пет'],
        ];
    }

    #[DataProvider('cardinalProvider')]
    public function test_cardinal(string $number, string $gender, string $expected): void
    {
        $this->assertSame($expected, BulgarianNumberWords::cardinal($number, $gender));
    }

    public static function euroAmountProvider(): array
    {
        return [
            ['50.00', 'петдесет евро'],
            ['50', 'петдесет евро'],
            ['1.00', 'едно евро'],
            ['2.00', 'две евро'],
            ['0.99', 'нула евро и деветдесет и девет цента'],
            ['2.01', 'две евро и един цент'],
            ['121.21', 'сто двадесет и едно евро и двадесет и един цента'],
            ['1500.50', 'хиляда и петстотин евро и петдесет цента'],
            ['2534.05', 'две хиляди петстотин тридесет и четири евро и пет цента'],
            ['999999.99', 'деветстотин деветдесет и девет хиляди деветстотин деветдесет и девет евро и деветдесет и девет цента'],
        ];
    }

    #[DataProvider('euroAmountProvider')]
    public function test_euro_amount(string $amount, string $expected): void
    {
        $this->assertSame($expected, BulgarianNumberWords::euroAmount($amount));
    }

    public static function percentProvider(): array
    {
        return [
            ['12.00', 'дванадесет'],
            ['16.00', 'шестнадесет'],
            ['20.00', 'двадесет'],
            ['12', 'дванадесет'],
            ['12.50', 'дванадесет цяло и петдесет стотни'],
            ['8.05', 'осем цяло и пет стотни'],
            ['10.01', 'десет цяло и една стотна'],
            ['0.50', 'нула цяло и петдесет стотни'],
            ['12.5', 'дванадесет цяло и петдесет стотни'],
        ];
    }

    #[DataProvider('percentProvider')]
    public function test_percent(string $rate, string $expected): void
    {
        $this->assertSame($expected, BulgarianNumberWords::percent($rate));
    }

    public function test_cardinal_rejects_non_integer_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BulgarianNumberWords::cardinal('12.5');
    }

    public function test_cardinal_rejects_numbers_above_supported_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BulgarianNumberWords::cardinal('1000000000');
    }

    public function test_percent_rejects_malformed_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BulgarianNumberWords::percent('12,50');
    }
}
