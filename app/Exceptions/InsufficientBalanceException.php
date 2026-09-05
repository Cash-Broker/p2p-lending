<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * The wallet re-check under the row lock found less money than the request
 * assumed — a two-tab race, a concurrent withdrawal, a locked bonus.
 *
 * A subclass of InvalidArgumentException so every existing catch keeps
 * working; its own type so HTTP controllers can answer 422 to THIS outcome
 * only and let every other InvalidArgumentException (state machine, schedule
 * projection, contract build — platform faults) surface as a 500 + CRITICAL
 * alert (audit 2026-09-01 PAY-41, review 2026-09-03).
 */
class InsufficientBalanceException extends InvalidArgumentException {}
