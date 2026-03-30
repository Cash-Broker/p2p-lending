<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(private int $loanId, private string $newStatus) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabels = [
            'late' => 'закъснял',
            'default' => 'просрочен',
            'repaid' => 'изплатен',
            'funded' => 'напълно финансиран',
        ];
        $label = $statusLabels[$this->newStatus] ?? $this->newStatus;

        return (new MailMessage)
            ->subject("Кредит #{$this->loanId} — статус: {$label} — P2P Invest")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Статусът на кредит #{$this->loanId}, в който имате инвестиция, е променен на \"{$label}\".")
            ->action('Виж портфолио', config('app.url') . '/portfolio')
            ->salutation('Поздрави, екипът на P2P Invest');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'loan_status_changed', 'loan_id' => $this->loanId, 'status' => $this->newStatus];
    }
}
