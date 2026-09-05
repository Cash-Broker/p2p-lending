<?php

namespace App\Support;

use App\Mail\OpsAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Operations alerts — «ако някоя операция не мине» (owner, 2026-09-03).
 *
 * One recipient, `config('app.admin_email')` (ADMIN_ALERT_EMAIL), used by the
 * ledger reconciliation, failed scheduled commands, failed payout runs and the
 * queue-backlog alarm. Sent SYNCHRONOUSLY on purpose: the queue worker may be
 * the very thing that is down. Never throws — an alert must not change the
 * outcome of the operation it reports on.
 */
final class OpsAlert
{
    /** The owner's address — the fallback when the config/env value is empty. */
    public const DEFAULT_RECIPIENT = 'yordanyordanov0104@gmail.com';

    public static function email(): ?string
    {
        $email = config('app.admin_email');

        return is_string($email) && $email !== '' ? $email : self::DEFAULT_RECIPIENT;
    }

    public static function mail(string $subject, string $body): bool
    {
        $to = self::email();
        if ($to === null) {
            Log::warning('Ops alert not sent: app.admin_email is empty', ['subject' => $subject]);

            return false;
        }

        try {
            Mail::to($to)->send(new OpsAlertMail('[Vamaasset ALERT] '.$subject, $body));

            return true;
        } catch (\Throwable $e) {
            Log::error('Ops alert e-mail failed', ['subject' => $subject, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
