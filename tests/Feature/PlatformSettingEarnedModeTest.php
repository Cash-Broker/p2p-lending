<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformSettingResource\Pages\EditPlatformSetting;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard_earned_mode setting edits through a Select ghost field
 * (STRING_CHOICES) instead of the free-text Textarea — the admin can only
 * pick a variant the dashboard understands. Pins the ghost-field → `value`
 * mutation path for choice settings (and that plain strings still use the
 * Textarea path untouched).
 */
class PlatformSettingEarnedModeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_setting_is_seeded_with_daily_default(): void
    {
        $this->assertSame('daily', PlatformSetting::get('dashboard_earned_mode'));
    }

    public function test_admin_switches_variant_via_the_select(): void
    {
        $this->actingAsAdmin();

        $record = PlatformSetting::where('key', 'dashboard_earned_mode')->firstOrFail();

        Livewire::test(EditPlatformSetting::class, ['record' => $record->getKey()])
            ->assertSuccessful()
            ->fillForm(['value_choice' => 'live'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('live', PlatformSetting::get('dashboard_earned_mode'));
    }

    public function test_missing_choice_state_keeps_the_current_value(): void
    {
        $this->actingAsAdmin();

        $record = PlatformSetting::where('key', 'dashboard_earned_mode')->firstOrFail();

        // Saving without touching the Select must not blank the setting.
        Livewire::test(EditPlatformSetting::class, ['record' => $record->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('daily', PlatformSetting::get('dashboard_earned_mode'));
    }
}
