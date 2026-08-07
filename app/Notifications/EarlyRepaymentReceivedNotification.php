<?php

namespace App\Notifications;

use App\Models\Loan;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor-facing notification: "your loan was closed early by the
 * borrower; your share of outstanding principal + accrued interest
 * through the current schedule boundary has landed in your wallet".
 *
 * Dispatched from the Filament LoanResource's Execute row action
 * (one per investor) AFTER EarlyRepaymentExecutionService::execute()
 * returns successfully (= DB::transaction committed). Mirrors F1/F2
 * "money first, emails second" discipline — notification failure
 * cannot undo the financial distribution.
 *
 * Channels: mail + database (investor's in-app inbox).
 * Queue: ShouldQueue — admin's Execute click must not block on SMTP.
 *
 * PII hygiene: NO borrower data. No loan `type`. Only the loan id,
 * originator name (public marketplace data), the investor's own
 * share, and the execution timestamp. Pinned by the "no PII" test
 * in Step 6 (mirror of F2 LoanBoughtBackNotificationTest).
 *
 * Rate limiting — one notification per (investor, loan, early_repaid_at):
 *   `early_repaid_at` is set exactly once per loan (terminal status —
 *   `repaid` → [] in Loan::ALLOWED_TRANSITIONS), so in normal flow each
 *   (user, loan) pair gets at most ONE notification. The explicit
 *   timestamp key is retained for F1/F2 contract symmetry and defense
 *   against degenerate admin-SQL interventions.
 *
 * Contract guard: toArray() MUST keep `early_repaid_at` as an ISO-8601
 * string. The rate-limit query matches exact string equality. Pinned
 * by EarlyRepaymentReceivedNotificationTest::test_toarray_includes_
 * early_repaid_at_as_iso_string_for_rate_limit_contract (Step 6).
 */
class EarlyRepaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Loan  $loan  the loan that was early-repaid
     * @param  CarbonInterface  $executedAt  loan.early_repaid_at snapshot
     * @param  string  $investorPrincipal  this investor's share of principal (bcmath scale 2)
     * @param  string  $investorInterest  this investor's share of interest (bcmath scale 2)
     * @param  string  $totalReceived  principal + interest (bcmath scale 2)
     */
    public function __construct(
        public Loan $loan,
        public CarbonInterface $executedAt,
        public string $investorPrincipal,
        public string $investorInterest,
        public string $totalReceived,
    ) {}

    /**
     * Channels: skip entirely if already notified for THIS (user, loan,
     * early_repaid_at). Returning [] is Laravel's documented opt-out.
     *
     * Runs at DISPATCH time only (ShouldQueue: Laravel bakes the channel
     * list into per-channel jobs; via() is never re-consulted on the
     * worker). Guards repeated dispatches whose earlier row landed — NOT
     * worker retries of the mail job (accepted: at-least-once; a retried
     * insert of the SAME dispatch is blocked by the UUID primary key,
     * though racing dispatches mint distinct UUIDs).
     */
    public function via(object $notifiable): array
    {
        if ($this->wasRecentlyNotified($notifiable)) {
            return [];
        }

        return ['mail', 'database'];
    }

    /**
     * Dedupe query — same shape as F2 LoanBoughtBackNotification but keyed
     * on early_repaid_at (the F3 terminal moment) instead of bought_back_at.
     *
     * Null executedAt falls back to a 24-hour calendar cooldown (degenerate
     * admin-SQL-intervention path, unreachable in normal flow).
     */
    private function wasRecentlyNotified(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        $base = $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->loan_id', $this->loan->id);

        if ($this->executedAt) {
            return $base
                ->whereJsonContains('data->early_repaid_at', $this->executedAt->toIso8601String())
                ->exists();
        }

        return $base
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Инвестиция #{$this->loan->id} е предсрочно погасена — Vamaasset")
            ->markdown('emails.early-repayment-received', [
                'name' => $notifiable->name,
                'loanId' => $this->loan->id,
                'originatorName' => $this->loan->originator?->name ?? '—',
                'principal' => $this->investorPrincipal,
                'interest' => $this->investorInterest,
                'total' => $this->totalReceived,
                'executedAt' => $this->executedAt,
                'portfolioUrl' => config('app.url').'/portfolio',
            ]);
    }

    /**
     * In-app inbox payload.
     *
     * **Contract** (do not break — via()'s rate-limit query depends):
     *   - `loan_id` present, int.
     *   - `early_repaid_at` present and serialised as ISO-8601 string
     *     (from Carbon::toIso8601String()). Null when the input is null.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'early_repayment_received',
            'loan_id' => $this->loan->id,
            'early_repaid_at' => $this->executedAt?->toIso8601String(),
            'investor_principal' => $this->investorPrincipal,
            'investor_interest' => $this->investorInterest,
            'total_received' => $this->totalReceived,
        ];
    }
}
