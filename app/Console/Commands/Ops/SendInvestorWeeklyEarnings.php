<?php

namespace App\Console\Commands\Ops;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InvestorWeeklyEarningsNotification;
use App\Services\AccruedEarningsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly investor bulletin (client request 2026-08-13, Reni: «седмичен
 * бюлетин — тази седмица спечели X сума», by email, «постоянно»).
 *
 * Sends each verified investor a queued email with the interest INCOME paid
 * out over the past 7 days plus their current running profit («текуща
 * печалба»). Investors with nothing to show (no interest received AND no
 * running accrual) are skipped — no zero-euro spam.
 *
 * Kill switch: platform setting `investor_weekly_email_enabled`.
 * Idempotency: Cache::lock per run (same convention as the other crons).
 */
class SendInvestorWeeklyEarnings extends Command
{
    protected $signature = 'investors:weekly-earnings {--dry-run : List recipients without sending}';

    protected $description = 'Email each investor their weekly earnings summary';

    private const LOCK_KEY = 'investors:weekly-earnings';

    /** Interest income types — the same set the dashboard chart counts. */
    private const INTEREST_TYPES = [
        Transaction::TYPE_REPAYMENT_INTEREST,
        Transaction::TYPE_BUYBACK_INTEREST,
        Transaction::TYPE_EARLY_REPAYMENT_INTEREST,
        Transaction::TYPE_INTEREST_RELEASED,
    ];

    public function handle(AccruedEarningsService $accrued): int
    {
        if (! PlatformSetting::get('investor_weekly_email_enabled', true)) {
            $this->info('investor_weekly_email_enabled is off — nothing sent.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK_KEY, 600);

        if (! $lock->get()) {
            $this->error('Another investors:weekly-earnings instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            $windowEnd = now();
            $windowStart = $windowEnd->copy()->subDays(7);

            // Interest received per user over the window — one grouped query.
            $weeklyByUser = Transaction::query()
                ->whereIn('type', self::INTEREST_TYPES)
                ->where('created_at', '>=', $windowStart)
                ->where('created_at', '<', $windowEnd)
                ->groupBy('user_id')
                ->select('user_id', DB::raw('SUM(amount) as total'))
                ->pluck('total', 'user_id');

            // Candidates: got interest this week OR hold investments in a
            // payout-eligible loan (their «текуща печалба» is running).
            $activeInvestorIds = Investment::query()
                ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES))
                ->distinct()
                ->pluck('user_id');

            $candidateIds = $weeklyByUser->keys()
                ->merge($activeInvestorIds)
                ->unique()
                ->values();

            $sent = 0;

            User::query()
                ->whereIn('id', $candidateIds)
                ->where('role', 'investor')
                ->whereNotNull('email_verified_at')
                ->with('wallet')
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($accrued, $weeklyByUser, $windowStart, $windowEnd, &$sent) {
                    foreach ($users as $user) {
                        $weekly = bcadd((string) ($weeklyByUser[$user->id] ?? '0'), '0', 2);
                        $accrual = $accrued->forUser($user->id);

                        // Nothing received AND nothing running — skip, no spam.
                        if (bccomp($weekly, '0', 2) <= 0 && bccomp($accrual['amount_daily'], '0', 2) <= 0
                            && bccomp($accrual['daily_rate'], '0', 4) <= 0) {
                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $this->line(sprintf('[dry-run] %s — weekly %s €, accrued %s €',
                                $user->email, $weekly, $accrual['amount_daily']));
                            $sent++;

                            continue;
                        }

                        try {
                            $user->notify(new InvestorWeeklyEarningsNotification(
                                $weekly,
                                $accrual['amount_daily'],
                                (string) ($user->wallet->earned ?? '0.00'),
                                $windowStart,
                                $windowEnd,
                            ));
                            $sent++;
                        } catch (\Throwable $e) {
                            // One investor's failure must not stop the batch.
                            Log::error('Weekly earnings email failed', [
                                'user_id' => $user->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });

            $this->info(sprintf('%s %d investor email(s).', $this->option('dry-run') ? 'Would send' : 'Queued', $sent));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
