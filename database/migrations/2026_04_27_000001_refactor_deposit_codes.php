<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit code refactor (resolves audit findings C2 + M1 + H5).
 *
 * Pre-refactor flow had two disconnected reference systems:
 *   1. /api/deposit returned `P2P-{user_id}` — sequential, leaks user_id,
 *      enables a "twist attack" where user A wires money with reference
 *      `P2P-000015` (user B's code), and a tired admin matching by code
 *      instead of by sender name credits the wrong account.
 *   2. DepositRequest::booted() generated `DEP-{8 random chars}` on row
 *      create — never shown to the user (they saw P2P-XXX). Pure dead code.
 *
 * Post-refactor: the random DEP-XXXXXXXX code is THE single user-facing
 * reference. /api/deposit returns the existing pending DepositRequest
 * (or creates one with amount=null). Admin matches by pasting the code,
 * not by guessing the user from sender name.
 *
 * Schema changes:
 *   - `amount` becomes nullable. A DepositRequest is now created at
 *     code-issuance time, BEFORE the wire arrives — so we don't yet
 *     know how much the user will send. Admin fills the amount when
 *     they credit the deposit.
 *   - `expires_at` — 30-day code lifetime. Reduces stale-code attack
 *     surface; if a user got a code and never used it, it eventually
 *     stops working. New code on next /api/deposit visit.
 *   - `bank_reference` (unique) — the literal reference string from the
 *     bank statement. Same wire CANNOT be applied to two deposits
 *     (closes audit finding H5).
 *
 * Backward compatibility: existing pending DepositRequest rows have
 * NULL `expires_at`, treated as "never expires" by the service-layer
 * query in DepositController::index. This matches pre-refactor behavior
 * for legacy rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            // Amount is unknown at code-issuance time. Admin fills it on credit.
            $table->decimal('amount', 12, 2)->nullable()->change();

            // 30-day code lifetime. NULL = legacy row (no expiry).
            $table->timestamp('expires_at')->nullable()->after('confirmed_at');

            // Literal reference from bank statement. UNIQUE so the same
            // wire cannot be credited twice (audit H5).
            $table->string('bank_reference')->nullable()->after('reference_code');
            $table->unique('bank_reference');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropUnique(['bank_reference']);
            $table->dropColumn(['expires_at', 'bank_reference']);
            // Note: not reverting `amount` to NOT NULL — would fail if any
            // refactor-era rows have null amounts. Acceptable drift in down().
        });
    }
};
