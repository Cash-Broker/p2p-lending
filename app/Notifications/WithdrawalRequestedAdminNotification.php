<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-facing EVENT alert: an investor just requested a withdrawal —
 * emailed the moment it happens, so processing does not wait for the
 * 09:00 digest (client request 2026-08-07: event-driven, "когато има
 * какво, без час" — the investor's money is reserved until the admin
 * wires it out, so speed matters).
 *
 * Channels: mail ONLY. The in-panel Filament bell for the same event is
 * dispatched at the call site (WithdrawalController) via notifyNow() —
 * the explicit synchronous path, needed because Filament v5's
 * DatabaseNotification implements ShouldQueue and a plain notify() would
 * make panel visibility depend on a queue worker being alive. This class
 * carries the (queued) email leg.
 *
 * Fires once per withdrawal request (created exactly once, at the
 * user's POST). No via()-level dedupe — creation is the idempotency
 * boundary. Queue semantics honest per the 2026-08-07 contract: a
 * retried mail job after SMTP handoff can re-send (at-least-once).
 *
 * PII hygiene: investor name + amount only. The IBAN is encrypted at
 * rest and deliberately NEVER leaves the platform by email — the admin
 * sees it in Filament when processing.
 *
 * Constructor snapshot pattern: scalars frozen at dispatch time.
 */
class WithdrawalRequestedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $withdrawalId  withdrawal_requests.id
     * @param  string  $investorName  requester's name at request time
     * @param  string  $amount  requested amount (bcmath string, scale 2)
     * @param  CarbonInterface  $requestedAt  moment of the request
     */
    public function __construct(
        public int $withdrawalId,
        public string $investorName,
        public string $amount,
        public CarbonInterface $requestedAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Vamaasset] Ново заявено теглене — '.$this->amount.' €')
            ->markdown('emails.withdrawal-requested-admin', [
                'adminName' => $notifiable->name ?? 'администратор',
                'withdrawalId' => $this->withdrawalId,
                'investorName' => $this->investorName,
                'amount' => $this->amount,
                'requestedAtFormatted' => $this->requestedAt->format('d.m.Y H:i'),
                'reviewUrl' => config('app.url').'/admin/withdrawal-requests',
            ]);
    }
}
