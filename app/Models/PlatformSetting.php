<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurable platform-wide settings (key/value with type metadata).
 *
 * Distinct from PlatformMetric (observed state). A setting is something
 * an admin DECIDES — every change is captured in audit_logs via the
 * Auditable trait. The DB-level CHECK on `grace_period_days` is the
 * defense-in-depth backstop; Filament-level validation is the
 * user-facing first line.
 *
 * Read pattern:
 *   PlatformSetting::get('grace_period_days')      // typed: int 10
 *   PlatformSetting::get('late_check_enabled')     // typed: bool true
 *   PlatformSetting::get('missing_key', 'default') // returns 'default'
 */
class PlatformSetting extends Model
{
    use Auditable;

    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_STRING = 'string';
    public const TYPE_BOOL = 'bool';
    public const TYPE_JSON = 'json';

    public const TYPES = [self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_STRING, self::TYPE_BOOL, self::TYPE_JSON];

    protected $fillable = ['key', 'value', 'type', 'description'];

    /**
     * Coerce the stored string `value` back to its declared PHP type.
     */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            self::TYPE_INT    => (int) $this->value,
            self::TYPE_FLOAT  => (float) $this->value,
            self::TYPE_BOOL   => filter_var($this->value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            self::TYPE_JSON   => json_decode($this->value, true),
            default           => (string) $this->value,
        };
    }

    /**
     * Convenience reader — returns the typed value, or $default if the key is missing.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->typedValue() : $default;
    }
}
