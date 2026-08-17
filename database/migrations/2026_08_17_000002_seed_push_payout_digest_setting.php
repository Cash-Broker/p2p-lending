<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the kill switch for the morning Web Push payout digest (2026-08-17,
 * Yordan sign-off — «събуждаш се с пари» push at 09:05 batching the 04:00
 * payout run). Bool, default ON. Same admin-togglable pattern as
 * late_check_enabled / buyback_check_enabled / investor_weekly_email_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insert([
            'key' => 'push_payout_digest_enabled',
            'value' => 'true',
            'type' => 'bool',
            'description' => 'Сутрешно пуш известие до инвеститорите «Получихте X € лихва» (09:05, батчва нощния payout run). Изключването спира изпращането при следващото изпълнение на push:payout-digest; не трие нищо.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'push_payout_digest_enabled')->delete();
    }
};
