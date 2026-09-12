<?php

namespace App\Services;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\ScheduledPayoutReceivedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Turns the schedule rows a payout run just marked `paid` into ONE
 * {@see ScheduledPayoutReceivedNotification} per (investor, loan).
 *
 * Called by ScheduledPayoutService AFTER PayoutAccrualService's transaction
 * committed — money first, notifications after. Nothing in here may reach
 * the caller as an exception: runAllAutomatic would count the loan as FAILED
 * and tell the admin its investors were NOT paid, which would be false.
 *
 * The same grouping serves `payouts:notify-paid` (re-send / backfill by day),
 * so both paths produce identical mails and share the row-id dedupe.
 *
 * Kill switch `payout_email_enabled` (default on) — the money still moves,
 * the bell/mail just stays silent, like push_payout_digest_enabled.
 */
class ScheduledPayoutNotifier
{
    public const SETTING_ENABLED = 'payout_email_enabled';

    public static function isEnabled(): bool
    {
        return (bool) PlatformSetting::get(self::SETTING_ENABLED, true);
    }

    /**
     * Announce rows by id (the ids PayoutAccrualService returns for a run).
     * Never throws.
     *
     * @param  array<int, int>  $scheduleIds
     * @return array{sent: int, skipped: int}
     */
    public function notifyForScheduleIds(array $scheduleIds): array
    {
        if ($scheduleIds === []) {
            return ['sent' => 0, 'skipped' => 0];
        }

        try {
            $rows = InvestmentSchedule::query()
                ->whereIn('id', $scheduleIds)
                ->where('status', InvestmentSchedule::STATUS_PAID)
                ->with('investment.user')
                ->orderBy('due_date')
                ->orderBy('id')
                ->get();

            return $this->notifyRows($rows);
        } catch (\Throwable $e) {
            Log::warning('Scheduled payout notifications skipped', [
                'schedule_ids' => $scheduleIds,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => 0, 'skipped' => 0];
        }
    }

    /**
     * Announce already-paid rows (with `investment.user` loaded). One
     * investor's failure never stops the others.
     *
     * @param  Collection<int, InvestmentSchedule>  $rows
     * @return array{sent: int, skipped: int}
     */
    public function notifyRows(Collection $rows): array
    {
        $sent = 0;
        $skipped = 0;

        if (! self::isEnabled()) {
            return ['sent' => 0, 'skipped' => 0];
        }

        foreach ($this->groups($rows) as $group) {
            /** @var User $user */
            $user = $group['user'];
            /** @var ScheduledPayoutReceivedNotification $notification */
            $notification = $group['notification'];

            try {
                // The notification re-checks in via() (queue retries); this
                // early check only keeps the counters honest for the command.
                if ($notification->wasAlreadySent($user)) {
                    $skipped++;

                    continue;
                }

                $user->notify($notification);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Scheduled payout notification failed', [
                    'user_id' => $user->id,
                    'loan_id' => $notification->loanId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Group paid rows into (investor, loan) notifications without sending —
     * shared with the command's --dry-run.
     *
     * @param  Collection<int, InvestmentSchedule>  $rows
     * @return Collection<int, array{user: User, loan_id: int, notification: ScheduledPayoutReceivedNotification}>
     */
    public function groups(Collection $rows): Collection
    {
        return $rows
            // A closed (anonymised) account has no mailbox — reachable only via a
            // re-send for an old day, never via the live run (closure is blocked
            // while an investment is still paying).
            ->filter(fn (InvestmentSchedule $row) => $row->investment?->user !== null && ! $row->investment->user->isClosed())
            ->groupBy(fn (InvestmentSchedule $row) => $row->investment->user_id.':'.$row->loan_id)
            ->map(function (Collection $group) {
                /** @var InvestmentSchedule $first */
                $first = $group->first();

                return [
                    'user' => $first->investment->user,
                    'loan_id' => (int) $first->loan_id,
                    'notification' => $this->build($group),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, InvestmentSchedule>  $group  rows of ONE investor in ONE loan
     */
    private function build(Collection $group): ScheduledPayoutReceivedNotification
    {
        /** @var InvestmentSchedule $first */
        $first = $group->first();
        $userId = (int) $first->investment->user_id;
        $loanId = (int) $first->loan_id;

        $principal = '0.00';
        $interest = '0.00';
        $installments = [];

        foreach ($group as $row) {
            $principal = bcadd($principal, (string) $row->principal, 2);
            $interest = bcadd($interest, (string) $row->interest, 2);
            $installments[] = [
                'due_date' => $row->due_date->toDateString(),
                'principal' => (string) $row->principal,
                'interest' => (string) $row->interest,
                'total' => (string) $row->total,
            ];
        }

        // «Последна вноска» = nothing left to receive in this loan across ALL
        // of the investor's positions in it. `closed` rows (partial early
        // closure) are settled, not outstanding — same reading as
        // LoanStatusUpdaterService.
        $isFinal = ! InvestmentSchedule::query()
            ->where('loan_id', $loanId)
            ->whereIn('investment_id', Investment::query()->where('loan_id', $loanId)->where('user_id', $userId)->select('id'))
            ->whereIn('status', [InvestmentSchedule::STATUS_PENDING, InvestmentSchedule::STATUS_LATE])
            ->exists();

        $payoutTypes = $group
            ->map(fn (InvestmentSchedule $row) => $row->investment->payout_type)
            ->filter(fn ($type) => $type instanceof PayoutType)
            ->unique(fn (PayoutType $type) => $type->value);

        return new ScheduledPayoutReceivedNotification(
            loanId: $loanId,
            scheduleIds: $group->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            installments: $installments,
            principal: $principal,
            interest: $interest,
            total: bcadd($principal, $interest, 2),
            payoutType: $payoutTypes->count() === 1 ? $payoutTypes->first() : null,
            isFinal: $isFinal,
            paidOn: $first->paid_at?->copy() ?? now(),
        );
    }
}
