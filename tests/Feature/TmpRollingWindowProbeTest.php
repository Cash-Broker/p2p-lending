<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvestorRegisteredAdminNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TmpRollingWindowProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_paced_registrations_refire_the_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 10 ballast investors paced 340s apart, all inside the 3600s window.
        for ($i = 1; $i <= 10; $i++) {
            User::factory()->create(['created_at' => now()->subSeconds(340 * $i)]);
        }

        Notification::fake();
        $first = User::factory()->create(['created_at' => now()]);
        event(new Registered($first));

        Notification::assertSentTo($admin, InvestorRegisteredAdminNotification::class,
            fn ($n) => $n->consolidatedCount === 11);

        // One pacing interval later the oldest ballast row ages out.
        $this->travel(340)->seconds();

        Notification::fake();
        $second = User::factory()->create(['created_at' => now()]);
        event(new Registered($second));

        Notification::assertSentTo($admin, InvestorRegisteredAdminNotification::class,
            fn ($n) => $n->consolidatedCount === 11);

        // And again.
        $this->travel(340)->seconds();
        Notification::fake();
        $third = User::factory()->create(['created_at' => now()]);
        event(new Registered($third));

        Notification::assertSentTo($admin, InvestorRegisteredAdminNotification::class,
            fn ($n) => $n->consolidatedCount === 11);
    }
}
