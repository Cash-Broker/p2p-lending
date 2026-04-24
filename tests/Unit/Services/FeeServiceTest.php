<?php

namespace Tests\Unit\Services;

use App\Models\PlatformSetting;
use App\Services\FeeQuote;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Smoke tests for F4 FeeService. Full coverage (edge cases, flag-flip
 * concurrency, percent-based strategies when v1.1 lands) goes in
 * Step 5/6.
 */
class FeeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_fee_is_disabled_by_default(): void
    {
        $service = new FeeService();

        $this->assertFalse($service->isWithdrawalFeeEnabled());
        $this->assertFalse($service->isEnabled(FeeService::CATEGORY_WITHDRAWAL));
    }

    public function test_getamount_returns_seeded_value_as_normalised_string(): void
    {
        $service = new FeeService();

        $this->assertSame('2.50', $service->getAmount(FeeService::CATEGORY_WITHDRAWAL));
    }

    public function test_quote_with_flag_off_returns_not_applies(): void
    {
        $service = new FeeService();

        $quote = $service->getQuote(FeeService::CATEGORY_WITHDRAWAL, '100.00');

        $this->assertInstanceOf(FeeQuote::class, $quote);
        $this->assertFalse($quote->applies);
        $this->assertSame('0.00', $quote->amount);
        $this->assertSame(FeeService::CATEGORY_WITHDRAWAL, $quote->category);
    }

    public function test_quote_with_flag_on_returns_applies_with_configured_amount(): void
    {
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $service = new FeeService();

        $quote = $service->getQuote(FeeService::CATEGORY_WITHDRAWAL, '100.00');

        $this->assertTrue($quote->applies);
        $this->assertSame('2.50', $quote->amount);
    }

    public function test_quote_with_zero_gross_returns_not_applies_even_when_enabled(): void
    {
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $service = new FeeService();

        $quote = $service->getQuote(FeeService::CATEGORY_WITHDRAWAL, '0.00');

        $this->assertFalse($quote->applies);
    }

    public function test_quote_with_zero_configured_amount_returns_not_applies(): void
    {
        PlatformSetting::set('fees_withdrawal_enabled', true);
        PlatformSetting::set('fees_withdrawal_amount', 0);
        $service = new FeeService();

        $quote = $service->getQuote(FeeService::CATEGORY_WITHDRAWAL, '100.00');

        $this->assertFalse($quote->applies);
        $this->assertSame('0.00', $quote->amount);
    }

    public function test_unknown_category_throws_invalid_argument(): void
    {
        $service = new FeeService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown fee category: origination');

        $service->getQuote('origination', '100.00');
    }

    public function test_feequote_netamount_subtracts_when_applies(): void
    {
        $quote = new FeeQuote(true, '2.50', FeeService::CATEGORY_WITHDRAWAL);

        $this->assertSame('97.50', $quote->netAmount('100.00'));
    }

    public function test_feequote_netamount_returns_gross_when_not_applies(): void
    {
        $quote = new FeeQuote(false, '0.00', FeeService::CATEGORY_WITHDRAWAL);

        $this->assertSame('100.00', $quote->netAmount('100.00'));
    }

    // ── Batch B — edge case coverage ──

    public function test_getamount_returns_zero_when_setting_key_is_missing(): void
    {
        // Simulate an operator accidentally deleting the seeded row — the
        // service must NOT throw. Returning '0.00' maps to applies=false
        // downstream, so the investor flow degrades to "no fee charged"
        // rather than blowing up at approve time.
        PlatformSetting::where('key', 'fees_withdrawal_amount')->delete();

        $service = new FeeService();

        $this->assertSame('0.00', $service->getAmount(FeeService::CATEGORY_WITHDRAWAL));
    }

    public function test_isenabled_returns_false_when_setting_key_is_missing(): void
    {
        PlatformSetting::where('key', 'fees_withdrawal_enabled')->delete();

        $service = new FeeService();

        $this->assertFalse($service->isWithdrawalFeeEnabled());
    }

    public function test_getamount_normalises_unpadded_stored_value(): void
    {
        // Raw-SQL admin override — stored "5" should surface as "5.00"
        // through the service so downstream bcmath stays stable.
        PlatformSetting::where('key', 'fees_withdrawal_amount')->update(['value' => '5']);

        $service = new FeeService();

        $this->assertSame('5.00', $service->getAmount(FeeService::CATEGORY_WITHDRAWAL));
    }

    public function test_quote_with_very_large_gross_preserves_precision(): void
    {
        PlatformSetting::set('fees_withdrawal_enabled', true);
        $service = new FeeService();

        $quote = $service->getQuote(FeeService::CATEGORY_WITHDRAWAL, '9999999.99');

        $this->assertTrue($quote->applies);
        $this->assertSame('2.50', $quote->amount);
        $this->assertSame('9999997.49', $quote->netAmount('9999999.99'));
    }
}
