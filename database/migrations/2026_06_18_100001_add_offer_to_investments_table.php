<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which offer an investor chose, plus a snapshot of the rate +
 * payout type at invest time. The snapshot is the source of truth for that
 * investor's cash flow — editing the offer later (while still allowed) must
 * never rewrite an existing investor's terms.
 *
 * Nullable: every pre-existing investment keeps loan_offer_id = null and is
 * routed through the LEGACY per-loan amortization path, untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->foreignId('loan_offer_id')->nullable()->after('loan_id')
                ->constrained('loan_offers')->nullOnDelete();
            // Snapshots — frozen at invest time.
            $table->decimal('interest_rate', 5, 2)->nullable()->after('amount');
            $table->string('payout_type')->nullable()->after('interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_offer_id');
            $table->dropColumn(['interest_rate', 'payout_type']);
        });
    }
};
