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
 * Admin-facing EVENT alert: an investor just submitted KYC documents —
 * emailed the moment it happens, so review does not wait for the 09:00
 * digest (client request 2026-08-07: event-driven, "когато има какво,
 * без час" — the applicant may be in a hurry to invest).
 *
 * Channels: mail ONLY. The in-panel Filament bell for the same event is
 * dispatched at the call site (ProfileController) via notifyNow() — the
 * explicit synchronous path, needed because Filament v5's
 * DatabaseNotification implements ShouldQueue and a plain notify() would
 * make panel visibility depend on a queue worker being alive. This class
 * carries just the (queued) email leg.
 *
 * Dispatch guard (at the call site, NOT here): sent only when the
 * submission transitions INTO the review queue (previous kyc_status not
 * already submitted/in_review). A re-upload while review is pending
 * refreshes the documents and the bell, but must not re-page the
 * admin's inbox.
 *
 * Queue semantics (honest, per the 2026-08-07 notification-pattern
 * contract): via() runs once at dispatch; a retried mail job after SMTP
 * handoff can re-send (accepted: at-least-once). No via()-level dedupe —
 * the call-site transition guard is the idempotency boundary.
 *
 * PII hygiene: applicant name/email/account type only — the same fields
 * the admin bell already shows. NO document paths, NO ЕГН/ЕИК, nothing
 * from the encrypted KYC payload leaves the platform.
 *
 * Constructor snapshot pattern (F1/F2 convention): scalars frozen at
 * dispatch time — a worker delivering later renders the submission-time
 * facts even if the user has since been renamed or reviewed.
 */
class KycSubmittedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    /**
     * @param  int  $applicantId  users.id of the submitter (builds the review URL)
     * @param  string  $applicantName  submitter's name at submission time
     * @param  string  $applicantEmail  submitter's email at submission time
     * @param  string  $accountType  individual | legal_entity
     * @param  CarbonInterface  $submittedAt  moment of submission
     */
    public function __construct(
        public int $applicantId,
        public string $applicantName,
        public string $applicantEmail,
        public string $accountType,
        public CarbonInterface $submittedAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Vamaasset] Нова KYC заявка — '.$this->applicantName)
            ->markdown('emails.kyc-submitted-admin', [
                'adminName' => $notifiable->name ?? 'администратор',
                'applicantName' => $this->applicantName,
                'applicantEmail' => $this->applicantEmail,
                'accountTypeLabel' => $this->accountType === 'legal_entity'
                    ? 'Юридическо лице'
                    : 'Физическо лице',
                'submittedAtFormatted' => $this->submittedAt->format('d.m.Y H:i'),
                'reviewUrl' => config('app.url').'/admin/users/'.$this->applicantId,
            ]);
    }

    /** Lockscreen hygiene: no applicant name — details behind admin auth. */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            'Ново KYC за преглед',
            'Инвеститор чака верификация.',
            config('app.url').'/admin/users/'.$this->applicantId,
            "kyc-{$this->applicantId}",
        );
    }
}
