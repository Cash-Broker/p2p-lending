<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deposit codes no longer expire (client decision 2026-07-17).
 *
 * A pending DEP-XXXXXXXX code is now valid until an admin approves or
 * rejects a deposit against it. Rationale: the user may have wired money
 * against the code days before the transfer lands — a code that dies on
 * a 30-day timer (or rotates for any reason other than a processed
 * deposit) strands a real bank transfer that the admin can no longer
 * match. This deliberately reverses the rotation added for audit finding
 * M1; the anti-twist protections stay intact (random non-sequential
 * codes, UNIQUE bank_reference).
 *
 * Data change: NULL the stored expires_at on still-pending rows so
 * nothing (admin table, API, old SPA bundles reading `expires_at`)
 * displays an expiry that is no longer enforced. NULL already means
 * "non-expiring" everywhere. Approved/rejected rows keep their
 * historical values — those codes are consumed anyway.
 *
 * The column itself stays (historical rows reference it).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deposit_requests')
            ->where('status', 'pending')
            ->update(['expires_at' => null]);
    }

    public function down(): void
    {
        // Irreversible data change: the original per-row expiry dates are
        // gone. Restoring a synthetic expiry would re-enable a policy the
        // code no longer enforces, so down() is a deliberate no-op.
    }
};
