<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor-facing weekly earnings email (client request 2026-08-13, Reni:
 * «седмичен бюлетин — тази седмица спечели X сума», «по имейл да им
 * изпращаме», «да си ги има постоянно»). Mail-only and queued — the SPA
 * bell deliberately stays quiet for a recurring digest.
 *
 * Constructor snapshot pattern (project convention): every figure is frozen
 * at dispatch time; a worker delivering later shows the cron-run numbers.
 * All money figures are bcmath strings, formatted only for display.
 */
class InvestorWeeklyEarningsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $weeklyInterest  interest INCOME received in the window (2dp string)
     * @param  string  $accruedNow  current running profit — schedule-accrued, unpaid (2dp string)
     * @param  string  $totalPaid  lifetime interest paid out (wallet earned, 2dp string)
     */
    public function __construct(
        public string $weeklyInterest,
        public string $accruedNow,
        public string $totalPaid,
        public CarbonInterface $windowStart,
        public CarbonInterface $windowEnd,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Vamaasset] Тази седмица спечели '.$this->formatBg($this->weeklyInterest).' €')
            ->markdown('emails.investor-weekly-earnings', [
                'name' => $notifiable->name ?? 'инвеститор',
                'weeklyInterest' => $this->formatBg($this->weeklyInterest),
                'accruedNow' => $this->formatBg($this->accruedNow),
                'totalPaid' => $this->formatBg($this->totalPaid),
                'windowStart' => $this->windowStart->format('d.m.Y'),
                'windowEnd' => $this->windowEnd->format('d.m.Y'),
                'dashboardUrl' => config('app.url').'/dashboard',
            ]);
    }

    /** bg-BG money formatting for the email body (display only). */
    private function formatBg(string $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ');
    }
}
