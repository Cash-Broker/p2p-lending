<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loan-overhaul Phase 1 (borrower "general info" + co-debtor).
     *
     * - personal_id (ЕГН) becomes nullable so a borrower can be created inline
     *   from the loan form with only general info (the EGN field was removed
     *   from the UI). The encrypted column is kept — not dropped — so existing
     *   data and the encryption/redaction tests are untouched.
     * - co_borrower_id (съдлъжник): an optional second borrower on a loan, with
     *   its own anonymized profile surfaced to investors.
     */
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->text('personal_id')->nullable()->change();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('co_borrower_id')->nullable()->after('borrower_id')
                ->constrained('borrowers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('co_borrower_id');
        });

        // Re-tighten personal_id. Backfill any inline-created borrowers that
        // have a null EGN with a placeholder so the NOT NULL re-add succeeds.
        \Illuminate\Support\Facades\DB::table('borrowers')->whereNull('personal_id')
            ->update(['personal_id' => '']);

        Schema::table('borrowers', function (Blueprint $table) {
            $table->text('personal_id')->nullable(false)->change();
        });
    }
};
