<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loan-overhaul Phase 2 — "available for investment" cap.
     *
     * A loan's `amount` is the nominal loan size; `investable_amount` is how much
     * is actually offered to platform investors (e.g. amount 10000, investable
     * 7000). Investors fund up to investable_amount, and — per the client's
     * "easy variant" decision — the on-platform amortization schedule amortizes
     * investable_amount, so investors are repaid exactly the capital they put in.
     *
     * Nullable + backfilled to `amount`: every in-flight loan keeps identical
     * behavior (investable == amount), and Loan::investableAmount() falls back to
     * `amount` whenever the column is null, so the audit fixtures are unaffected.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('investable_amount', 12, 2)->nullable()->after('amount');
        });

        DB::table('loans')->whereNull('investable_amount')
            ->update(['investable_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('investable_amount');
        });
    }
};
