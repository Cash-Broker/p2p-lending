<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The KYC endpoint validates max:10240 KB per file x 3 files per POST.
 * That contract is only honoured if PHP's own limits are big enough —
 * distro defaults (2M/8M) silently kill every real phone photo BEFORE
 * validation runs. These limits ship in the repo (public/.user.ini for
 * FPM/FastCGI, an <IfModule php_module> block in .htaccess for mod_php);
 * this test fails if either is removed or tightened below the contract.
 */
class UploadLimitsConfigTest extends TestCase
{
    private const VALIDATION_LIMIT_KB = 10240; // mirrors max:10240 in submitKyc

    public function test_user_ini_declares_upload_limits_covering_the_kyc_contract(): void
    {
        $path = public_path('.user.ini');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertGreaterThan(
            self::VALIDATION_LIMIT_KB,
            $this->parseIniSizeToKb($content, '/upload_max_filesize\s*=\s*(\S+)/'),
            'upload_max_filesize in public/.user.ini must exceed the 10240 KB KYC validation limit.'
        );
        $this->assertGreaterThan(
            3 * self::VALIDATION_LIMIT_KB,
            $this->parseIniSizeToKb($content, '/post_max_size\s*=\s*(\S+)/'),
            'post_max_size in public/.user.ini must fit three max-size KYC files in one POST.'
        );
    }

    public function test_htaccess_declares_upload_limits_for_mod_php(): void
    {
        $content = file_get_contents(public_path('.htaccess'));

        $this->assertGreaterThan(
            self::VALIDATION_LIMIT_KB,
            $this->parseIniSizeToKb($content, '/php_value\s+upload_max_filesize\s+(\S+)/'),
            'php_value upload_max_filesize in public/.htaccess must exceed the 10240 KB KYC validation limit.'
        );
        $this->assertGreaterThan(
            3 * self::VALIDATION_LIMIT_KB,
            $this->parseIniSizeToKb($content, '/php_value\s+post_max_size\s+(\S+)/'),
            'php_value post_max_size in public/.htaccess must fit three max-size KYC files in one POST.'
        );
    }

    private function parseIniSizeToKb(string $content, string $pattern): int
    {
        $this->assertSame(1, preg_match($pattern, $content, $m), "Missing directive: {$pattern}");

        $value = trim($m[1]);
        $unit = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G' => $number * 1024 * 1024,
            'M' => $number * 1024,
            'K' => $number,
            default => intdiv($number, 1024), // bare bytes
        };
    }
}
