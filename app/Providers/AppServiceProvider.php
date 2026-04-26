<?php

namespace App\Providers;

use App\Listeners\SendAdminLoginAlert;
use App\Listeners\TelegramAdminLoginAlert;
use App\Listeners\TelegramFailedLoginAlert;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Financial platform password policy — applies globally to Password::defaults()
        // which is used in RegisterRequest, reset password, and any future password fields.
        // Requirements: 8+ chars, mixed case, at least 1 number, at least 1 symbol.
        Password::defaults(function () {
            return Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();
        });

        // Email alert on every successful admin login. Compensating control for
        // the absence of 2FA — see DECISIONS.md.
        Event::listen(Login::class, SendAdminLoginAlert::class);

        // Telegram mirrors of admin events (HIGH tier — push notification but
        // not blocking). Email remains primary; Telegram is faster signal.
        Event::listen(Login::class, TelegramAdminLoginAlert::class);

        // Telegram alert on suspicious failed-login patterns (CRITICAL tier).
        // Threshold: 5+ failures in 10 min from same IP. Distinguishes admin
        // vs investor vs scanner-bot patterns.
        Event::listen(Failed::class, TelegramFailedLoginAlert::class);

        // Build password reset email URL directly, bypassing Laravel's default
        // route('password.reset', [...]) lookup. This decouples the password
        // reset notification from any named route registration in routes/web.php
        // — even if the route is removed/renamed/cached-stale, the link still
        // resolves to the SPA's reset page. Defense-in-depth alongside the
        // stub in routes/web.php.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForVerification());
            return config('app.url') . "/reset-password/{$token}?email={$email}";
        });
    }
}
