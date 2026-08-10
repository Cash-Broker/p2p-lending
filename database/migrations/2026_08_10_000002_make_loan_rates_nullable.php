<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boss 2026-08-10: the loan-level «Доходност (%)» and «Лихва
 * кредитополучател (%)» inputs come OFF the loan form — the three offer
 * rates are the real product. The columns stay (legacy loans and the
 * admin-only ГПР/Марж math read them) but must accept NULL for loans
 * created without them. Every consumer is already null-safe or
 * legacy-only (APRCalculatorService → «—»; AmortizationService is guarded
 * before generating a legacy schedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('interest_rate', 5, 2)->nullable()->change();
            $table->decimal('interest_rate_annual', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // NULLs would violate NOT NULL — backfill zeros first.
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('interest_rate', 5, 2)->nullable(false)->default(0)->change();
            $table->decimal('interest_rate_annual', 5, 2)->nullable(false)->default(0)->change();
        });
    }
};
