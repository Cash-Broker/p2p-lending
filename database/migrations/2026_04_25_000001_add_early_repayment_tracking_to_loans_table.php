<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3 Step 1 — early-repayment tracking on the loans table.
 *
 * F3 uses SCHEDULE-BOUNDARY interest semantics (Q3 approved), mirroring
 * F2's buyback pattern. No day-count accrual anywhere in the codebase —
 * just two columns to mark the terminal early-repayment event:
 *
 *   early_repaid_at          — timestamp. Set by EarlyRepaymentExecution-
 *                              Service::execute() at admin Execute click.
 *                              Distinguishes a terminal `repaid` loan that
 *                              was CLOSED EARLY from one that ran to term
 *                              (for UI differentiation + investor timeline).
 *                              NULL = loan was/is NOT early-repaid.
 *
 *   early_repayment_amount   — DECIMAL(12,2) NULL. Audit denormalisation
 *                              (Q6). Stores the total amount distributed
 *                              at execution time (principal + interest).
 *                              Per-investor detail lives in loan_events
 *                              metadata + the 2 Transaction rows per
 *                              investor. This column enables admin queries
 *                              like "total early-closed revenue this
 *                              quarter" without joining loan_events.
 *
 * CHECK constraint (MySQL only):
 *   early_repayment_amount IN [0, 10_000_000] when non-null. Defense
 *   in depth: the service writes positive values by construction
 *   (lower bound) AND the 10M ceiling is a psychological sanity-check
 *   alarm — any single loan repayment above 10M EUR is almost
 *   certainly a calculation bug, and the CHECK surfaces it loudly
 *   before the row is committed. Far above any realistic early
 *   repayment on this platform (loan amounts are typically < 50k EUR).
 *
 * NO cross-field CHECK (e.g. "early_repaid_at IS NULL XOR status = repaid")
 * — cross-field invariants are enforced at the service layer (F2
 * precedent). Overly strict DB constraints would reject legitimate
 * admin data-fix-ups.
 *
 * No index on either column — queries are rare, and admin already
 * filters by `status` which is indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('early_repaid_at')->nullable()->after('buyback_dismissed_by');
            $table->decimal('early_repayment_amount', 12, 2)->nullable()->after('early_repaid_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE loans
                ADD CONSTRAINT chk_loans_early_repayment_amount_range
                CHECK (
                    early_repayment_amount IS NULL
                    OR (early_repayment_amount >= 0 AND early_repayment_amount <= 10000000)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE loans DROP CONSTRAINT chk_loans_early_repayment_amount_range');
        }
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['early_repaid_at', 'early_repayment_amount']);
        });
    }
};
