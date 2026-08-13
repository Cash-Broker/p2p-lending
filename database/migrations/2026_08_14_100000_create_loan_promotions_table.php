<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flash promo campaigns (client request 2026-08-14, Reni Telegram):
 * «динамичен панел... кредити с висока доходност за събиране на пари за
 * кратко време», «авансово изплащане на бонус и офертата валидна за 60
 * мин», «и едно звънче там».
 *
 * A promotion pins a LIMITED-TIME window on one loan: investing during the
 * window pays the investor an UPFRONT bonus (TYPE_BONUS — same ledger
 * mechanics as the admin «Начисли бонус», excluded from bank-statement
 * reconciliation, LEDGER_MAP-covered) immediately into `available`.
 *
 * Guardrails at the DB level (defense in depth, per platform convention):
 *   • bonus_percent ∈ (0, 10] — an upfront bonus above 10 % of the invested
 *     amount is almost certainly a fat-finger, not a promo;
 *   • bonus_paid_total ≥ 0 and, when budget_cap is set, never above it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->decimal('bonus_percent', 5, 2);
            $table->decimal('budget_cap', 12, 2)->nullable();
            $table->decimal('bonus_paid_total', 12, 2)->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ends_at', 'cancelled_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE loan_promotions ADD CONSTRAINT chk_promo_bonus_percent CHECK (bonus_percent > 0 AND bonus_percent <= 10)');
            DB::statement('ALTER TABLE loan_promotions ADD CONSTRAINT chk_promo_paid_total CHECK (bonus_paid_total >= 0 AND (budget_cap IS NULL OR bonus_paid_total <= budget_cap))');
            DB::statement('ALTER TABLE loan_promotions ADD CONSTRAINT chk_promo_window CHECK (ends_at > starts_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_promotions');
    }
};
