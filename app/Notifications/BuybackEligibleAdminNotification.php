<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-facing DIGEST notification: summary of newly-detected buyback
 * eligible loans plus an age-breakdown reminder for loans aging in the
 * Queue.
 *
 * One notification per cron run (Q21) — NOT one per eligible loan.
 * Dispatched only when at least 1 loan is actionable (newly-eligible OR
 * aging > 3 days); an empty-state run writes metrics but sends no email
 * (Q21: no empty daily spam).
 *
 * Channels: mail + database (admin inbox visibility in Filament).
 *
 * Rate limiting — per (admin, run_at):
 *   Each cron run generates a distinct `run_at` timestamp. The dedupe
 *   query matches on data->run_at AS AN EXACT ISO STRING. This guards
 *   against queue-worker retries of the SAME serialized job re-landing
 *   a duplicate row in the admin inbox. Two independent cron runs have
 *   different run_at values and both deliver normally.
 *
 * Constructor snapshot pattern (F1 convention): all rendering data is
 * frozen at dispatch time. Queue worker that delivers hours later sees
 * the same counts + loan IDs as were current at cron run time — no
 * re-query against mutated state.
 *
 * Contract guard: toArray() MUST keep `run_at` as ISO-8601 string and
 * keep it in the `data` payload — the dedupe query in via() depends on
 * exact format match. Pinned by a contract-guard test in Step 7.
 */
class BuybackEligibleAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $newlyEligibleCount         loans flagged in THIS cron run (new today)
     * @param  int  $waitingMoreThan3DaysCount  loans flagged > 3 days ago AND still pending
     * @param  int[]  $loanIds                  IDs of the newly-flagged loans (for email listing)
     * @param  CarbonInterface  $runAt          the cron run timestamp (dedupe key)
     */
    public function __construct(
        public int $newlyEligibleCount,
        public int $waitingMoreThan3DaysCount,
        public array $loanIds,
        public CarbonInterface $runAt,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->wasRecentlyNotified($notifiable)) {
            return [];
        }
        return ['mail', 'database'];
    }

    /**
     * Match any existing database-channel row of THIS class for THIS admin
     * with THIS exact run_at ISO string. Protects against queue-worker
     * retries re-delivering the same job after a transient SMTP failure.
     */
    private function wasRecentlyNotified(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }
        return $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->run_at', $this->runAt->toIso8601String())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $newly = $this->newlyEligibleCount;
        $subject = sprintf(
            '[P2P Invest] %d %s buyback-eligible %s — Buyback Queue',
            $newly,
            $newly === 1 ? 'нов' : 'нови',
            $newly === 1 ? 'кредит' : 'кредита',
        );

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.buyback-eligible-admin', [
                'adminName'         => $notifiable->name ?? 'администратор',
                'newlyCount'        => $this->newlyEligibleCount,
                'olderCount'        => $this->waitingMoreThan3DaysCount,
                'loanIds'           => $this->loanIds,
                'runAtFormatted'    => $this->runAt->format('d.m.Y H:i'),
                'queueUrl'          => config('app.url') . '/admin/buyback-queue',
            ]);
    }

    /**
     * Database channel payload.
     *
     * **Contract** (do not break — via()'s rate-limit query depends):
     *   - `run_at` present.
     *   - `run_at` is an ISO-8601 string (from Carbon::toIso8601String()).
     *
     * Keys also surface to the Filament admin inbox — stable field names
     * help any future UI that renders them.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyback_eligible_admin_digest',
            'run_at' => $this->runAt->toIso8601String(),
            'newly_eligible_count' => $this->newlyEligibleCount,
            'waiting_more_than_3_days_count' => $this->waitingMoreThan3DaysCount,
            'loan_ids' => $this->loanIds,
        ];
    }
}
