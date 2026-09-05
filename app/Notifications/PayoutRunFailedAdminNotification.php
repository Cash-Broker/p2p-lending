<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Audit 2026-09-01 (A3, PAY-16) — the 04:00 payout cron used to record
 * `loans_failed > 0` only in a log file and a metric nobody reads. A loan that
 * fails inside the run means its investors were NOT paid that day while every
 * other loan was; this reaches every admin (mail + bell) the same morning.
 *
 * No investor data: the loan ids are enough to open the loan in Filament.
 * Deduped per run through `run_at` in the database payload.
 */
class PayoutRunFailedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $failedLoanIds
     */
    public function __construct(
        public int $loansProcessed,
        public int $loansFailed,
        public array $failedLoanIds,
        public CarbonInterface $runAt,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->wasRecentlyNotified($notifiable)) {
            return [];
        }

        return ['mail', 'database'];
    }

    private function wasRecentlyNotified(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        return $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->run_at', $this->runAt->toIso8601String())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ids = $this->failedLoanIds === [] ? '—' : implode(', ', array_map(fn (int $id) => '#'.$id, $this->failedLoanIds));

        return (new MailMessage)
            ->error()
            ->subject(sprintf('[Vamaasset] Плащания към инвеститори: %d кредит(а) НЕ са обработени', $this->loansFailed))
            ->greeting('Здравей, '.($notifiable->name ?? 'администратор').',')
            ->line(sprintf(
                'Автоматичното плащане от %s обработи %d кредит(а) успешно и %d с грешка.',
                $this->runAt->format('d.m.Y H:i'),
                $this->loansProcessed,
                $this->loansFailed,
            ))
            ->line('Кредити с грешка: '.$ids.'. Инвеститорите по тях НЕ са получили падежа си за днес.')
            ->line('Подробностите са в storage/logs/loans-process-payouts.log и laravel.log. След отстраняване на причината пусни плащането ръчно от кредита («Пусни плащане сега») — редовете, които вече са платени, не се плащат повторно.')
            ->action('Отвори кредитите', config('app.url').'/admin/loans')
            ->salutation('Vamaasset');
    }

    public function toDatabase(object $notifiable): array
    {
        return array_merge(
            FilamentNotification::make()
                ->title(sprintf('Плащания: %d кредит(а) с грешка', $this->loansFailed))
                ->body('Инвеститорите по тях не са платени днес. Виж имейла / лога.')
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
                ->getDatabaseMessage(),
            $this->toArray($notifiable),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payout_run_failed',
            'loans_processed' => $this->loansProcessed,
            'loans_failed' => $this->loansFailed,
            'failed_loan_ids' => $this->failedLoanIds,
            'run_at' => $this->runAt->toIso8601String(),
        ];
    }
}
