<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a principal-return operation would withdraw more from an
 * investor's `invested` bucket than it holds. Replaces the old silent
 * "clamp to zero + credit full amount" behavior, which manufactured
 * spendable balance (audit's confirmed money-creation path). Surfacing this
 * as an exception forces a rollback and an investigation rather than papering
 * over a distribution bug.
 */
class InvestedUnderflowException extends RuntimeException {}
