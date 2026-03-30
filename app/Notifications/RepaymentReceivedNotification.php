<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RepaymentReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $loanId,
        private string $principalAmount,
        private string $interestAmount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = bcadd($this->principalAmount, $this->interestAmount, 2);

        return (new MailMessage)
            ->subject("Получено погашение — {$total} € — P2P Invest")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Получихте погашение по кредит #{$this->loanId}.")
            ->line("Главница: {$this->principalAmount} €")
            ->line("Лихва: {$this->interestAmount} €")
            ->line("Общо: {$total} €")
            ->action('Виж портфолио', config('app.url') . '/portfolio')
            ->salutation('Поздрави, екипът на P2P Invest');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'loan_id' => $this->loanId,
            'principal' => $this->principalAmount,
            'interest' => $this->interestAmount,
            'total' => bcadd($this->principalAmount, $this->interestAmount, 2),
        ];
    }
}
