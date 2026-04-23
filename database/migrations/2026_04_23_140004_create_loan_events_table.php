<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loan Events — append-only lifecycle log per loan.
 *
 * Distinct from `audit_logs` (which captures every model field mutation,
 * is noisy, and is keyed by model_type/model_id). loan_events is a focused,
 * denormalised stream of business-meaningful state transitions, intended
 * to be the primary view for ops + admin timeline UI.
 *
 * Append-only enforced by DB triggers (UPDATE/DELETE blocked) — same
 * pattern as the transactions table.
 *
 * The event_type enum is intentionally PRE-EXPANDED in this F1 migration
 * so future phases (F2 buyback, F3 early repayment, F4 fees) don't need
 * a schema change to start writing their event types. F1 only writes
 * went_late / recovered_from_late / went_default; the others sit in the
 * enum unused until later phases.
 *
 * triggered_by + triggered_by_user_id:
 *   triggered_by = 'system' → user_id is null (cron command wrote this)
 *   triggered_by = 'admin'  → user_id is the admin who took the action
 */
return new class extends Migration
{
    private const EVENT_TYPES = [
        'went_late',
        'recovered_from_late',
        'went_default',
        'buyback_triggered',         // placeholder for Phase F2
        'buyback_completed',         // placeholder for Phase F2
        'early_repayment_requested', // placeholder for Phase F3
        'early_repayment_completed', // placeholder for Phase F3
        'fee_applied',               // placeholder for Phase F4
        'status_changed',            // generic fallback for transitions not covered above
    ];

    public function up(): void
    {
        Schema::create('loan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->string('triggered_by', 16); // 'system' or 'admin'
            // ON DELETE RESTRICT (Laravel default) — admin users with loan_events
            // cannot be hard-deleted. AccountDeletionService anonymises rather
            // than deletes, so this restriction never trips in normal flow.
            // We deliberately don't use nullOnDelete because the consistency
            // CHECK below requires user_id to be present whenever
            // triggered_by='admin' — a SET NULL action would silently violate
            // that and MySQL forbids the combination outright.
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['loan_id', 'occurred_at']);
            $table->index('event_type');
            $table->index('occurred_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            // Enforce event_type enum at DB level — defense in depth against
            // typos / SQL-injected garbage.
            $allowed = "'" . implode("','", self::EVENT_TYPES) . "'";
            DB::statement("
                ALTER TABLE loan_events
                ADD CONSTRAINT chk_loan_events_event_type
                CHECK (event_type IN ({$allowed}))
            ");

            // Enforce triggered_by enum.
            DB::statement("
                ALTER TABLE loan_events
                ADD CONSTRAINT chk_loan_events_triggered_by
                CHECK (triggered_by IN ('system', 'admin'))
            ");

            // Enforce consistency: triggered_by='admin' requires user_id; 'system' forbids it.
            DB::statement("
                ALTER TABLE loan_events
                ADD CONSTRAINT chk_loan_events_triggered_by_consistency
                CHECK (
                    (triggered_by = 'system' AND triggered_by_user_id IS NULL)
                 OR (triggered_by = 'admin'  AND triggered_by_user_id IS NOT NULL)
                )
            ");

            // Enforce status pair consistency: either BOTH statuses set (and
            // different — no self-transitions), or NEITHER (generic non-transition
            // events such as fee_applied or buyback_completed). Forbids the
            // half-set case (only from or only to) which would be malformed.
            DB::statement("
                ALTER TABLE loan_events
                ADD CONSTRAINT chk_loan_events_status_pair
                CHECK (
                    (from_status IS NULL AND to_status IS NULL)
                 OR (from_status IS NOT NULL AND to_status IS NOT NULL
                     AND from_status <> to_status)
                )
            ");

            // Append-only — block UPDATE / DELETE at the DB level. Same pattern
            // as transactions (see 2026_03_29_110003_add_transaction_immutability_triggers).
            DB::unprepared("
                CREATE TRIGGER prevent_loan_event_update
                BEFORE UPDATE ON loan_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'loan_events are immutable and cannot be updated';
                END
            ");

            DB::unprepared("
                CREATE TRIGGER prevent_loan_event_delete
                BEFORE DELETE ON loan_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'loan_events are immutable and cannot be deleted';
                END
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_loan_event_update');
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_loan_event_delete');
            DB::statement('ALTER TABLE loan_events DROP CONSTRAINT chk_loan_events_event_type');
            DB::statement('ALTER TABLE loan_events DROP CONSTRAINT chk_loan_events_triggered_by');
            DB::statement('ALTER TABLE loan_events DROP CONSTRAINT chk_loan_events_triggered_by_consistency');
            DB::statement('ALTER TABLE loan_events DROP CONSTRAINT chk_loan_events_status_pair');
        }
        Schema::dropIfExists('loan_events');
    }
};
