<?php

namespace App\Console\Commands\Ops;

use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InvestorPayoutDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Morning Web Push digest of the scheduled interest payouts (2026-08-17).
 *
 * Scheduled 09:05 — after the 04:00 payout cron and next to the 09:00
 * Telegram digest. Aggregates each investor's interest credited in the last
 * 24h and sends ONE push per investor.
 *
 * Scope (review 2026-08-17 — «числата винаги верни»): ONLY rows written by
 * the offer payout engine, identified by their `loan:{id}:investment:{iid}:…`
 * reference. That is deliberate:
 *  - legacy repayments (`loan:{id}:user:{uid}`), buyback and early repayment
 *    each send their OWN instant push, so including them here would announce
 *    the same euros twice;
 *  - `interest_accrued` is recognition, not cash, and never counts.
 * The 24h window (not "since midnight") keeps manual evening payouts —
 * admin «Пусни плащане сега» — from vanishing unannounced; the copy
 * therefore says «платформата изплати», not «тази нощ».
 *
 * Only investors with registered push devices are considered — no
 * subscriptions, no queue churn. Kill switch: `push_payout_digest_enabled`.
 */
class SendPayoutDigestPush extends Command
{
    protected $signature = 'push:payout-digest {--dry-run : List recipients without sending}';

    protected $description = 'Send the morning Web Push digest of interest paid out in the last 24h';

    private const LOCK_KEY = 'push:payout-digest';

    /** Cash-interest types the payout engine writes. */
    private const INTEREST_TYPES = [
        Transaction::TYPE_REPAYMENT_INTEREST,
        Transaction::TYPE_INTEREST_RELEASED,
    ];

    /**
     * Reference prefix of the per-investment payout engine
     * (`loan:{id}:investment:{iid}:schedule:{sid}` and `…:capitalized`).
     * Every other interest reference belongs to a flow that pushes on its own.
     */
    private const PAYOUT_REFERENCE_PATTERN = 'loan:%:investment:%';

    public function handle(): int
    {
        if (! PlatformSetting::get('push_payout_digest_enabled', true)) {
            $this->info('Payout digest push disabled via platform setting. Exit.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK_KEY, 600);

        if (! $lock->get()) {
            $this->error('Another push:payout-digest instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            $since = now()->subDay();

            $rows = Transaction::query()
                ->whereIn('type', self::INTEREST_TYPES)
                ->where('created_at', '>', $since)
                // Payout-engine rows only — see the class docblock.
                ->where('reference', 'like', self::PAYOUT_REFERENCE_PATTERN)
                ->groupBy('user_id')
                ->select(
                    'user_id',
                    DB::raw('SUM(amount) as total_interest'),
                    // Every reference in scope is loan:{id}:investment:… so
                    // segment 2 is the loan id.
                    DB::raw("COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(reference, ':', 2), ':', -1)) as loans_count"),
                )
                ->get();

            if ($rows->isEmpty()) {
                $this->info('No interest paid out in the window — nothing to push.');

                return self::SUCCESS;
            }

            $subscribed = User::whereHas('pushSubscriptions')
                ->whereIn('id', $rows->pluck('user_id'))
                ->get()
                ->keyBy('id');

            $sent = 0;
            foreach ($rows as $row) {
                $user = $subscribed->get($row->user_id);
                if (! $user) {
                    continue;
                }

                // bcmath, not a float round-trip: SUM() of a DECIMAL comes
                // back as a string and must stay one (house money rule).
                $total = bcadd((string) $row->total_interest, '0', 2);
                if (bccomp($total, '0.00', 2) <= 0) {
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("[dry-run] user #{$user->id}: {$total} € from {$row->loans_count} loan(s)");
                    $sent++;

                    continue;
                }

                $user->notify(new InvestorPayoutDigestNotification($total, (int) $row->loans_count));
                $sent++;
            }

            $this->info("Payout digest push: {$sent} investor(s) notified.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('push:payout-digest failed', ['error' => $e->getMessage()]);
            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
