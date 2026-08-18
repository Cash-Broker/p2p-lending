<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Early closure of an offer-based loan — full or PARTIAL (Reni 2026-08-18).
 *
 * The borrower pays back some (or all) of the principal ahead of plan, and the
 * platform must close the matching share of every investor's position on the
 * spot: «има клиенти които може предсрочно да закрият една част, т.е. ние
 * трябва да закрием на инвеститора, защото се набутваме с лихви иначе».
 *
 * One row per closure event. It exists for three reasons:
 *   • the borrower may close «колкото пъти иска», so every ledger reference
 *     needs a unique event id (`loan:{id}:closure:{closureId}:…`);
 *   • the ratio and the as-of date must be reproducible after the fact — the
 *     schedules they were computed from get rewritten by the closure itself;
 *   • it is the audit trail for money that left outside the payout engine.
 *
 * No new transaction types: the existing early_repayment_principal /
 * early_repayment_interest carry the money, so `LEDGER_MAP` is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_early_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();

            // What the investors got closed and paid, in total.
            $table->decimal('principal_amount', 12, 2);
            $table->decimal('interest_amount', 12, 2);
            // Share of the loan's outstanding principal this closure took out.
            // Scale 10 — the same precision the pro-rata split runs at.
            $table->decimal('ratio', 12, 10);
            $table->boolean('is_full');
            // Interest is priced to THIS day (30/360), so it is part of the
            // record: re-running the numbers tomorrow gives a different answer.
            $table->date('as_of');
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['loan_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('
                ALTER TABLE loan_early_closures
                ADD CONSTRAINT chk_loan_early_closures_amounts
                CHECK (principal_amount > 0 AND interest_amount >= 0 AND ratio > 0 AND ratio <= 1)
            ');
        }

        // A fourth schedule state: `closed`. The installment was cancelled by
        // an early closure — NOT received. It must never be `paid`: the
        // portfolio would claim money that never arrived, and the conditional
        // bonus counts paid installments, which Reni excluded for early
        // closures. The payout services already filter on pending/late, so a
        // closed row simply stops being picked up.
        Schema::table('investment_schedules', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE loan_early_closures DROP CONSTRAINT chk_loan_early_closures_amounts');
        }

        Schema::table('investment_schedules', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::dropIfExists('loan_early_closures');
    }
};
