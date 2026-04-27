<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level immutability for audit_logs (audit H4).
 *
 * Mirror of the transactions immutability triggers. The Auditable trait
 * (app/Traits/Auditable.php) writes audit_logs rows on every create /
 * update / delete of sensitive models (User, Wallet, Transaction, Loan,
 * Investment, DepositRequest, WithdrawalRequest). The Filament
 * AuditLogResource exposes only ViewAction — no edit, no delete.
 *
 * Without these triggers, however, any path that bypasses Filament
 * (admin shell, leaked DB credentials, raw `mysql` from inside the
 * server) can `UPDATE` or `DELETE` audit_log rows, erasing the only
 * forensic trail of admin tampering. transactions has had this
 * protection since 2026-03-29; audit_logs lagged behind. This closes
 * the gap.
 *
 * Regulatory framing: financial platforms must demonstrate that the
 * audit trail itself cannot be tampered with. App-level guards (no
 * delete action in Filament) are necessary but insufficient — a
 * compromised admin / leaked credentials bypass them. DB-level
 * SIGNAL SQLSTATE ensures even raw SQL fails.
 *
 * SQLite skipped (no SIGNAL support); test suite covers MySQL/MariaDB.
 *
 * Reversibility: down() drops the triggers. Safe even if rows accumulated
 * during the trigger window — DROP TRIGGER doesn't affect data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::unprepared("
            CREATE TRIGGER prevent_audit_log_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be updated';
            END
        ");

        DB::unprepared("
            CREATE TRIGGER prevent_audit_log_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be deleted';
            END
        ");
    }

    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_log_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_log_delete');
    }
};
