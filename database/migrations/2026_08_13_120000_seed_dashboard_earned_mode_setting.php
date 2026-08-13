<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the display-mode setting for the dashboard «Спечелени» ticker
 * (client request 2026-08-13, Reni Telegram): the accrued-by-schedule
 * interest counter shown top-right on the investor dashboard.
 *
 * Both presentation variants shipped at once so the client can pick
 * («двата варианта наведнъж»):
 *
 *   daily — the amount grows once per full elapsed day («всеки ден да
 *           вижда как му тропат по 33 евро»). Default.
 *   live  — a real-time ticker advancing every second at the schedule's
 *           per-second pace («брояч, който превърта на минута»).
 *
 * Purely presentational — both variants read the same schedule-derived
 * reference number; no money moves either way. Per-key CHECK pattern
 * mirrors F1/F2/F4 seeds (outer `key <> 'x' OR …` so other rows are
 * unaffected; sqlite skipped — MySQL is the constraint-bearing target).
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE platform_settings DROP CONSTRAINT chk_dashboard_earned_mode');
        }
        DB::table('platform_settings')->where('key', 'dashboard_earned_mode')->delete();
    }
};
