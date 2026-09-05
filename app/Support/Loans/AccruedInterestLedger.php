<?php

namespace App\Support\Loans;

use App\Models\Transaction;

/**
 * The ONE definition of "interest currently locked in `accrued` for an
 * investment", read from the immutable ledger.
 *
 * Σ interest_accrued − Σ interest_released − Σ interest_accrual_reversed over
 * EVERY reference written for the investment — the payout engine's
 * `loan:{l}:investment:{i}:capitalized`, buyback's `…:buyback` and early
 * closure's `…:early_closure`. Before this helper existed the three engines
 * each carried their own copy and the early-closure one matched the exact
 * `…:capitalized` reference only, so it never saw the releases written by a
 * previous closure (audit 2026-09-01, PAY-12 / HEALTH-01).
 *
 * The trailing ':' in the LIKE pattern keeps investment 1 from matching 11.
 */
final class AccruedInterestLedger
{
    public static function netFor(int $loanId, int $investmentId): string
    {
        $rows = Transaction::query()
            ->where('reference', 'like', "loan:{$loanId}:investment:{$investmentId}:%")
            ->whereIn('type', [
                Transaction::TYPE_INTEREST_ACCRUED,
                Transaction::TYPE_INTEREST_RELEASED,
                Transaction::TYPE_INTEREST_ACCRUAL_REVERSED,
            ])
            ->get(['type', 'amount']);

        $net = '0.00';
        foreach ($rows as $row) {
            $net = $row->type === Transaction::TYPE_INTEREST_ACCRUED
                ? bcadd($net, (string) $row->amount, 2)
                : bcsub($net, (string) $row->amount, 2);
        }

        return $net;
    }
}
