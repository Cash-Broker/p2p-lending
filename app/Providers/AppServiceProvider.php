<?php

namespace App\Providers;

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
    }
}
