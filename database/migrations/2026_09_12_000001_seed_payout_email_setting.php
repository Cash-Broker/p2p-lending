<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the kill switch for the investor «получено плащане» mail + bell sent
 * right after each scheduled payout commits (2026-09-12, Yordan: «хубаво е
 * да знаят»). Bool, default ON. Same admin-togglable pattern as
 * push_payout_digest_enabled / investor_weekly_email_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insert([
            'key' => 'payout_email_enabled',
            'value' => 'true',
            'type' => 'bool',
            'description' => 'Имейл + известие в звънчето до инвеститора «Получено плащане X € по кредит #N» веднага след всяко плащане по график (04:00 крон или «Пусни плащане сега»). Изключването спира изпращането от следващото плащане; парите се движат както досега.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'payout_email_enabled')->delete();
    }
};
