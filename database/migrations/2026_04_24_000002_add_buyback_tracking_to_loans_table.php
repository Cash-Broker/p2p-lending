<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2 Step 1 — buyback tracking on the loans table.
 *
 *   buyback_eligible_at       — Set by daily `loans:detect-buyback-eligible`
 *                               cron when a loan crosses the trigger
 *                               threshold. Cleared by admin "Reactivate"
 *                               (un-dismiss) action OR by BuybackExecution-
 *                               Service at execution time (left as-is per
 *                               Q23 — kept for audit; `bought_back_at` is
 *                               the terminal marker).
 *
 *   bought_back_at            — Set by BuybackExecutionService on admin
 *                               Execute click. Terminal state per Q3 —
 *                               never cleared.
 *
 *   buyback_dismissed_at      — Set by admin "Dismiss" in the Queue.
 *                               Detection cron SKIPS rows with dismissed_at
 *                               set (unless --force). Admin can reactivate
 *                               by clearing.
 *
 *   buyback_dismissed_reason  — Admin note on dismissal. Required at the
 *                               Filament form level (not DB) so admin must
 *                               justify; NULL allowed at DB layer for
 *                               migration back-fill flexibility.
 *
 *   buyback_dismissed_by      — FK to users.id. Accountability per Q23 —
 *                               every dismissal carries a signature.
 *                               RESTRICT on delete (default Laravel
 *                               behaviour via constrained()), mirroring
 *                               loan_events.triggered_by_user_id —
 *                               AccountDeletionService anonymises rather
 *                               than hard-deletes, so RESTRICT never trips
 *                               in normal flow.
 *
 * Index on `buyback_eligible_at`:
 *   Cron query path 1 — "find newly eligible": WHERE status='late' AND
 *   buyback_eligible_at IS NULL. NULL side is most rows.
 *   Queue display path — WHERE buyback_eligible_at IS NOT NULL AND
 *   buyback_dismissed_at IS NULL AND bought_back_at IS NULL.
 *   Index serves both by selectivity on buyback_eligible_at.
 *
 * No CHECK on cross-field timestamp ordering (e.g. bought_back_at >
 * buyback_eligible_at) — enforced at the service layer; overly strict DB
 * constraints would reject legitimate admin data-fix-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('buyback_eligible_at')->nullable()->after('became_late_at');
            $table->timestamp('bought_back_at')->nullable()->after('buyback_eligible_at');
            $table->timestamp('buyback_dismissed_at')->nullable()->after('bought_back_at');
            $table->string('buyback_dismissed_reason', 255)->nullable()->after('buyback_dismissed_at');
            $table->foreignId('buyback_dismissed_by')
                ->nullable()
                ->after('buyback_dismissed_reason')
                ->constrained('users'); // RESTRICT on delete (Laravel default)

            $table->index('buyback_eligible_at');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['buyback_eligible_at']);
            $table->dropForeign(['buyback_dismissed_by']);
            $table->dropColumn([
                'buyback_eligible_at',
                'bought_back_at',
                'buyback_dismissed_at',
                'buyback_dismissed_reason',
                'buyback_dismissed_by',
            ]);
        });
    }
};
