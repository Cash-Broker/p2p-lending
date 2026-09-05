<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The admin could not prove their password again (wrong password or too many
 * failed attempts). Message is Bulgarian — it is shown verbatim in a panel toast.
 */
class AdminReauthenticationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}
