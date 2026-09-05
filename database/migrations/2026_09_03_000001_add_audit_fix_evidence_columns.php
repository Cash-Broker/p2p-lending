<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-09-01 — package A4 (evidence & idempotency columns).
 *
 * ADDITIVE ONLY: new nullable columns and unique indexes. No data is moved or
 * rewritten, `transactions` / `wallets` / `LEDGER_MAP` / CHECKs / triggers are
 * untouched (those changes need explicit sign-off per CLAUDE.md). Safe to run
 * on a live database with open investments.
 *
 *   withdrawal_requests.idempotency_key  — PAY-03: a retried POST /withdrawal
 *       returns the same request instead of reserving the money twice.
 *   withdrawal_requests.fee_quoted       — PAY-24: the fee disclosed at request
 *       time is the fee charged at approval.
 *   withdrawal_requests.approved_by / approved_at / processed_by — PAY-15:
 *       structured «who approved / who sent the wire, and when» instead of the
 *       free-text admin_note; `processed_at` keeps its existing semantics.
 *   deposit_requests.approved_by         — PAY-15, same for deposit credits.
 *   loan_early_closures.request_token    — PAY-04: one admin submit = one
 *       closure; a replayed form token is refused.
 *   loan_early_closures.accrued_written_off — PAY-35: engine accrual the
 *       closure wrote off, so the event carries the number the investor sees.
 *   investment_contracts.template_hash   — PAY-48: sha256 of the Blade template
 *       at conclusion, so a later edit of the frozen v1 file is detectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('user_agent');
            // PAY-24 (owner 2026-09-03): the fee shown to the investor at request
            // time; approve() charges exactly this. NULL = request predates the
            // column → live quote (old behaviour, old rows only).
            $table->decimal('fee_quoted', 12, 2)->nullable()->after('amount');
            // Admin references are plain ids on purpose (no FK): every caller
            // passes the acting admin's id, and the evidence must outlive an
            // admin account that is later removed or anonymised.
            $table->unsignedBigInteger('approved_by')->nullable()->after('admin_note')->index();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('processed_by')->nullable()->after('processed_at')->index();
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable()->after('admin_note')->index();
        });

        Schema::table('loan_early_closures', function (Blueprint $table) {
            $table->string('request_token', 64)->nullable()->unique()->after('note');
            // Engine accrual the closure could not fund and wrote off
            // (PAY-35) — the only place the amount survives per event.
            $table->decimal('accrued_written_off', 12, 2)->nullable()->after('interest_amount');
        });

        Schema::table('investment_contracts', function (Blueprint $table) {
            $table->string('template_hash', 64)->nullable()->after('template_version');
        });

        // PAY-30 (owner 2026-09-03): which status a loan ended from and when.
        // `funding` marks a partially funded loan that closed because every
        // investor plan ran (or was closed early). NULL on every existing row.
        Schema::table('loans', function (Blueprint $table) {
            $table->string('closed_from_status', 16)->nullable()->after('early_repayment_amount');
            $table->timestamp('closed_at')->nullable()->after('closed_from_status');
        });

        // SEC-01 (owner 2026-09-03): a new IBAN is confirmed through a signed e-mail
        // link and becomes a payout destination only after a cooling-off. Rows with
        // all four columns NULL predate the feature and count as confirmed.
        Schema::table('saved_ibans', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('label');
            $table->char('confirmation_token_hash', 64)->nullable()->after('confirmed_at');
            $table->timestamp('confirmation_sent_at')->nullable()->after('confirmation_token_hash');
            $table->timestamp('confirmation_expires_at')->nullable()->after('confirmation_sent_at');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // Evidence only — no FK, the request must survive a deleted IBAN row.
            $table->unsignedBigInteger('saved_iban_id')->nullable()->after('iban')->index();
        });

        DB::table('platform_settings')->insertOrIgnore([
            'key' => 'withdrawal_new_iban_cooldown_hours',
            'value' => '24',
            'type' => 'int',
            'description' => 'Часове след потвърждаването на нов IBAN, преди към него да може да се заяви теглене. 0 = без изчакване. Одобрено: 24 (SEC-01, 2026-09-03).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('platform_settings')->insertOrIgnore([
            'key' => 'funding_auto_close_enabled',
            'value' => 'true',
            'type' => 'bool',
            'description' => 'Автоматично приключване (нощно в 03:30 и веднага след последното плащане) на частично финансирани кредити, чиито инвеститори са изплатени докрай по плановете си (PAY-30). Изключването ги оставя във «Финансира се»; не трие нищо.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // SEC-22 (owner 2026-09-03): the four-step deletion state machine lives on
        // the users row. NULL everywhere = no open request (every live row today).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('kyc_selfie_path');
            $table->timestamp('deletion_confirmed_at')->nullable()->after('deletion_requested_at');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('deletion_confirmed_at')->index();
            $table->timestamp('deletion_finalized_at')->nullable()->after('deletion_scheduled_for');
        });

        // SEC-16 (owner 2026-09-03): compliance archive of closed accounts —
        // identity files + consent ledger with a ЗМИП clock (see KycRetention).
        Schema::create('kyc_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('account_type', 20);
            $table->string('kyc_status_at_deletion', 20);
            $table->string('kyc_document_front_path')->nullable();
            $table->string('kyc_document_back_path')->nullable();
            $table->string('kyc_selfie_path')->nullable();
            $table->text('consent_snapshot')->nullable();
            $table->text('subject_snapshot')->nullable();
            $table->unsignedSmallInteger('retention_years');
            $table->date('retained_until')->index();
            $table->timestamp('purged_at')->nullable();
            $table->unsignedBigInteger('purged_by')->nullable();
            $table->timestamps();
            $table->index(['purged_at', 'retained_until']);
        });

        foreach ([
            ['account_deletion_waiting_days', '7', 'int', 'Дни между потвърждението по имейл и реалното закриване на акаунт (SEC-22). Промяната важи за бъдещи потвърждения.'],
            ['account_deletion_finalize_enabled', 'true', 'bool', 'Нощно финализиране (04:30) на потвърдени заявки за закриване на акаунт. Изключването оставя заявките да чакат; не трие нищо.'],
            ['kyc_retention_years', '5', 'int', 'Години, през които документите за самоличност и съгласията на закрит акаунт се пазят в KYC архива (ЗМИП чл. 67). Промяната важи за бъдещи закривания.'],
            ['kyc_retention_purge_enabled', 'true', 'bool', 'Нощно заличаване (05:00) на KYC архиви с изтекъл срок. Изключването само отлага заличаването; нищо не се губи.'],
        ] as [$key, $value, $type, $description]) {
            DB::table('platform_settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['account_deletion_waiting_days', 'account_deletion_finalize_enabled', 'kyc_retention_years', 'kyc_retention_purge_enabled'] as $key) {
            DB::table('platform_settings')->where('key', $key)->delete();
        }

        Schema::dropIfExists('kyc_retentions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_confirmed_at', 'deletion_scheduled_for', 'deletion_finalized_at']);
        });

        DB::table('platform_settings')->where('key', 'funding_auto_close_enabled')->delete();
        DB::table('platform_settings')->where('key', 'withdrawal_new_iban_cooldown_hours')->delete();

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropIndex(['saved_iban_id']);
            $table->dropColumn('saved_iban_id');
        });

        Schema::table('saved_ibans', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'confirmation_token_hash', 'confirmation_sent_at', 'confirmation_expires_at']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['closed_from_status', 'closed_at']);
        });

        Schema::table('investment_contracts', function (Blueprint $table) {
            $table->dropColumn('template_hash');
        });

        Schema::table('loan_early_closures', function (Blueprint $table) {
            $table->dropUnique(['request_token']);
            $table->dropColumn(['request_token', 'accrued_written_off']);
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropIndex(['approved_by']);
            $table->dropColumn('approved_by');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropIndex(['processed_by']);
            $table->dropColumn(['processed_by', 'approved_at', 'fee_quoted']);
            $table->dropIndex(['approved_by']);
            $table->dropColumn('approved_by');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
