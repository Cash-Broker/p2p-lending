<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conditional bonuses (Reni 2026-08-18).
 *
 * A bonus is no longer spendable cash the moment it is granted: it lands in a
 * NEW `bonus_locked` wallet bucket and is released into `available` only after
 * the investor has put the agreed amount to work and served its installments.
 * Otherwise nothing stops the loop deposit → bonus → withdraw everything.
 *
 * Why a bucket and not a flag on the withdrawal path: `WalletService` is the
 * single gate to the money, so keeping the bonus OUT of `available` makes the
 * rule hold for every present and future spend path (withdraw, invest, fee) —
 * a check bolted onto WithdrawalService would only cover the one it lives in.
 *
 * Sign-off: Yordan, 2026-08-18 (CLAUDE.md requires an explicit human decision
 * for wallet CHECK constraints and LEDGER_MAP changes).
 *
 * Legacy `bonus` ledger rows are NOT migrated: they were granted under the old
 * "spendable immediately" terms and stay in `available`. Terms are not changed
 * retroactively, and `ReconcileLedger` keeps its old mapping for that type so
 * historical wallets keep reconciling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('bonus_locked', 12, 2)->default(0)->after('accrued');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_bonus_locked_non_negative CHECK (bonus_locked >= 0)');
        }

        Schema::create('bonus_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The TYPE_BONUS_LOCKED row that put the money in the bucket.
            // RESTRICT: transactions are immutable evidence, never orphaned.
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 12, 2);
            // The investment the bonus was calculated on («за 5000 → 50 €»).
            // Admin types it in; for promo grants it is the investment amount.
            $table->decimal('base_amount', 12, 2);
            // How many installments a qualifying investment must have served.
            // Per-grant so the policy can change without rewriting history.
            $table->unsignedTinyInteger('required_installments')->default(3);

            $table->string('source', 16);              // admin | promo
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('loan_promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();

            $table->string('status', 16)->default('locked'); // locked | released | cancelled
            // Only investments made from this moment on count toward the base.
            // Admin grants: the grant time (the bonus must INDUCE the money).
            // Promo grants: the investment's own timestamp, so the investment
            // that earned the bonus qualifies for it.
            $table->timestamp('qualifies_from');

            $table->timestamp('released_at')->nullable();
            $table->foreignId('release_transaction_id')->nullable()
                ->constrained('transactions')->restrictOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE bonus_grants ADD CONSTRAINT chk_bonus_grants_status CHECK (status IN ('locked','released','cancelled'))");
            DB::statement("ALTER TABLE bonus_grants ADD CONSTRAINT chk_bonus_grants_source CHECK (source IN ('admin','promo'))");
            DB::statement('ALTER TABLE bonus_grants ADD CONSTRAINT chk_bonus_grants_amounts_positive CHECK (amount > 0 AND base_amount > 0)');
            // A released grant must carry its release row, and only a released
            // grant may carry one — the bucket move and the status can never
            // drift apart.
            DB::statement('
                ALTER TABLE bonus_grants
                ADD CONSTRAINT chk_bonus_grants_release_pair
                CHECK (
                    (status = \'released\' AND released_at IS NOT NULL AND release_transaction_id IS NOT NULL)
                 OR (status <> \'released\' AND released_at IS NULL AND release_transaction_id IS NULL)
                )
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE bonus_grants DROP CONSTRAINT chk_bonus_grants_release_pair');
            DB::statement('ALTER TABLE bonus_grants DROP CONSTRAINT chk_bonus_grants_amounts_positive');
            DB::statement('ALTER TABLE bonus_grants DROP CONSTRAINT chk_bonus_grants_source');
            DB::statement('ALTER TABLE bonus_grants DROP CONSTRAINT chk_bonus_grants_status');
        }

        Schema::dropIfExists('bonus_grants');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_bonus_locked_non_negative');
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('bonus_locked');
        });
    }
};
