<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Sent to an admin every time their account is used to log in.
 *
 * Subject line distinguishes a login from a previously-trusted IP from a
 * brand-new IP — admins can spot account takeover even without 2FA.
 *
 * Queued so the actual login response is not delayed by SMTP.
 */
class AdminLoginAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $trustUrl;

    public function __construct(
        public User $admin,
        public string $ipAddress,
        public ?string $userAgent,
        public CarbonInterface $occurredAt,
        public bool $isKnownIp,
        public int $consolidatedCount = 1,
    ) {
        $this->trustUrl = URL::temporarySignedRoute(
            'admin.trust-ip',
            now()->addDays(7),
            ['user' => $admin->id, 'ip' => $ipAddress],
        );
    }

    public function envelope(): Envelope
    {
        $tag = $this->isKnownIp
            ? '[Admin Login - Known IP]'
            : '[Admin Login - NEW IP - VERIFY!]';

        return new Envelope(
            subject: $tag . ' Vamaasset',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin-login-alert',
            with: [
                'admin' => $this->admin,
                'ipAddress' => $this->ipAddress,
                'userAgent' => $this->userAgent,
                'occurredAt' => $this->occurredAt,
                'isKnownIp' => $this->isKnownIp,
                'consolidatedCount' => $this->consolidatedCount,
                'trustUrl' => $this->trustUrl,
            ],
        );
    }
}
