<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decision: registration captures only company name + EIK + contact-person
     * name + email + phone + password. AML data (address, representative role
     * + ID, PEP status, source of funds) is deferred to a post-registration
     * KYC workflow analogous to the individual ID-document upload.
     *
     * The columns stay in the table so the deferred workflow has a place to
     * land — they're just made nullable here so registration can complete
     * without supplying them. UBO collection (`beneficial_owners` table)
     * likewise remains; the table will be populated in the deferred flow.
     */
    public function up(): void
    {
        Schema::table('legal_entity_profiles', function (Blueprint $table) {
            $table->string('legal_form', 20)->nullable()->change();
            $table->string('address_country', 2)->default('BG')->nullable()->change();
            $table->string('address_city')->nullable()->change();
            $table->string('address_postcode', 12)->nullable()->change();
            $table->string('address_street')->nullable()->change();
            $table->string('company_email')->nullable()->change();
            $table->string('company_phone', 32)->nullable()->change();
            $table->string('representative_role', 32)->nullable()->change();
            $table->string('source_of_funds', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Best-effort rollback: any rows registered under the simplified flow
        // will have NULLs in these columns. Backfill with placeholder defaults
        // so the NOT NULL re-tightening doesn't fail. The placeholders are
        // obviously fake — the deferred KYC workflow is supposed to overwrite.
        DB::table('legal_entity_profiles')->whereNull('legal_form')->update(['legal_form' => 'DRUGO']);
        DB::table('legal_entity_profiles')->whereNull('address_country')->update(['address_country' => 'BG']);
        DB::table('legal_entity_profiles')->whereNull('address_city')->update(['address_city' => 'TBD']);
        DB::table('legal_entity_profiles')->whereNull('address_postcode')->update(['address_postcode' => '0000']);
        DB::table('legal_entity_profiles')->whereNull('address_street')->update(['address_street' => 'TBD']);
        DB::table('legal_entity_profiles')->whereNull('company_email')->update(['company_email' => 'tbd@example.invalid']);
        DB::table('legal_entity_profiles')->whereNull('company_phone')->update(['company_phone' => 'TBD']);
        DB::table('legal_entity_profiles')->whereNull('representative_role')->update(['representative_role' => 'upravitel']);
        DB::table('legal_entity_profiles')->whereNull('source_of_funds')->update(['source_of_funds' => 'other']);

        Schema::table('legal_entity_profiles', function (Blueprint $table) {
            $table->string('legal_form', 20)->nullable(false)->change();
            $table->string('address_country', 2)->default('BG')->nullable(false)->change();
            $table->string('address_city')->nullable(false)->change();
            $table->string('address_postcode', 12)->nullable(false)->change();
            $table->string('address_street')->nullable(false)->change();
            $table->string('company_email')->nullable(false)->change();
            $table->string('company_phone', 32)->nullable(false)->change();
            $table->string('representative_role', 32)->nullable(false)->change();
            $table->string('source_of_funds', 32)->nullable(false)->change();
        });
    }
};
