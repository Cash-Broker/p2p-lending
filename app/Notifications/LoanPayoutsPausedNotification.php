<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor notification — PAY-13: the platform stopped fronting this loan's
 * payouts because the borrower is late past the threshold. Mail + inbox,
 * queued, deduped per (loan, paused_at) exactly like LoanWentLateNotification.
 * No borrower PII, no policy numbers beyond «над допустимия срок».
 *
 * ⚠ Wording awaits Reni's approval before `payout_pause_enabled` is ever
 * switched on (05-fix-plan.md, open questions) — it fires only on a pause.
 */
class LoanPayoutsPausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $loanId,
        public CarbonInterface $pausedAt,
        public int $daysLate,
        public int $withheldRows,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->wasAlreadySent($notifiable)) {
            return [];
        }

        return ['mail', 'database'];
    }

    private function wasAlreadySent(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        return $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->loan_id', $this->loanId)
            ->whereJsonContains('data->paused_at', $this->pausedAt->toIso8601String())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Плащанията по кредит #{$this->loanId} са временно спрени — Vamaasset")
            ->markdown('emails.loan-payouts-paused', [
                'name' => $notifiable->name,
                'loanId' => $this->loanId,
                'pausedAt' => $this->pausedAt,
                'daysLate' => $this->daysLate,
                'withheldRows' => $this->withheldRows,
                'portfolioUrl' => config('app.url').'/portfolio',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'loan_payouts_paused',
            'loan_id' => $this->loanId,
            'paused_at' => $this->pausedAt->toIso8601String(),
            'days_late' => $this->daysLate,
            'withheld_rows' => $this->withheldRows,
        ];
    }
}
