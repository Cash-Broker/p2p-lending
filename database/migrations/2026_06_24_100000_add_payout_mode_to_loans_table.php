<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-loan payout trigger mode (boss feature, 2026-06-23).
 *
 *   manual    — admin posts the scheduled accrual with a button (current behavior).
 *   automatic — a timer accrues each investor's due amount on the schedule date.
 *
 * Default 'manual' so every EXISTING loan keeps behaving exactly as today until
 * an admin flips it. This is an OPERATIONAL setting, NOT a financial term, so it
 * is deliberately editable after draft (admin must be able to switch already
 * uploaded loans) — i.e. it is NOT added to Loan::IMMUTABLE_AFTER_DRAFT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('payout_mode', 16)->default('manual')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('payout_mode');
        });
    }
};
