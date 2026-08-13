<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the dashboard_earned_mode setting (seeded 2026-08-13, removed the
 * SAME day by client decision): Reni chose to show BOTH paces at once —
 * per-second big number + daily/hourly badges («хем на час, хем на ден»,
 * «да няма нужда да ги избираш от админа») — so the daily/live variant
 * switch has nothing left to control. A stray settings row with no reader
 * would violate the PlatformSettingResource contract, hence removal rather
 * than abandonment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE platform_settings DROP CONSTRAINT chk_dashboard_earned_mode');
        }

        DB::table('platform_settings')->where('key', 'dashboard_earned_mode')->delete();
    }

    public function down(): void
    {
        DB::table('platform_settings')->insert([
            'key' => 'dashboard_earned_mode',
            'value' => 'daily',
            'type' => 'string',
            'description' => 'Как се показва зеленият брояч «Спечелени» на таблото на инвеститора: daily = сумата нараства веднъж на ден; live = брояч в реално време по погасителния план. Само визуализация — не движи пари.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE platform_settings
                ADD CONSTRAINT chk_dashboard_earned_mode
                CHECK (`key` <> 'dashboard_earned_mode' OR value IN ('daily', 'live'))
            ");
        }
    }
};
