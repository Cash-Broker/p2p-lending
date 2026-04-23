<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Observed state of the system, written by automated jobs (cron commands).
 *
 * Distinct from PlatformSetting (configurable). A metric is something the
 * system measures and stamps with a timestamp. Examples written by
 * loans:process-late:
 *   last_late_check_run_at, last_late_check_status,
 *   last_late_check_loans_scanned, last_late_check_schedules_marked,
 *   last_late_check_loans_recovered, last_late_check_notifications_queued.
 *
 * NOT Auditable — these are noisy observability data, not configuration
 * decisions. If you need to audit who triggered a job, use loan_events
 * (per-loan) or laravel.log (job-level).
 */
class PlatformMetric extends Model
{
    protected $fillable = ['key', 'value', 'measured_at'];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
        ];
    }

    /**
     * Upsert a metric in one call. Stamps measured_at = now().
     *
     * Stored value is always a string; readers cast as needed.
     */
    public static function record(string $key, string $value): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'measured_at' => now()],
        );
    }

    /**
     * Read raw string; null if metric never recorded.
     */
    public static function read(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    /**
     * Read measured_at as Carbon; null if metric never recorded.
     */
    public static function measuredAt(string $key): ?\Illuminate\Support\Carbon
    {
        $row = static::where('key', $key)->first();
        return $row?->measured_at;
    }
}
