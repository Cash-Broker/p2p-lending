<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Settings — configurable values that operators can change at runtime.
 *
 * Distinct from `platform_metrics` (observed state). A setting is something
 * an admin DECIDES; a metric is something the system OBSERVES.
 *
 * Storage shape: simple key/value with a `type` column for round-tripping
 * (the eloquent cast on the model uses `type` to coerce `value` back to
 * the right PHP type). All settings are auditable via `Auditable` trait
 * on the PlatformSetting model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            // Type coercion hint — model casts `value` back to PHP type on read.
            // Allowed: int, float, string, bool, json
            $table->string('type', 16);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Defense-in-depth: enforce grace_period_days range at the DB layer
        // so a misbehaving model/admin/SQL injection cannot push it out of
        // the 0–30 day range. Skipped under SQLite (CHECK with REGEXP not
        // supported) — model-level validation is the only line there.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE platform_settings
                ADD CONSTRAINT chk_grace_period_days_range
                CHECK (
                    `key` <> 'grace_period_days'
                    OR (`value` REGEXP '^[0-9]+$' AND CAST(`value` AS UNSIGNED) BETWEEN 0 AND 30)
                )
            ");
        }

        // Seed initial settings.
        DB::table('platform_settings')->insert([
            [
                'key' => 'grace_period_days',
                'value' => '10',
                'type' => 'int',
                'description' => 'Number of days after a schedule due_date before the installment is marked late. Range 0–30.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'late_check_enabled',
                'value' => 'true',
                'type' => 'bool',
                'description' => 'Master switch for the daily loans:process-late command. Set false to pause automation (e.g. during data fix-ups).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE platform_settings DROP CONSTRAINT chk_grace_period_days_range');
        }
        Schema::dropIfExists('platform_settings');
    }
};
