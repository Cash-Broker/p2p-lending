<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `accrued` bucket — profit accrued on schedule but NOT yet released to
 * `available` (boss feature, 2026-06-23). It is the part of "текущо салдо"
 * (current balance = invested + accrued) that is not yet withdrawable, e.g.
 * the compounding interest of a capitalized offer before maturity.
 *
 * Because accrual happens on schedule regardless of whether the borrower has
 * actually paid, this bucket also doubles as the platform's EXPOSURE marker —
 * money promised to investors that may not yet have been received. Kept as a
 * first-class non-negative bucket so reconciliation can see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('accrued', 12, 2)->default(0)->after('invested');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_accrued_non_negative CHECK (accrued >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_accrued_non_negative');
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('accrued');
        });
    }
};
