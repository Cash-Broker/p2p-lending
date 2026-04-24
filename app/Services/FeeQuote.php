<?php

namespace App\Services;

/**
 * Read-only DTO describing the platform's answer to "is a fee due for this
 * transaction, and how much?". Consumed by the fee-charging call sites
 * (WithdrawalService, future origination / service / late / early-repayment
 * flows). bcmath-only throughout — `amount` is a 2-decimal string.
 *
 * Design note — flat v1:
 *   In v1 the only implemented category is withdrawal, which is a flat
 *   amount regardless of the parent transaction size. This DTO carries an
 *   `amount` field (not a percent) because that is what every call site
 *   ultimately needs; percent-based categories (planned v1.1) would still
 *   resolve to a concrete amount via the FeeService before this DTO is
 *   returned. The caller never sees the calculation strategy.
 */
final class FeeQuote
{
    public function __construct(
        public readonly bool $applies,
        public readonly string $amount,
        public readonly string $category,
    ) {}

    /**
     * Gross minus fee. Safe with bccomp 2 decimals. Caller is responsible
     * for ensuring gross >= fee before calling — this does not guard
     * against negative results (service layer does).
     */
    public function netAmount(string $grossAmount): string
    {
        if (!$this->applies) {
            return number_format((float) $grossAmount, 2, '.', '');
        }
        return bcsub($grossAmount, $this->amount, 2);
    }
}
