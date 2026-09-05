<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PAY-13 — the 04:00 payout run paused ≥ 1 loan (borrower late past the
 * threshold). Every admin gets mail + bell with the two steps that resume the
 * payouts. Deduped per run through `run_at`. No investor data.
 */
class PayoutsPausedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $loanIds
     */
    public function __construct(
        public array $loanIds,
        public CarbonInterface $runAt,
        public int $thresholdDays,
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

    private function idList(): string
    {
        return $this->loanIds === [] ? '—' : implode(', ', array_map(fn (int $id) => '#'.$id, $this->loanIds));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('[Vamaasset] Спряно авансиране: %d кредит(а)', count($this->loanIds)))
            ->greeting('Здравей, '.($notifiable->name ?? 'администратор').',')
            ->line(sprintf(
                'Плащането от %s спря авансирането по кредити %s — кредитополучателят е в закъснение над %d дни.',
                $this->runAt->format('d.m.Y H:i'),
                $this->idList(),
                $this->thresholdDays,
            ))
            ->line('Инвеститорите по тях НЕ получават вноските си, докато: (1) вноските на кредитополучателя не бъдат отбелязани като платени в таб „Погасителен план“ на кредита, или (2) кредитът не бъде изкупен от оригинатора (Buyback Queue). Възобновяването е автоматично при следващото нощно плащане.')
            ->action('Отвори кредитите', config('app.url').'/admin/loans?tableFilters[payouts_paused][isActive]=1')
            ->salutation('Vamaasset');
    }

    public function toDatabase(object $notifiable): array
    {
        return array_merge(
            FilamentNotification::make()
                ->title(sprintf('Спряно авансиране: %d кредит(а)', count($this->loanIds)))
                ->body('Кредити '.$this->idList().' — кредитополучателят е в закъснение над прага. Отбележи вноските или изкупи кредита.')
                ->icon('heroicon-o-pause-circle')
                ->warning()
                ->getDatabaseMessage(),
            $this->toArray($notifiable),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payouts_paused',
            'loan_ids' => $this->loanIds,
            'threshold_days' => $this->thresholdDays,
            'run_at' => $this->runAt->toIso8601String(),
        ];
    }
}
