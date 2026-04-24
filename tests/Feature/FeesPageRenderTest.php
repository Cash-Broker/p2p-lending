<?php

namespace Tests\Feature;

use App\Filament\Pages\FeesPage;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament FeesPage render-smoke test.
 *
 * Regression guard: F4's FeesPage also carried the `Forms\Get`
 * type-hint bug (same root cause as OriginatorResource::$visible).
 * Not caught by F4's test suite because none of those tests invoke
 * Filament form rendering — a real coverage gap documented in the F4
 * audit report (F4-L3 "no browser tests") plus this specific
 * Filament-render angle.
 *
 * Two assertions:
 *  1. Page renders at all (fees flag off — default).
 *  2. Page renders with the flag toggled on, exercising the
 *     renderPreview() Get-closure under real state.
 *
 * Related: OriginatorResourceCreateTest pins the same guard for
 * the F2 admin page.
 */
class FeesPageRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_without_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(FeesPage::class)->assertSuccessful();
    }

    public function test_page_renders_when_fees_flag_is_on(): void
    {
        // Toggling the flag changes the branch in renderPreview() — make
        // sure the Get closure path is exercised in both directions.
        PlatformSetting::set('fees_withdrawal_enabled', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(FeesPage::class)
            ->set('data.preview_amount', '100.00')
            ->assertSuccessful();
    }
}
