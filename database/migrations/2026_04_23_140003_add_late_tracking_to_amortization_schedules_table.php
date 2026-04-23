<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds late-state tracking to amortization_schedules.
 *
 *  became_late_at — set when this installment was first marked status='late'.
 *                   Used by the recovery rule "auto-recover only if paid_at
 *                   >= became_late_at" — guarantees we don't auto-recover a
 *                   loan whose admin manually backfilled `paid` to fix data.
 *
 *  days_late      — snapshot value updated daily by loans:process-late.
 *                   = (run_date - due_date) at command run time.
 *                   Stored (not computed-on-read) because we want sortable,
 *                   indexable values in admin tables AND we want the
 *                   audit-friendly snapshot.
 *                   "Live current days late" is still computable as
 *                   `today - due_date` if needed.
 *                   CHECK constraint forbids negative values (defense in
 *                   depth — application would never write negative, but DB
 *                   says no anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->timestamp('became_late_at')->nullable()->after('paid_at');
            $table->unsignedInteger('days_late')->default(0)->after('became_late_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('
                ALTER TABLE amortization_schedules
                ADD CONSTRAINT chk_amortization_schedules_days_late_non_negative
                CHECK (days_late >= 0)
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE amortization_schedules DROP CONSTRAINT chk_amortization_schedules_days_late_non_negative');
        }
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropColumn(['became_late_at', 'days_late']);
        });
    }
};
