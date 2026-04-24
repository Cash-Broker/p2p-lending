<?php

namespace Tests\Feature;

use App\Filament\Resources\OriginatorResource\Pages\CreateOriginator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament CreateOriginator render-smoke test.
 *
 * Regression guard: the create page crashed 500 in production because
 * `->visible(fn (Forms\Get $get) => ...)` used the wrong type hint.
 * Filament 5.4 has NO `Filament\Forms\Get` class — only
 * `Filament\Schemas\Components\Utilities\Get`. PHP's closure type check
 * fires on first invocation, producing a TypeError during form render.
 *
 * This test mounts the Livewire page as an admin and asserts a
 * successful render. If anyone ever re-introduces a `Forms\Get` /
 * `Forms\Set` / `Forms\<anything>` type hint in a closure, this test
 * catches it before it ships.
 *
 * Related: FeesPageRenderTest pins the same guard for the F4 admin page.
 */
class OriginatorResourceCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_without_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(CreateOriginator::class)->assertSuccessful();
    }

    public function test_create_page_with_buyback_toggled_on_still_renders(): void
    {
        // The `buyback` toggle controls visibility of `buyback_coverage`
        // + `buyback_trigger_days` via Get-closures — the exact code path
        // that used to crash. Flipping it on forces Filament to re-render
        // those fields, exercising the closures under real form state.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(CreateOriginator::class)
            ->set('data.buyback', true)
            ->assertSuccessful();
    }
}
