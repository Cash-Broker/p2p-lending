<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail
{
    use Queueable;

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => hash_hmac('sha256', $notifiable->getEmailForVerification(), config('app.key')),
            ]
        );
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Потвърди имейл адреса си — Vamaasset')
            ->greeting('Добре дошъл в Vamaasset!')
            ->line('Благодарим ти за регистрацията. Моля, потвърди имейл адреса си, за да активираш акаунта си и да започнеш да инвестираш.')
            ->action('Потвърди имейл адреса си', $url)
            ->line('Ако не си създавал акаунт в Vamaasset, не е необходимо да предприемаш действия.')
            ->salutation('Поздрави, екипът на Vamaasset');
    }
}
