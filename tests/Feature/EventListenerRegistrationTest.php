<?php

namespace Tests\Feature;

use App\Listeners\SendAdminLoginAlert;
use App\Listeners\SendInvestorRegisteredAlert;
use App\Listeners\TelegramAdminLoginAlert;
use App\Listeners\TelegramFailedLoginAlert;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Listeners are wired ONE way in this app: the explicit Event::listen calls in
 * AppServiceProvider::boot(). Laravel's automatic discovery of app/Listeners is
 * switched off in bootstrap/app.php.
 *
 * Both mechanisms were active until 2026-08-20, so every listener was
 * registered twice — discovery as `Class@handle`, the explicit call as `Class`
 * — and every admin login sent two identical Telegram messages. The email leg
 * of the same alert was independently dead (Cache::increment on a missing key
 * returns false with the database store), which is why nobody noticed.
 *
 * This test fails on either mistake: a re-enabled discovery (duplicate) or a
 * listener that nobody remembered to register (missing).
 */
class EventListenerRegistrationTest extends TestCase
{
    /** @return array<class-string, list<class-string>> */
    public static function expectedListeners(): array
    {
        return [
            Login::class => [SendAdminLoginAlert::class, TelegramAdminLoginAlert::class],
            Failed::class => [TelegramFailedLoginAlert::class],
            Registered::class => [SendInvestorRegisteredAlert::class],
        ];
    }

    public function test_every_listener_is_registered_exactly_once(): void
    {
        $raw = Event::getRawListeners();

        foreach (self::expectedListeners() as $event => $listeners) {
            $registered = array_map(
                // Discovery registers as `Class@method`, Event::listen as `Class`.
                // Both forms count as one registration of the same handler.
                fn (string $listener): string => explode('@', $listener)[0],
                array_filter($raw[$event] ?? [], 'is_string'),
            );

            foreach ($listeners as $listener) {
                $this->assertSame(
                    1,
                    count(array_keys($registered, $listener, true)),
                    $listener.' must be subscribed to '.$event.' exactly once. Two means '
                        .'discovery is on again (bootstrap/app.php) on top of the explicit '
                        .'Event::listen; zero means nobody registered it and the alert is dead.',
                );
            }
        }
    }

    /**
     * The registration alert is the one a stranger can trigger (/api/register
     * is public), so a duplicate there would double every alert AND double the
     * flood-guard's own accounting.
     */
    public function test_registration_alert_is_not_double_subscribed(): void
    {
        $registered = array_filter(Event::getRawListeners()[Registered::class] ?? [], 'is_string');

        $this->assertCount(
            1,
            array_filter($registered, fn (string $l) => str_starts_with($l, SendInvestorRegisteredAlert::class)),
        );
    }
}
