<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Admin-facing EVENT alert: someone just registered an investor account
 * (Reni 2026-08-20 — «за нови регистрации на инвеститори може ли да
 * получавам известия»). Same shape as the KYC / withdrawal alerts added
 * on 2026-08-07: event-driven, so she does not wait for the 09:00 digest.
 *
 * Channels: mail + Web Push. The in-panel Filament bell is dispatched at
 * the call site (SendInvestorRegisteredAlert) via notifyNow() — the
 * explicit synchronous path, because Filament v5's DatabaseNotification
 * implements ShouldQueue and a plain notify() would make panel visibility
 * depend on a live queue worker.
 *
 * Flood guard (at the call site, NOT here): /api/register is the first
 * PUBLIC endpoint wired to an admin alert — unlike KYC and withdrawals,
 * no session is needed to trigger it. The listener therefore counts the
 * investor registrations of the last hour and, past the threshold, sends
 * ONE consolidated alert instead of one per account. `consolidatedCount`
 * carries that number; 1 means a normal single registration. Same shape
 * as SendAdminLoginAlert's 1h consolidation.
 *
 * PII hygiene: name / email / account type only — the same fields the
 * admin bell and the Users list already show. The Web Push leg carries
 * NO name at all (lockscreen), just the fact and a link into the panel.
 *
 * Constructor snapshot pattern (F1/F2 convention): scalars frozen at
 * dispatch time, so a worker delivering later renders the facts as they
 * were at registration even if the account has since been renamed.
 */
class InvestorRegisteredAdminNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    /**
     * @param  int  $investorId  users.id of the new account (builds the panel URL)
     * @param  string  $investorName  name given at registration
     * @param  string  $investorEmail  email given at registration
     * @param  string  $accountType  individual | legal_entity
     * @param  CarbonInterface  $registeredAt  moment of registration
     * @param  int  $consolidatedCount  1 = single registration; >1 = burst summary
     */
    public function __construct(
        public int $investorId,
        public string $investorName,
        public string $investorEmail,
        public string $accountType,
        public CarbonInterface $registeredAt,
        public int $consolidatedCount = 1,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', QueuedWebPushChannel::class];
    }

    public function isConsolidated(): bool
    {
        return $this->consolidatedCount > 1;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->isConsolidated()
            ? '[Vamaasset] '.$this->consolidatedCount.' нови регистрации за последния час'
            : '[Vamaasset] Нова регистрация — '.$this->investorName;

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.investor-registered-admin', [
                'adminName' => $notifiable->name ?? 'администратор',
                'investorName' => $this->investorName,
                'investorEmail' => $this->investorEmail,
                'accountTypeLabel' => $this->accountType === 'legal_entity'
                    ? 'Юридическо лице'
                    : 'Физическо лице',
                'registeredAtFormatted' => $this->registeredAt->format('d.m.Y H:i'),
                'consolidatedCount' => $this->consolidatedCount,
                'isConsolidated' => $this->isConsolidated(),
                'profileUrl' => config('app.url').'/admin/users/'.$this->investorId,
                'usersUrl' => config('app.url').'/admin/users',
            ]);
    }

    /** Lockscreen hygiene: no investor name — details behind admin auth. */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        if ($this->isConsolidated()) {
            return $this->webPushMessage(
                $this->consolidatedCount.' нови регистрации',
                'Повишена активност през последния час.',
                config('app.url').'/admin/users',
                'registrations-burst',
            );
        }

        return $this->webPushMessage(
            'Нова регистрация',
            'Нов инвеститор си създаде профил.',
            config('app.url').'/admin/users/'.$this->investorId,
            "registration-{$this->investorId}",
        );
    }
}
