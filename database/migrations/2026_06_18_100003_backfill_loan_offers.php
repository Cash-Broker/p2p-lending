<?php

use App\Enums\PayoutType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the three default offers (12/16/20) onto every EXISTING loan so the
 * boss can immediately edit offers on already-published loans.
 *
 * Re-runnable: insertOrIgnore + the unique(loan_id, payout_type) constraint
 * make a second run a no-op. Deliberately does NOT touch existing investments
 * — they keep loan_offer_id = null and stay on the legacy amortization path.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('loans')->orderBy('id')->select('id')->chunk(500, function ($loans) use ($now) {
            $rows = [];
            foreach ($loans as $loan) {
                foreach (PayoutType::defaults() as $type) {
                    $rows[] = [
                        'loan_id' => $loan->id,
                        'payout_type' => $type->value,
                        'interest_rate' => $type->defaultRate(),
                        'is_enabled' => true,
                        'position' => $type->position(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('loan_offers')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        // Truncate-by-delete: offers are derived data; safe to drop on rollback.
        DB::table('loan_offers')->delete();
    }
};
