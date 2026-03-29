<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Потвърди имейл адреса си — P2P Invest')
            ->greeting('Добре дошъл в P2P Invest!')
            ->line('Благодарим ти за регистрацията. Моля, потвърди имейл адреса си, за да активираш акаунта си и да започнеш да инвестираш.')
            ->action('Потвърди имейл адреса си', $url)
            ->line('Ако не си създавал акаунт в P2P Invest, не е необходимо да предприемаш действия.')
            ->salutation('Поздрави, екипът на P2P Invest');
    }
}
