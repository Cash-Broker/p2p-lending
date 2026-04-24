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
}
