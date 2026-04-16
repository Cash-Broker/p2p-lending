<?php

namespace Tests\Unit;

use App\Rules\ValidIban;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidIbanTest extends TestCase
{
    private function validate(string $iban): array
    {
        // Add 'required' so ValidIban runs even on empty strings.
        $v = Validator::make(['iban' => $iban], ['iban' => ['required', new ValidIban]]);
        return $v->errors()->get('iban');
    }

    private function isValid(string $iban): bool
    {
        return empty($this->validate($iban));
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function ibans(): iterable
    {
        // [iban, isValid, reason]
        return [
            // Valid SEPA IBANs (real-world test vectors from ECBS)
            'BG valid'           => ['BG80BNBG96611020345678', true, 'BG SEPA'],
            'DE valid'           => ['DE89370400440532013000', true, 'DE SEPA'],
            'FR valid'           => ['FR1420041010050500013M02606', true, 'FR SEPA'],
            'NL valid'           => ['NL91ABNA0417164300', true, 'NL SEPA'],
            'GB valid'           => ['GB82WEST12345698765432', true, 'GB SEPA'],
            'CH valid'           => ['CH9300762011623852957', true, 'CH SEPA'],
            'IT valid'           => ['IT60X0542811101000000123456', true, 'IT SEPA'],
            'ES valid'           => ['ES9121000418450200051332', true, 'ES SEPA'],
            'NO valid'           => ['NO9386011117947', true, 'NO EEA SEPA'],
            'AD valid'           => ['AD1200012030200359100100', true, 'AD other SEPA'],
            'with spaces'        => ['BG80 BNBG 9661 1020 3456 78', true, 'spaces stripped'],
            'lowercase'          => ['bg80bnbg96611020345678', true, 'normalised to upper'],

            // Invalid checksum (mod-97 fail)
            'BG bad checksum'    => ['BG00BNBG96611020345678', false, 'checksum 00 invalid'],
            'DE digits swapped'  => ['DE89370400440532013100', false, 'wrong checksum after swap'],

            // Wrong length per country
            'BG too short'       => ['BG80BNBG966110203', false, 'BG must be 22 chars'],
            'DE too long'        => ['DE893704004405320130001234', false, 'DE must be 22 chars'],

            // Non-SEPA countries — even if checksum/format valid, must reject
            'US (non-SEPA)'      => ['US64SVBKUS6S3300958879', false, 'non-SEPA country'],
            'AE (non-SEPA)'      => ['AE070331234567890123456', false, 'non-SEPA country'],
            'SA (non-SEPA)'      => ['SA0380000000608010167519', false, 'non-SEPA country'],
            'TR (non-SEPA)'      => ['TR330006100519786457841326', false, 'non-SEPA country'],

            // Garbage input
            'empty'              => ['', false, 'empty'],
            'too short'          => ['BG80', false, 'min length'],
            'all letters'        => ['BGBGBGBGBGBGBGBGBGBGBG', false, 'no digits in checksum positions'],
            'special chars'      => ['BG80<script>alert(1)</script>', false, 'invalid format'],
        ];
    }

    #[DataProvider('ibans')]
    public function test_iban_validation(string $iban, bool $shouldPass, string $reason): void
    {
        $errors = $this->validate($iban);

        if ($shouldPass) {
            $this->assertEmpty($errors, "Expected '{$iban}' to pass ({$reason}), got: " . implode(', ', $errors));
        } else {
            $this->assertNotEmpty($errors, "Expected '{$iban}' to fail ({$reason}) but it passed");
        }
    }

    public function test_empty_string_fails_when_required(): void
    {
        $this->assertFalse($this->isValid(''));
    }
}
