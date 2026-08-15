<?php

namespace App\Console\Commands\Ops;

use App\Models\DepositRequest;
use App\Models\Loan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\AdminActionItemsNotification;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sends a daily morning digest to Telegram (INFO tier, silent) and — when
 * there is actual work waiting — an action-items EMAIL to admin accounts.
 *
 * Aggregates state-of-platform numbers that admin should glance at each
 * morning: new registrations in the last 24h, items awaiting admin action
 * (KYC pending, deposits pending, withdrawals pending, buyback queue),
 * loan health (late count). Optionally adds last-night's F1/F2 cron summary
 * read from platform_metrics.
 *
 * Email rules (client request 2026-08-07):
 *   - sent ONLY when at least one actionable count is > 0 (no empty spam);
 *   - sent ONLY to users with role 'admin';
 *   - independent of Telegram: it goes out even when TELEGRAM_BOT_TOKEN
 *     is not configured, and an email failure never blocks the Telegram
 *     digest (Telegram is sent first; TelegramService never throws).
 *
 * Schedule: bootstrap/app.php → dailyAt('09:00') in app timezone.
 *
 * The Telegram part is a no-op when TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID
 * are not configured; the counting + email part always runs.
 */
class TelegramDigest extends Command
{
    protected $signature = 'telegram:digest';

    protected $description = 'Send a morning digest to Telegram (INFO tier) and email admins when there are pending action items.';

    public function handle(TelegramService $telegram): int
    {
        $runAt = now();
        $since = $runAt->copy()->subDay();

        $newRegistrations = User::where('created_at', '>=', $since)->count();
        $kycPending = User::where('kyc_status', 'submitted')->count();
        // Same rule as the admin UI (DepositRequestResource / StatsOverview):
        // pending rows without an amount are just issued reference codes the
        // user never wired against — not actionable work, so not counted.
        $depositsPending = DepositRequest::where('status', 'pending')
            ->where('amount', '>', 0)
            ->count();
        $withdrawalsPending = WithdrawalRequest::where('status', 'pending')->count();
        $loansLate = Loan::whereIn('status', ['late', 'default'])->count();
        $buybackQueue = Loan::whereNotNull('buyback_eligible_at')
            ->whereNull('bought_back_at')
            ->whereNull('buyback_dismissed_at')
            ->count();

        $needsAttention = $kycPending > 0 || $depositsPending > 0 || $withdrawalsPending > 0 || $buybackQueue > 0;

        if ($telegram->isConfigured()) {
            $lastLateCheck = DB::table('platform_metrics')
                ->where('key', 'last_late_check_run_at')
                ->value('value');
            $lastLateMarked = DB::table('platform_metrics')
                ->where('key', 'last_late_check_schedules_marked')
                ->value('value') ?? '0';
            $lastBuybackCheck = DB::table('platform_metrics')
                ->where('key', 'last_buyback_check_run_at')
                ->value('value');
            $lastBuybackEligible = DB::table('platform_metrics')
                ->where('key', 'last_buyback_check_loans_newly_eligible')
                ->value('value') ?? '0';

            $today = $runAt->format('d.m.Y');

            // Visit analytics (2026-08-15): yesterday's Sofia-day entries.
            $yesterday = $runAt->copy()->timezone('Europe/Sofia')->subDay()->toDateString();
            $visitStats = DB::table('user_visit_days')
                ->where('visit_date', $yesterday)
                ->selectRaw('COALESCE(SUM(entries), 0) as entries, COUNT(DISTINCT user_id) as users')
                ->first();

            $body = "📊 Сутрешно резюме ($today):\n"
                ."• Нови регистрации (24ч): $newRegistrations\n"
                ."• Влизания вчера: {$visitStats->entries} (от {$visitStats->users} инвеститори)\n"
                ."• KYC чакащи преглед: $kycPending\n"
                ."• Депозити чакащи потвърждение: $depositsPending\n"
                ."• Тегления чакащи обработка: $withdrawalsPending\n"
                ."• Late / default кредити: $loansLate\n"
                ."• Buyback queue: $buybackQueue\n\n"
                ."🤖 Нощни cron-и:\n"
                .'• F1 (last run): '.($lastLateCheck ?? 'не е работил').' — '.$lastLateMarked." вноски маркирани late\n"
                .'• F2 (last run): '.($lastBuybackCheck ?? 'не е работил').' — '.$lastBuybackEligible.' нови buyback';

            $title = $needsAttention ? 'Daily Digest — има задачи за обработка' : 'Daily Digest — всичко е чисто';

            $telegram->info($title, $body);

            $this->info('Telegram digest sent.');
        } else {
            $this->info('Telegram not configured — skipping Telegram digest.');
        }

        // Action-items email — only when there is actual work waiting (no
        // empty daily spam, mirroring the Q21 buyback-digest decision) and
        // only to admin accounts. Best-effort: a dispatch failure is
        // reported but never fails the command — the Telegram digest above
        // has already gone out.
        if (! $needsAttention) {
            $this->info('No actionable items — skipping admin email.');

            return self::SUCCESS;
        }

        try {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new AdminActionItemsNotification(
                    kycPending: $kycPending,
                    depositsPending: $depositsPending,
                    withdrawalsPending: $withdrawalsPending,
                    buybackQueue: $buybackQueue,
                    loansLate: $loansLate,
                    runAt: $runAt,
                ));
            }
            $this->info(sprintf('Action-items email queued for %d admin(s).', $admins->count()));
        } catch (\Throwable $e) {
            report($e);
            $this->error('Failed to queue admin action-items email: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
