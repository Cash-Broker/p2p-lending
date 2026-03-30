<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DB-level immutability for financial transactions.
     *
     * The Transaction model already prevents updates via const UPDATED_AT = null,
     * but that's a PHP-level convention. A raw SQL query or compromised admin
     * could still modify records. These triggers enforce immutability at the
     * database engine level — even direct SQL access can't tamper with the ledger.
     *
     * This is a regulatory requirement: financial auditors need to verify that
     * transaction records cannot be modified after creation.
     *
     * SQLite doesn't support SIGNAL — triggers only apply to MySQL/MariaDB.
     */
    public function up(): void
    {
        if (config('database.default') === 'sqlite') {
            return; // SQLite doesn't support SIGNAL; tested via model-level checks
        }

        DB::unprepared("
            CREATE TRIGGER prevent_transaction_update
            BEFORE UPDATE ON transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Transactions are immutable and cannot be updated';
            END
        ");

        DB::unprepared("
            CREATE TRIGGER prevent_transaction_delete
            BEFORE DELETE ON transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Transactions are immutable and cannot be deleted';
            END
        ");
    }

    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS prevent_transaction_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_transaction_delete');
    }
};
