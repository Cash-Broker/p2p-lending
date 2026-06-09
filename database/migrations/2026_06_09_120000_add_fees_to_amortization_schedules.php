<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loan-overhaul Phase 5 — "такси и комисионни" column.
     *
     * Platform/originator revenue, shown on the schedule for transparency.
     * Deliberately SEPARATE from `total` (which stays principal + interest) and
     * NOT part of investor distribution — so the Σprincipal == investable
     * invariant and the existing payout math are untouched.
     */
    public function up(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->decimal('fees', 10, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropColumn('fees');
        });
    }
};
