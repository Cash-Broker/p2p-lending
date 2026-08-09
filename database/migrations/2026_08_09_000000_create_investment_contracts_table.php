<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-investment loan agreement («Договор за целеви паричен заем»).
 *
 * One row per (offer-based) investment, created inside the SAME DB
 * transaction as the investment itself. The row is a frozen snapshot of
 * everything the generated contract PDF needs — party identification,
 * amount/rate in words, the projected repayment schedule — so the PDF can
 * be re-rendered deterministically at any later date without being
 * affected by subsequent profile edits, offer edits or platform-setting
 * changes.
 *
 * The row doubles as the click-wrap consent evidence (client decision,
 * 2026-08-09: no signatures — the invest click itself IS the acceptance,
 * recorded with timestamp + IP + user agent, like accepting the terms of
 * service).
 *
 * `party_snapshot` holds decrypted-at-build-time PII (names, ЕГН/ЕИК,
 * address) and is therefore stored via the `encrypted:array` cast,
 * consistent with LegalEntityProfile/BeneficialOwner treatment.
 * `terms_snapshot` holds only commercial terms (amounts, rates, schedule)
 * and stays plain JSON for admin queryability.
 *
 * No PDF file is stored on disk — rendering happens on demand from the
 * snapshot. No orphaned files, no extra PII surface, GDPR-simple.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_contracts', function (Blueprint $table) {
            $table->id();
            // RESTRICT (constrained() default), NOT cascade: this row is
            // legal acceptance evidence — deleting an investment/user/loan
            // must fail loudly rather than silently destroy it. (GDPR
            // deletion anonymizes users, it never deletes rows.)
            $table->foreignId('investment_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('loan_id')->constrained();
            $table->text('party_snapshot');
            $table->json('terms_snapshot');
            $table->string('template_version', 8)->default('v1');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'accepted_at']);
            $table->index(['loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_contracts');
    }
};
