<?php

namespace App\Console\Commands\Loans;

use App\Models\InvestmentSchedule;
use App\Services\ScheduledPayoutNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Send (or re-send) the «получено плащане» mails for installments the payout
 * engine marked `paid` on a given day. Manual tool, not scheduled: the live
 * path is ScheduledPayoutService → ScheduledPayoutNotifier right after each
 * loan's payout commits. Born 2026-09-12 to announce that morning's run, which
 * happened before the notification existed; kept as the re-send tool for a
 * day whose mails were lost (queue down, SMTP outage).
 *
 * Moves no money, changes no schedule row. Safe to repeat: the notification
 * dedupes on the schedule row ids, so a second run reports «пропуснати».
 */
class NotifyPaidPayouts extends Command
{
    protected $signature = 'payouts:notify-paid
        {--date= : Calendar day (Y-m-d, app timezone) whose paid installments to announce; defaults to today}
        {--loan= : Limit to one loan id}
        {--dry-run : List who would be mailed without sending}';

    protected $description = 'Send (or re-send) the investor «получено плащане» mails for installments paid on a given day';

    public function handle(ScheduledPayoutNotifier $notifier): int
    {
        try {
            $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : today();
        } catch (\Throwable) {
            $this->error('--date must be a calendar day (Y-m-d).');

            return self::FAILURE;
        }

        if ($date->gt(today())) {
            $this->error("--date={$date->toDateString()} is in the future — nothing can have been paid yet.");

            return self::FAILURE;
        }

        $rows = InvestmentSchedule::query()
            ->where('status', InvestmentSchedule::STATUS_PAID)
            ->whereDate('paid_at', $date->toDateString())
            ->when($this->option('loan'), fn ($query, $loanId) => $query->where('loan_id', (int) $loanId))
            ->with('investment.user')
            ->orderBy('loan_id')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No installments were paid on {$date->toDateString()} — nothing to announce.");

            return self::SUCCESS;
        }

        if (! ScheduledPayoutNotifier::isEnabled()) {
            $this->warn('payout_email_enabled is OFF — nothing will be sent. Turn it on in «Настройки» first.');
        }

        $groups = $notifier->groups($rows);

        $this->table(
            ['user', 'loan', 'rows', 'principal', 'interest', 'total', 'final', 'already sent'],
            $groups->map(fn (array $group) => [
                $group['user']->id,
                $group['loan_id'],
                count($group['notification']->scheduleIds),
                $group['notification']->principal,
                $group['notification']->interest,
                $group['notification']->total,
                $group['notification']->isFinal ? 'yes' : '',
                $group['notification']->wasAlreadySent($group['user']) ? 'yes' : '',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d notification(s) would be sent for %s.', $groups->count(), $date->toDateString()));

            return self::SUCCESS;
        }

        $result = $notifier->notifyRows($rows);

        $this->info(sprintf(
            '%s: %d notification(s) sent, %d skipped (already sent).',
            $date->toDateString(),
            $result['sent'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
