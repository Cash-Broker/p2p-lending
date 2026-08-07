<?php

namespace App\Notifications;

use App\Models\Loan;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor notification — "your loan went late".
 *
 * Channels: mail + database (in-app inbox).
 * Queue: ShouldQueue — never block the late-detection command's HTTP
 * response time (the command runs from cron, but the same notification
 * is dispatched from any future admin-triggered late marking too).
 *
 * Anonymisation: this notification carries NO borrower PII. The body
 * references ONLY the loan id (no loan type, no borrower data, no
 * originator-internal fields). Confirmed by the test suite.
 *
 * Rate limiting — one notification per (investor, loan, late period):
 *
 *   "Late period" is identified by loan.became_late_at — the timestamp
 *   we set when the loan transitions active → late. A loan that recovers
 *   and goes late again gets a NEW became_late_at, hence a NEW late
 *   period, hence a NEW notification (per spec: "ако different (loan
 *   recovered and went late again) → SEND").
 *
 *   We check whether THIS user already received a notification for THIS
 *   loan with THIS became_late_at. If yes → SKIP. If no → SEND.
 *
 *   This naturally provides the within-day duplicate guard mentioned in
 *   the spec ("manual --force within same day"): a scheduler retry
 *   inside the same late period can't change the became_late_at, so the
 *   match-and-skip rule applies. There is no separate calendar 24-hour
 *   cooldown — by-late-period is the cleaner contract.
 *
 * Honesty note (2026-08-07 correction — the original wording claimed
 * via() re-evaluates at worker pickup, which is false): for a
 * ShouldQueue notification Laravel resolves via() ONCE at dispatch time
 * and bakes the channel list into per-channel queue jobs — via() is
 * never re-consulted on the worker. The check therefore suppresses a
 * REPEATED DISPATCH whose earlier row already landed (e.g. a --force
 * cron re-run within the same late period), which is the guarantee the
 * spec needs. It CANNOT catch two dispatches racing before the first
 * database row lands, nor a retried MAIL job re-sending after SMTP
 * handoff (accepted: at-least-once). Within a SINGLE dispatch duplicate
 * database rows cannot occur — the row id is the notification UUID
 * fixed at dispatch, so a retried insert hits the primary key. Racing
 * dispatches mint distinct UUIDs and could each land a row.
 *
 * Constructor parameters:
 *   $loan                       — the loan that went late
 *   $becameLateAt               — snapshot of loan.became_late_at; passed
 *                                 explicitly so the email body always
 *                                 reflects the moment of transition, not
 *                                 a value mutated by a subsequent recovery
 *   $daysLateAtTransition       — same snapshot, from the went_late event
 *                                 metadata (sourced by the caller from
 *                                 LoanEvent.metadata.days_late_at_transition)
 *   $investorTotalAmount        — sum of THIS investor's investments in the
 *                                 loan (an investor can hold multiple
 *                                 positions in the same loan)
 *   $investorOutstandingPrincipal
 *                               — investor's pro-rata share of the
 *                                 outstanding (unpaid) principal at
 *                                 transition time
 */
class LoanWentLateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Loan $loan,
        public ?CarbonInterface $becameLateAt,
        public int $daysLateAtTransition,
        public string $investorTotalAmount,
        public string $investorOutstandingPrincipal,
    ) {}

    /**
     * Channels: skip entirely if we've already sent this notification to
     * this user about this loan in the last 24 h. Returning [] is
     * Laravel's documented opt-out — no mail, no DB row, no log noise.
     */
    public function via(object $notifiable): array
    {
        if ($this->wasRecentlyNotified($notifiable)) {
            return [];
        }

        return ['mail', 'database'];
    }

    /**
     * Look in the existing notifications table for a row of THIS class,
     * for THIS user, mentioning THIS loan AND THIS became_late_at.
     *
     * Match on became_late_at (not on time-window) so a loan that
     * recovered and went late again — therefore has a NEW became_late_at
     * — sends a fresh notification regardless of how recent the last one
     * was. Same late period (same became_late_at) blocks the duplicate.
     *
     * Edge case: if becameLateAt is null (no transition timestamp known),
     * fall back to a calendar 24 h check so we don't spam in a degenerate
     * scenario.
     */
    private function wasRecentlyNotified(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        $base = $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->loan_id', $this->loan->id);

        if ($this->becameLateAt) {
            return $base
                ->whereJsonContains('data->became_late_at', $this->becameLateAt->toIso8601String())
                ->exists();
        }

        // Degenerate case: no transition timestamp known. Fall back to a
        // 24 h cooldown so we don't spam in unexpected data shapes.
        return $base
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Инвестиция #{$this->loan->id} е в закъснение — Vamaasset")
            ->markdown('emails.loan-went-late', [
                'name' => $notifiable->name,
                'loanId' => $this->loan->id,
                'investmentAmount' => $this->investorTotalAmount,
                'daysOverdue' => $this->daysLateAtTransition,
                'outstandingPrincipal' => $this->investorOutstandingPrincipal,
                'portfolioUrl' => config('app.url').'/portfolio',
                'becameLateAt' => $this->becameLateAt,
            ]);
    }

    /**
     * In-app inbox payload. Mirrors the email's data box. No PII.
     *
     * `data->loan_id` and `data->became_late_at` are the keys used by
     * the rate-limit query above — keeping the field names stable here
     * is part of the rate-limit contract.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'loan_went_late',
            'loan_id' => $this->loan->id,
            'became_late_at' => $this->becameLateAt?->toIso8601String(),
            'days_overdue' => $this->daysLateAtTransition,
            'investment_amount' => $this->investorTotalAmount,
            'outstanding_principal' => $this->investorOutstandingPrincipal,
        ];
    }
}
