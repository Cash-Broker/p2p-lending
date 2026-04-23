<?php

namespace App\Services\Loans;

use DomainException;

/**
 * Raised by EarlyRepaymentExecutionService when a second execution is
 * attempted on a loan that was already early-repaid (early_repaid_at
 * IS NOT NULL).
 *
 * Distinct from the InvalidArgumentException thrown when the loan
 * reached status=`repaid` through scheduled completion (early_repaid_at
 * IS NULL) — that's a different semantic ("loan finished normally;
 * early-repay is not applicable").
 *
 * Callers (Filament row action, admin CLI) should catch this and
 * surface a friendly "already executed" message.
 */
final class EarlyRepaymentAlreadyExecutedException extends DomainException
{
    public function __construct(
        public readonly int $loanId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
