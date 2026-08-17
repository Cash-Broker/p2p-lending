<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The 09:05 «събуждаш се с пари» push (2026-08-17, Yordan sign-off): the
 * payout cron runs at 04:00 — pushing then would wake people up, so the
 * night's interest is batched into one morning notification.
 *
 * WebPush ONLY by design: the durable record of the payouts already lives in
 * the transaction history / portfolio; this is the doorbell, not the ledger.
 * Dispatched only to users who actually have registered devices (the command
 * filters), so the queue never churns no-op jobs.
 */
class InvestorPayoutDigestNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        private string $totalInterest,
        private int $loansCount,
    ) {}

    public function via(object $notifiable): array
    {
        return [QueuedWebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $loans = $this->loansCount === 1 ? '1 кредит' : "{$this->loansCount} кредита";

        // No «тази нощ»: the window is a rolling 24h so manual daytime
        // payouts are included too (review 2026-08-17).
        return $this->webPushMessage(
            "Получихте {$this->totalInterest} € лихва",
            "Платформата изплати вноските по {$loans}.",
            config('app.url').'/portfolio',
            'payout-digest',
        );
    }
}
