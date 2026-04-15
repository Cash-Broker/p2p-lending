<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('reserved', 12, 2)->default(0)->after('available');
        });

        // CHECK constraints — last line of defense against negative balances.
        // Application logic should prevent this, but if a bug slips through,
        // the DB MUST reject the write rather than store corrupt data.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_available_non_negative CHECK (available >= 0)');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_reserved_non_negative CHECK (reserved >= 0)');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_invested_non_negative CHECK (invested >= 0)');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_earned_non_negative CHECK (earned >= 0)');
            DB::statement('ALTER TABLE loans ADD CONSTRAINT chk_loans_funded_amount_valid CHECK (funded_amount >= 0 AND funded_amount <= amount)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_available_non_negative');
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_reserved_non_negative');
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_invested_non_negative');
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT chk_wallets_earned_non_negative');
            DB::statement('ALTER TABLE loans DROP CONSTRAINT chk_loans_funded_amount_valid');
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('reserved');
        });
    }
};
