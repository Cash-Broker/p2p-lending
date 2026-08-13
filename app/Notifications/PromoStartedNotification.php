<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * «И едно звънче там» (Reni 2026-08-14): the bell ping every investor gets
 * the moment a flash promo goes live. Database-only (the SPA bell), queued —
 * one dispatch fans out to the whole investor base, so it must not block the
 * admin's create action. No email on purpose: a 60-minute window is bell
 * territory; mail would arrive after the party ends.
 *
 * Snapshot pattern: everything the bell renders is frozen at dispatch.
 */
class PromoStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $loanId,
        public string $bonusPercent,
        public CarbonInterface $endsAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'promo_started',
            'loan_id' => $this->loanId,
            'bonus_percent' => $this->bonusPercent,
            'ends_at' => $this->endsAt->toIso8601String(),
            'message' => sprintf(
                '+%s%% бонус веднага при инвестиция в кредит #%d — само до %s ч.',
                rtrim(rtrim($this->bonusPercent, '0'), '.'),
                $this->loanId,
                $this->endsAt->timezone(config('app.timezone'))->format('H:i'),
            ),
        ];
    }
}
