<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class RepaymentReceivedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(
        private int $loanId,
        private string $principalAmount,
        private string $interestAmount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = bcadd($this->principalAmount, $this->interestAmount, 2);

        return (new MailMessage)
            ->subject("Получено погашение — {$total} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Получихте погашение по кредит #{$this->loanId}.")
            ->line("Главница: {$this->principalAmount} €")
            ->line("Лихва: {$this->interestAmount} €")
            ->line("Общо: {$total} €")
            ->action('Виж портфолио', config('app.url').'/portfolio')
            ->salutation('Поздрави, екипът на Vamaasset');
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Получихте вноска от кредит #{$this->loanId}",
            "Главница {$this->principalAmount} € · лихва {$this->interestAmount} €",
            config('app.url').'/portfolio',
            "repayment-{$this->loanId}",
        );
    }
}
