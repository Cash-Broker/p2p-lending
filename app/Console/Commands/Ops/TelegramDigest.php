<?php

namespace App\Console\Commands\Ops;

use App\Models\DepositRequest;
use App\Models\Loan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sends a daily morning digest to Telegram (INFO tier, silent).
 *
 * Aggregates state-of-platform numbers that admin should glance at each
 * morning: new registrations in the last 24h, items awaiting admin action
 * (KYC pending, deposits pending, withdrawals pending, buyback queue),
 * loan health (late count). Optionally adds last-night's F1/F2 cron summary
 * read from platform_metrics.
 *
 * Schedule: bootstrap/app.php → dailyAt('09:00') in app timezone.
 *
 * No-op when TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID are not configured.
 */
class TelegramDigest extends Command
{
    protected $signature = 'telegram:digest';

    protected $description = 'Send a morning digest of platform state to Telegram (INFO tier).';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->info('Telegram not configured — skipping digest.');

            return self::SUCCESS;
        }

        $since = now()->subDay();

        $newRegistrations = User::where('created_at', '>=', $since)->count();
        $kycPending = User::where('kyc_status', 'submitted')->count();
        $depositsPending = DepositRequest::where('status', 'pending')->count();
        $withdrawalsPending = WithdrawalRequest::where('status', 'pending')->count();
        $loansLate = Loan::whereIn('status', ['late', 'default'])->count();
        $buybackQueue = Loan::whereNotNull('buyback_eligible_at')
            ->whereNull('bought_back_at')
            ->whereNull('buyback_dismissed_at')
            ->count();

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

        $today = now()->format('d.m.Y');

        $body = "📊 Сутрешно резюме ($today):\n"
            ."• Нови регистрации (24ч): $newRegistrations\n"
            ."• KYC чакащи преглед: $kycPending\n"
            ."• Депозити чакащи потвърждение: $depositsPending\n"
            ."• Тегления чакащи обработка: $withdrawalsPending\n"
            ."• Late / default кредити: $loansLate\n"
            ."• Buyback queue: $buybackQueue\n\n"
            ."🤖 Нощни cron-и:\n"
            .'• F1 (last run): '.($lastLateCheck ?? 'не е работил').' — '.$lastLateMarked." вноски маркирани late\n"
            .'• F2 (last run): '.($lastBuybackCheck ?? 'не е работил').' — '.$lastBuybackEligible.' нови buyback';

        $needsAttention = $kycPending > 0 || $depositsPending > 0 || $withdrawalsPending > 0 || $buybackQueue > 0;
        $title = $needsAttention ? 'Daily Digest — има задачи за обработка' : 'Daily Digest — всичко е чисто';

        $telegram->info($title, $body);

        $this->info('Digest sent.');

        return self::SUCCESS;
    }
}
