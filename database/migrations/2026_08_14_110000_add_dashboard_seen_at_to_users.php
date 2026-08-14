<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement pack (Yordan/Reni 2026-08-14): «Докато те нямаше…» needs to
 * know when the investor LAST saw the dashboard. Updated on every dashboard
 * load AFTER the previous value is read for the delta window. Nullable —
 * never-seen users simply get no welcome-back banner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('dashboard_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_seen_at');
        });
    }
};
