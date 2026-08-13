<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the kill switch for the investor weekly earnings email (client
 * request 2026-08-13, Reni: «седмичен бюлетин — тази седмица спечели X
 * сума… да си ги има постоянно», delivered by email). Bool, default ON.
 * Same admin-togglable pattern as late_check_enabled / buyback_check_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insert([
            'key' => 'investor_weekly_email_enabled',
            'value' => 'true',
            'type' => 'bool',
            'description' => 'Седмичен имейл до инвеститорите «Тази седмица спечели X €» (понеделник 09:30). Изключването спира изпращането при следващото изпълнение на investors:weekly-earnings; не трие нищо.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'investor_weekly_email_enabled')->delete();
    }
};
