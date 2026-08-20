<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvestorRegisteredAdminNotification;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class TmpZeroAdminProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposed_fix_as_written(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Нов Инвеститор',
            'email' => 'nov@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ])->assertStatus(201);

        // What actually got sent?
        $sent = [];
        Notification::assertSentTimes(InvestorRegisteredAdminNotification::class, 0);

        $fake = Notification::getFacadeRoot();
        $ref = new \ReflectionObject($fake);
        $prop = $ref->getProperty('notifications');
        $prop->setAccessible(true);
        foreach ($prop->getValue($fake) as $notifiable => $classes) {
            foreach ($classes as $class => $items) {
                $sent[] = $notifiable.' => '.$class.' x'.count($items);
            }
        }
        fwrite(STDERR, "\nSENT: ".json_encode($sent, JSON_UNESCAPED_UNICODE)."\n");

        $this->assertTrue(true);
    }

    public function test_telegram_still_fires_with_zero_admins(): void
    {
        Notification::fake();
        $this->app->instance(
            TelegramService::class,
            Mockery::mock(TelegramService::class, function ($mock) {
                $mock->shouldReceive('info')->once();
                $mock->shouldReceive('high')->never();
            }),
        );

        $this->postJson('/api/register', [
            'name' => 'Нов Инвеститор',
            'email' => 'nov2@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ])->assertStatus(201);
    }
}
