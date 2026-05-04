<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ultimate Beneficial Owners (UDB) of a legal-entity user, per ZMIP чл. 59
     * (Bulgarian AML Act). Required for any natural person who:
     *   - directly or indirectly owns ≥ 25% of the entity, OR
     *   - exercises control through other means (contract, voting, etc.)
     *
     * At registration we collect the minimum identifying data; KYC documents
     * (ID copy + UBO declaration) are uploaded later from "Профил".
     */
    public function up(): void
    {
        Schema::create('beneficial_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_profile_id')->constrained()->cascadeOnDelete();

            // Identity (encrypted — these are personal data of natural persons)
            $table->text('full_name'); // encrypted — three names as provided
            $table->text('national_id')->nullable(); // encrypted — EGN for BG, LNCH/passport for non-BG
            $table->string('date_of_birth')->nullable(); // ISO date, used when no national_id (foreigners)
            $table->string('nationality', 2)->default('BG'); // ISO 3166-1 alpha-2

            // Control
            $table->decimal('ownership_percent', 5, 2); // 0.00 - 100.00
            $table->string('control_type', 16); // direct | indirect | other

            // PEP status (separate from representative's PEP — UBOs have their own)
            $table->boolean('pep_status')->default(false);
            $table->text('pep_details')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficial_owners');
    }
};
