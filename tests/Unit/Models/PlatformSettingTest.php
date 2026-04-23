<?php

namespace Tests\Unit\Models;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_int_typed_value_returns_integer(): void
    {
        $value = PlatformSetting::get('grace_period_days');
        $this->assertIsInt($value);
        $this->assertSame(10, $value);
    }

    public function test_bool_typed_value_returns_boolean(): void
    {
        $value = PlatformSetting::get('late_check_enabled');
        $this->assertIsBool($value);
        $this->assertTrue($value);
    }

    public function test_bool_false_string_coerces_to_false(): void
    {
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);
        $this->assertFalse(PlatformSetting::get('late_check_enabled'));
    }

    public function test_json_typed_value_returns_array(): void
    {
        PlatformSetting::create([
            'key' => 'test_json',
            'value' => json_encode(['a' => 1, 'b' => 'two']),
            'type' => 'json',
        ]);
        $value = PlatformSetting::get('test_json');
        $this->assertIsArray($value);
        $this->assertSame(1, $value['a']);
        $this->assertSame('two', $value['b']);
    }

    public function test_missing_key_returns_default(): void
    {
        $this->assertSame('fallback', PlatformSetting::get('nonexistent_key', 'fallback'));
        $this->assertNull(PlatformSetting::get('nonexistent_key'));
    }

    public function test_settings_are_auditable_on_update(): void
    {
        // Auth as a user so the audit_log captures user_id.
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin);

        $setting = PlatformSetting::where('key', 'grace_period_days')->first();
        $setting->update(['value' => '7']);

        $auditExists = AuditLog::where('model_type', PlatformSetting::class)
            ->where('model_id', $setting->id)
            ->where('action', 'updated')
            ->exists();
        $this->assertTrue($auditExists, 'PlatformSetting updates must be audited');
    }
}
