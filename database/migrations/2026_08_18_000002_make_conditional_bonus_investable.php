<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The conditional bonus becomes INVESTABLE (Reni 2026-08-18, same day as the
 * feature shipped): «може ли бонусът да се инвестира, или ще стои заключен без
 * движение до 3-тия месец» — it must be usable as investment capital from day
 * one; only WITHDRAWING it stays conditional.
 *
 * So the money leaves its own bucket and joins `available`, and the condition
 * is enforced as a FLOOR on withdrawals instead:
 *
 *     withdrawable = available − Σ(locked bonus grants)
 *
 * The floor lives in `WalletService::reserve()` — the single method every
 * withdrawal must pass through (`WithdrawalService::createRequest` is its only
 * caller), so investing is untouched while cashing out still cannot reach an
 * unearned bonus.
 *
 * MONEY MOVES HERE. Any bonus still sitting in `wallets.bonus_locked` is added
 * to `available`. That keeps the ledger consistent with the new LEDGER_MAP,
 * where a `bonus_locked` transaction now contributes to cash:
 *
 *   • still-locked grant   old: bucket +X            new: cash +X  → moved below
 *   • released grant       old: bucket +X, −X, cash +X   new: cash +X, release 0
 *   • cancelled grant      old: bucket +X, −X        new: cash +X, cancel −X
 *
 * Historical transaction rows are NOT rewritten (they are immutable at the DB
 * level) — the map is what changes meaning, and the arithmetic above shows it
 * lands on the same balances.
 *
 * Sign-off: Yordan, 2026-08-18.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Release the bucket into spendable balance. Single atomic UPDATE:
        //    an addition can never trip the `available >= 0` CHECK, and no
        //    other process can see a half-migrated wallet.
        $moved = DB::table('wallets')->where('bonus_locked', '>', 0)->count();
        $total = (string) (DB::table('wallets')->where('bonus_locked', '>', 0)->sum('bonus_locked') ?: '0');

        DB::statement('UPDATE wallets SET available = available + bonus_locked, bonus_locked = 0 WHERE bonus_locked > 0');

        // Money moved by a migration bypasses the Auditable trait, so leave a
        // trace where support will look for it.
        Log::info('Migration: conditional bonuses moved into available', [
            'wallets_affected' => $moved,
            'total_moved_eur' => $total,
        ]);

        // 2. The bucket is gone; the floor replaces it.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_bonus_locked_non_negative');
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('bonus_locked');
        });

        // 3. Releasing a grant no longer writes a ledger row — the money is
        //    already in `available`, only the floor drops. The old CHECK
        //    demanded a release transaction id and would reject every future
        //    release.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE bonus_grants DROP CONSTRAINT chk_bonus_grants_release_pair');
        }

        Schema::table('bonus_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_transaction_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('
                ALTER TABLE bonus_grants
                ADD CONSTRAINT chk_bonus_grants_released_at
                CHECK (
                    (status = \'released\' AND released_at IS NOT NULL)
                 OR (status <> \'released\' AND released_at IS NULL)
                )
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE bonus_grants DROP CONSTRAINT chk_bonus_grants_released_at');
        }

        Schema::table('bonus_grants', function (Blueprint $table) {
            $table->foreignId('release_transaction_id')->nullable()->after('released_at')
                ->constrained('transactions')->restrictOnDelete();
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('bonus_locked', 12, 2)->default(0)->after('accrued');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_bonus_locked_non_negative CHECK (bonus_locked >= 0)');
            DB::statement('
                ALTER TABLE bonus_grants
                ADD CONSTRAINT chk_bonus_grants_release_pair
                CHECK (
                    (status = \'released\' AND released_at IS NOT NULL AND release_transaction_id IS NOT NULL)
                 OR (status <> \'released\' AND released_at IS NULL AND release_transaction_id IS NULL)
                )
            ');
        }

        // Deliberately NOT moving money back out of `available`: by now the
        // investor may have invested it, so the reversal could underflow.
        // Same accepted drift as the deposit-code refactor's down().
    }
};
