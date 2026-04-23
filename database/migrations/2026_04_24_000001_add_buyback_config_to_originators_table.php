<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 Step 1 — per-originator buyback configuration.
 *
 *   buyback_coverage       — Enum-as-string, CHECK-enforced, NULL allowed.
 *                            Values: 'principal_only' | 'principal_plus_interest'.
 *                            NULL → fall back to platform_settings
 *                            .buyback_default_coverage.
 *
 *   buyback_trigger_days   — Days after loan.became_late_at before
 *                            eligibility fires. NULL → fall back to
 *                            platform_settings.buyback_default_trigger_days
 *                            (60 by default — industry standard for P2P).
 *                            CHECK 0..365 — defense in depth.
 *
 * Design: the existing `buyback` boolean stays as the master switch
 * (Q12 approved). Eligibility service filters `buyback = true` first.
 * We intentionally DO NOT add a cross-field CHECK tying coverage/trigger
 * to buyback=true — churn-prone when admin toggles buyback off and on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('originators', function (Blueprint $table) {
            $table->string('buyback_coverage', 32)->nullable()->after('buyback');
            $table->unsignedSmallInteger('buyback_trigger_days')->nullable()->after('buyback_coverage');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE originators
                ADD CONSTRAINT chk_originators_buyback_coverage
                CHECK (
                    buyback_coverage IS NULL
                    OR buyback_coverage IN ('principal_only', 'principal_plus_interest')
                )
            ");

            DB::statement("
                ALTER TABLE originators
                ADD CONSTRAINT chk_originators_buyback_trigger_days
                CHECK (
                    buyback_trigger_days IS NULL
                    OR (buyback_trigger_days >= 0 AND buyback_trigger_days <= 365)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE originators DROP CONSTRAINT chk_originators_buyback_coverage');
            DB::statement('ALTER TABLE originators DROP CONSTRAINT chk_originators_buyback_trigger_days');
        }
        Schema::table('originators', function (Blueprint $table) {
            $table->dropColumn(['buyback_coverage', 'buyback_trigger_days']);
        });
    }
};
