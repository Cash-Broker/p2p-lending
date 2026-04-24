<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F4 Step 1 — seed 2 platform_settings rows for the fees infrastructure
 * feature flag + flat amount, plus a defense-in-depth CHECK on the
 * amount range.
 *
 * Per client decision Q5 — "infrastructure ready, disabled by default
 * in v1":
 *
 *   fees_withdrawal_enabled  — master toggle. Default FALSE. When true,
 *                              WithdrawalService::approve() charges a
 *                              flat fee at admin-approval time (see
 *                              DECISIONS.md F4-01).
 *   fees_withdrawal_amount   — flat per-withdrawal fee in EUR, stored
 *                              as a 2-decimal string. Default '2.50'
 *                              — matches the historical FAQ/chatbot
 *                              copy that was amended to "безплатно" in
 *                              commit f301c19 pending this infra.
 *                              CHECK enforces 0 ≤ value ≤ 100 (defense
 *                              in depth; psychological cap — any flat
 *                              withdrawal fee above 100 € is almost
 *                              certainly a config typo).
 *
 * F4 intentionally seeds ONLY these two keys. Future fee categories
 * (origination, service, late, early-repayment, inactivity) get their
 * own migration + CHECK + Filament field when the business enables
 * each one. Keeps v1 scope narrow per Q1 decision.
 *
 * Per-key CHECK pattern mirrors F1 (`chk_grace_period_days_range`) and
 * F2 (`chk_buyback_default_trigger_days_range`) — an outer
 * `key <> 'x' OR ...` so other rows are unaffected. SQLite skipped
 * because REGEXP inside a CHECK is not supported in all builds; SQLite
 * is test-suite-only and model/Filament validation is the only line
 * there, which is acceptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insert([
            [
                'key' => 'fees_withdrawal_enabled',
                'value' => 'false',
                'type' => 'bool',
                'description' => 'Master toggle for the flat per-withdrawal fee. When false, WithdrawalService::approve() charges no fee and the investor receives the full requested amount. Default false — flip to true ONLY after the Такси amount is configured AND the public FAQ/chatbot copy is amended to describe the concrete fee.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'fees_withdrawal_amount',
                'value' => '2.50',
                'type' => 'float',
                'description' => 'Flat withdrawal fee in EUR (2 decimal places). Charged once per withdrawal at admin-approval time. Range 0–100 (DB CHECK enforces). Matches historical "2.50 € минимална такса" copy from before commit f301c19.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE platform_settings
                ADD CONSTRAINT chk_fees_withdrawal_amount_range
                CHECK (
                    `key` <> 'fees_withdrawal_amount'
                    OR (value REGEXP '^[0-9]+([.][0-9]{1,2})?$'
                        AND CAST(value AS DECIMAL(6,2)) BETWEEN 0 AND 100)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE platform_settings DROP CONSTRAINT chk_fees_withdrawal_amount_range');
        }
        DB::table('platform_settings')->whereIn('key', [
            'fees_withdrawal_enabled',
            'fees_withdrawal_amount',
        ])->delete();
    }
};
