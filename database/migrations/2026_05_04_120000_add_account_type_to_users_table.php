<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discriminate between natural-person investors ("individual") and
     * legal-entity investors ("legal_entity"). For legal_entity rows, the
     * extended company data and beneficial owners live in dedicated tables
     * (`legal_entity_profiles`, `beneficial_owners`).
     *
     * Default 'individual' so existing rows keep the same shape and we don't
     * need to backfill — the existing UI flow continues to work unchanged.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 20)
                ->default('individual')
                ->after('role'); // 'individual' | 'legal_entity'
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
