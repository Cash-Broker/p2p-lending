<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-entered contract number on the loan (boss 2026-08-10: «искам аз
 * да изписвам номера на договора, а не автоматично системата — тези
 * номера ще съвпадат с номера на договора реално»). This is the REAL
 * credit-contract number from the paperwork, NOT the auto id and NOT the
 * investor's InvestmentContract.
 *
 * Nullable (existing loans have none; the admin fills it), unique when
 * present — two loans must not claim the same real contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('contract_number', 64)->nullable()->unique()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique(['contract_number']);
            $table->dropColumn('contract_number');
        });
    }
};
