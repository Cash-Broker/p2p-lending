<?php

namespace Tests\Feature;

use App\Filament\Pages\ActivityStats;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin «Активност» page (2026-08-15): whole-base engagement on one
 * screen. Render smoke + data sanity + the admin gate.
 */
class ActivityStatsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_visit_data(): void
    {
        $admin = User::factory()->admin()->create();

        $investor = User::factory()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();
        $investor->forceFill(['dashboard_seen_at' => now()])->save();
        DB::table('user_visit_days')->insert([
            'user_id' => $investor->id,
            'visit_date' => now()->timezone('Europe/Sofia')->toDateString(),
            'entries' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ActivityStats::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$investor]);
    }

    public function test_stats_count_todays_entries(): void
    {
        $admin = User::factory()->admin()->create();

        $investor = User::factory()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();
        DB::table('user_visit_days')->insert([
            'user_id' => $investor->id,
            'visit_date' => now()->timezone('Europe/Sofia')->toDateString(),
            'entries' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);

        $stats = (new ActivityStats)->getStats();

        $this->assertStringContainsString('5', $stats[0]['value']); // Влизания днес
    }

    public function test_non_admin_cannot_access(): void
    {
        $investor = User::factory()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();

        $this->actingAs($investor);

        $this->assertFalse(ActivityStats::canAccess());
    }
}
