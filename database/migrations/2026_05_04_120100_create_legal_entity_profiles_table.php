<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per legal-entity user (1:1 with `users` where account_type =
     * 'legal_entity'). Holds company identity + the registering
     * representative's role + AML declarations.
     *
     * EIK and VAT are stored encrypted (AES-256 via `encrypted` cast) per
     * the platform's privacy boundary (sec. 5.3.1 of the legal documentation).
     * The plain-text companies are nevertheless reconstructable by app code
     * after decryption, which is acceptable — the threat model we're defending
     * against is "stolen DB backup without app key", not "compromised app".
     */
    public function up(): void
    {
        Schema::create('legal_entity_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Company identity
            $table->string('legal_name'); // не е чувствително — фирменото име е публично в ТР
            $table->string('legal_form', 20); // EOOD | OOD | AD | EAD | ADSITZ | ET | KOOPERATSIYA | DRUGO
            $table->text('eik');           // encrypted — 9 or 13 digits
            $table->text('vat_number')->nullable(); // encrypted — BG + 9-13 digits, null if not VAT-registered

            // Registered office address
            $table->string('address_country', 2)->default('BG'); // ISO 3166-1 alpha-2
            $table->string('address_city');
            $table->string('address_postcode', 12);
            $table->string('address_street');

            // Contact
            $table->string('company_email');
            $table->string('company_phone', 32);

            // Representative — the natural person filling the form
            $table->string('representative_role', 32); // upravitel | prokurist | upalnomoshteno
            $table->text('representative_egn')->nullable();   // encrypted — for BG citizens
            $table->string('representative_dob')->nullable(); // for foreigners (encrypted? minor PII; keep plain for now and document)

            // AML declarations
            $table->boolean('pep_status')->default(false);
            $table->text('pep_details')->nullable(); // free-text when pep_status=true
            $table->string('source_of_funds', 32);   // business_income | dividends | asset_sale | loan | other
            $table->text('source_of_funds_other')->nullable(); // free-text when source_of_funds=other

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_entity_profiles');
    }
};
