<?php

namespace App\Services\Loans;

use DomainException;

/**
 * Raised by BuybackExecutionService when a second execution is attempted
 * on a loan that is already in status=bought_back.
 *
 * Callers (Filament Buyback Queue action, admin CLI) should catch this
 * and render a friendly "already processed" message rather than a 500.
 */
class BuybackAlreadyExecutedException extends DomainException
{
    public function __construct(
        public readonly int $loanId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
