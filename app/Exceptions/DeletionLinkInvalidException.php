<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * SEC-22: a deletion confirm/cancel link was opened for an account that has no
 * matching open request (cancelled, re-requested, already finalised, or a
 * foreign hash). The web route turns it into the `/login?deletion=invalid`
 * landing; the service stays HTTP-agnostic.
 */
class DeletionLinkInvalidException extends RuntimeException {}
