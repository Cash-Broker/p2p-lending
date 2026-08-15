<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserVisitDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin-only visit analytics (2026-08-15): a dashboard ENTRY = first load or
 * a load 30+ minutes after the previous one; refreshes inside a session
 * never count. One counter row per user per Sofia calendar day.
 */
class VisitTrackerTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_first_load_counts_one_entry_for_the_sofia_day(): void
    {
        $user = $this->investor();

        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $row = UserVisitDay::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, $row->entries);
        $this->assertSame(now()->timezone('Europe/Sofia')->toDateString(), $row->visit_date->toDateString());
    }

    public function test_refreshes_within_the_session_do_not_count(): void
    {
        $user = $this->investor();

        // fresh() per request mirrors production: the auth layer loads the
        // user from the DB on every request (a reused in-memory instance
        // would carry a stale dashboard_seen_at and defeat the guard).
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
        $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();
        $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();

        $this->assertSame(1, (int) UserVisitDay::where('user_id', $user->id)->sum('entries'));
    }

    public function test_a_return_after_thirty_minutes_counts_a_new_entry(): void
    {
        $user = $this->investor();

        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        // Simulate the pause: the previous stamp is 40 minutes old.
        $user->forceFill(['dashboard_seen_at' => now()->subMinutes(40)])->save();

        $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();

        $this->assertSame(2, (int) UserVisitDay::where('user_id', $user->id)->sum('entries'));
    }
}
