<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F2 Step 1 — seed 3 platform_settings entries + defense-in-depth CHECKs.
 *
 * Settings:
 *   buyback_default_coverage       — 'principal_plus_interest' default.
 *                                    Per-originator override via
 *                                    originators.buyback_coverage.
 *   buyback_default_trigger_days   — 60 days. Industry standard for P2P
 *                                    (Mintos / PeerBerry pattern).
 *   buyback_check_enabled          — Master switch for the daily detection
 *                                    cron. Admin can STILL execute buyback
 *                                    manually from the Queue when this is
 *                                    false — it only pauses detection.
 *
 * Defense-in-depth CHECKs mirror the F1 `chk_grace_period_days_range`
 * pattern (see 2026_04_23_140000). Each CHECK is keyed to the specific
 * platform_settings row — other rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insert([
            [
                'key' => 'buyback_default_coverage',
                'value' => 'principal_plus_interest',
                'type' => 'string',
                'description' => "Platform-wide default buyback coverage when an originator doesn't override. Values: 'principal_only' | 'principal_plus_interest'.",
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'buyback_default_trigger_days',
                'value' => '60',
                'type' => 'int',
                'description' => 'Platform-wide default days-since-became_late before a late loan becomes buyback-eligible. Per-originator override via originators.buyback_trigger_days.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'buyback_check_enabled',
                'value' => 'true',
                'type' => 'bool',
                'description' => 'Master switch for the daily loans:detect-buyback-eligible cron. When false, no automatic detection — admin can still execute buyback manually from the Queue.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE platform_settings
                ADD CONSTRAINT chk_buyback_default_coverage
                CHECK (
                    `key` <> 'buyback_default_coverage'
                    OR value IN ('principal_only', 'principal_plus_interest')
                )
            ");

            DB::statement("
                ALTER TABLE platform_settings
                ADD CONSTRAINT chk_buyback_default_trigger_days_range
                CHECK (
                    `key` <> 'buyback_default_trigger_days'
                    OR (value REGEXP '^[0-9]+$' AND CAST(value AS UNSIGNED) BETWEEN 0 AND 365)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE platform_settings DROP CONSTRAINT chk_buyback_default_coverage');
            DB::statement('ALTER TABLE platform_settings DROP CONSTRAINT chk_buyback_default_trigger_days_range');
        }
        DB::table('platform_settings')->whereIn('key', [
            'buyback_default_coverage',
            'buyback_default_trigger_days',
            'buyback_check_enabled',
        ])->delete();
    }
};
