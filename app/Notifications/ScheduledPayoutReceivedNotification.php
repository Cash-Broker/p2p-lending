<?php

namespace App\Notifications;

use App\Enums\PayoutType;
use App\Services\ScheduledPayoutNotifier;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor-facing «получихте плащане» for a SCHEDULED payout of an offer-based
 * loan (Yordan 2026-09-12: «хубаво е да знаят»). Built by
 * {@see ScheduledPayoutNotifier} AFTER the payout transaction
 * committed — one per (investor, loan, run), carrying every installment
 * released in that run (normally one; several only when a run catches up on
 * missed days).
 *
 * Mail + bell only. NO Web Push on purpose: push:payout-digest already
 * announces the same money at 09:05 and a second push would double-announce
 * euros (the same reason that digest excludes legacy repayments).
 *
 * Dedupe is keyed on the schedule row ids — a row is paid exactly once, so a
 * queue retry or the payouts:notify-paid re-send command cannot mail twice.
 *
 * Capitalized plans get this only at maturity (the one moment they are PAID);
 * the monthly accrual moves nothing to `available` and is not announced.
 *
 * PII hygiene, as with every loan notification: no borrower data, only the
 * loan id and the investor's own numbers.
 */
class ScheduledPayoutReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $scheduleIds
     * @param  array<int, array{due_date: string, principal: string, interest: string, total: string}>  $installments
     * @param  PayoutType|null  $payoutType  null when the investor holds positions on different plans in this loan
     */
    public function __construct(
        public int $loanId,
        public array $scheduleIds,
        public array $installments,
        public string $principal,
        public string $interest,
        public string $total,
        public ?PayoutType $payoutType,
        public bool $isFinal,
        public CarbonInterface $paidOn,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->wasAlreadySent($notifiable)) {
            return [];
        }

        return ['mail', 'database'];
    }

    /**
     * One notification per paid installment — a retry or a re-send that
     * overlaps ANY of these rows is a duplicate.
     */
    public function wasAlreadySent(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications') || $this->scheduleIds === []) {
            return false;
        }

        return $notifiable->notifications()
            ->where('type', static::class)
            ->where(function ($query) {
                foreach ($this->scheduleIds as $scheduleId) {
                    $query->orWhereJsonContains('data->schedule_ids', (int) $scheduleId);
                }
            })
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->payoutType ? " (план «{$this->payoutType->label()}»)" : '';

        $mail = (new MailMessage)
            ->subject("Получено плащане {$this->total} € по кредит #{$this->loanId} — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("На {$this->paidOn->format('d.m.Y')} по сметката ви постъпи плащане по график от кредит #{$this->loanId}{$plan}.");

        if (count($this->installments) > 1) {
            $mail->line('Вноски, включени в плащането:');
            foreach ($this->installments as $installment) {
                $due = Carbon::parse($installment['due_date'])->format('d.m.Y');
                $mail->line("• вноска с падеж {$due} — {$installment['total']} €");
            }
        }

        if (bccomp($this->principal, '0', 2) > 0) {
            $mail->line("Главница: {$this->principal} €");
        }

        $mail->line("Лихва: {$this->interest} €")
            ->line("Общо: {$this->total} €");

        if ($this->isFinal) {
            $mail->line('Това беше последната вноска по тази инвестиция — главницата ви е върната изцяло.');
        }

        return $mail
            ->line('Сумата е налична в портфейла ви веднага — може да я реинвестирате в друг кредит или да я изтеглите.')
            ->action('Виж портфейла', config('app.url').'/portfolio')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        $message = "Кредит #{$this->loanId} — {$this->interest} € лихва";
        if (bccomp($this->principal, '0', 2) > 0) {
            $message .= " + {$this->principal} € главница";
        }
        if ($this->isFinal) {
            $message .= ' (последна вноска)';
        }

        return [
            'type' => 'scheduled_payout_received',
            'loan_id' => $this->loanId,
            'schedule_ids' => array_values(array_map('intval', $this->scheduleIds)),
            'principal' => $this->principal,
            'interest' => $this->interest,
            'total' => $this->total,
            // `amount` is what the SPA bell renders in green next to the label.
            'amount' => $this->total,
            'payout_type' => $this->payoutType?->value,
            'is_final' => $this->isFinal,
            'paid_on' => $this->paidOn->toDateString(),
            'message' => $message,
        ];
    }
}
