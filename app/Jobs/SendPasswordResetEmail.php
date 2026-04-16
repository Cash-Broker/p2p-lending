<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

/**
 * Queued password-reset dispatch.
 *
 * Pushed onto the queue from AuthController@forgotPassword so that the HTTP
 * response time is constant regardless of whether the email is registered —
 * attackers cannot tell which addresses exist by timing the response.
 *
 * The job itself runs Password::sendResetLink which is a no-op for unknown
 * emails (returns INVALID_USER) and dispatches the reset notification for
 * known users.
 */
class SendPasswordResetEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $email) {}

    public function handle(): void
    {
        Password::sendResetLink(['email' => $this->email]);
    }
}
