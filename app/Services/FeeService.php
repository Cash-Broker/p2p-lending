<?php

namespace App\Services;

use App\Models\PlatformSetting;

/**
 * Central fee-calculation service (F4).
 *
 * Pure reader — queries platform_settings and answers "is a fee due, and
 * how much?" via {@see FeeQuote}. No DB writes, no side effects, no
 * interaction with wallets or transactions. Call sites are responsible
 * for actually charging the fee inside their own DB::transaction
 * (see WithdrawalService::approve).
 *
 * v1 scope:
 *   Only the `withdrawal` category is wired. Additional categories
 *   (origination, service, late, early_repayment, inactivity) are
 *   planned one-by-one via follow-up migrations + extending this service
 *   (each gets its own `fees_<category>_enabled` + parameter row).
 *   Per-category flags were chosen over a single master switch (DECISIONS.md
 *   F4-01 "Feature-flag shape") so operators can roll out gradually.
 *
 * Virtual-ledger semantics (DECISIONS.md F4-01):
 *   A fee is recorded as a TYPE_FEE transaction debited from the
 *   investor's wallet bucket. There is NO corresponding platform-wallet
 *   credit in v1 — the fee simply "leaves the ledger" from the investor's
 *   POV. Admin's bank statement is the source of truth for accrued
 *   platform revenue.
 */
class FeeService
{
    public const CATEGORY_WITHDRAWAL = 'withdrawal';

    public const CATEGORIES = [
        self::CATEGORY_WITHDRAWAL,
    ];

    /**
     * Is the given fee category currently enabled? Reads
     * platform_settings.fees_{category}_enabled. Missing key defaults to
     * false (fail-safe: if a category hasn't been migrated yet, no fee).
     */
    public function isEnabled(string $category): bool
    {
        $this->assertValidCategory($category);
        return (bool) PlatformSetting::get("fees_{$category}_enabled", false);
    }

    /**
     * Convenience reader — the common call site.
     */
    public function isWithdrawalFeeEnabled(): bool
    {
        return $this->isEnabled(self::CATEGORY_WITHDRAWAL);
    }

    /**
     * Configured flat fee for the category as a normalised 2-decimal
     * string (e.g. '2.50'). Missing/unconfigured → '0.00'.
     */
    public function getAmount(string $category): string
    {
        $this->assertValidCategory($category);
        $raw = PlatformSetting::get("fees_{$category}_amount", '0.00');
        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * Build a FeeQuote for a proposed gross transaction amount.
     *
     * Returns applies=false when:
     *   - The category's enabled flag is off.
     *   - The configured amount is 0.00 (fee amount set to zero counts
     *     as "disabled" from the charging POV — no TYPE_FEE transaction
     *     is created for a 0 value, which would fail the
     *     WalletService::debit() "must be positive" guard anyway).
     *   - The gross transaction amount is 0 or negative (defensive; a
     *     real transaction never reaches this but keeps the caller's
     *     branch tidy).
     */
    public function getQuote(string $category, string $grossAmount): FeeQuote
    {
        $this->assertValidCategory($category);

        if (!$this->isEnabled($category)) {
            return new FeeQuote(false, '0.00', $category);
        }

        if (bccomp($grossAmount, '0', 2) <= 0) {
            return new FeeQuote(false, '0.00', $category);
        }

        $amount = $this->getAmount($category);
        if (bccomp($amount, '0', 2) <= 0) {
            return new FeeQuote(false, '0.00', $category);
        }

        return new FeeQuote(true, $amount, $category);
    }

    private function assertValidCategory(string $category): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Unknown fee category: {$category}");
        }
    }
}
