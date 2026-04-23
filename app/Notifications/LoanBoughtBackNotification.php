<?php

namespace App\Notifications;

use App\Models\Loan;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor-facing notification: "your loan was bought back by the originator".
 *
 * Dispatched from the Filament Buyback Queue's Execute row action (one
 * per investor) AFTER the BuybackExecutionService::execute() DB
 * transaction commits. Mirrors F1's "money first, emails second" —
 * notification failure cannot undo the financial distribution.
 *
 * Channels: mail + database (investor's in-app inbox).
 * Queue: ShouldQueue — admin's Execute click shouldn't block on SMTP.
 *
 * PII hygiene: NO borrower data. No loan `type`. Only the loan id, the
 * originator name (public marketplace data), the coverage type, and the
 * investor's own share. Pinned by the "no PII" test in Step 7.
 *
 * Rate limiting — one notification per (investor, loan, bought_back_at):
 *   `bought_back_at` is set exactly once per loan (terminal status per
 *   F2 Q3 — buyback_back → []), so in normal flow each (user, loan)
 *   gets at most ONE notification.
 *   We still match on bought_back_at (not just user+loan) for contract
 *   symmetry with F1's became_late_at dedupe and defense against
 *   degenerate admin interventions (raw SQL that nulls bought_back_at).
 *   Null bought_back_at → 24 h cooldown fallback (degenerate path).
 *
 * Contract guard: `toArray()` MUST keep `bought_back_at` as an
 * ISO-8601 string. The rate-limit query matches exact string equality.
 * Pinned by
 * LoanBoughtBackNotificationTest::test_toarray_includes_bought_back_at
 * _as_iso_string_for_rate_limit_contract (Step 7).
 */
class LoanBoughtBackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Loan                 $loan                 the loan that was bought back
     * @param  CarbonInterface      $boughtBackAt         timestamp of execution (loan.bought_back_at)
     * @param  string               $investorPrincipal    this investor's share of principal (bcmath scale 2)
     * @param  string               $investorInterest     this investor's share of interest (bcmath scale 2)
     * @param  string               $totalReceived        principal + interest (bcmath scale 2)
     * @param  string               $coverageType         'principal_only' | 'principal_plus_interest'
     */
    public function __construct(
        public Loan $loan,
        public CarbonInterface $boughtBackAt,
        public string $investorPrincipal,
        public string $investorInterest,
        public string $totalReceived,
        public string $coverageType,
    ) {}

    /**
     * Channels: skip entirely if already notified for THIS (user, loan,
     * bought_back_at). Returning [] is Laravel's documented opt-out.
     */
    public function via(object $notifiable): array
    {
        if ($this->wasRecentlyNotified($notifiable)) {
            return [];
        }
        return ['mail', 'database'];
    }

    /**
     * Dedupe query — same shape as F1's LoanWentLateNotification but
     * keyed on bought_back_at (the terminal moment) instead of
     * became_late_at (the late-period moment).
     *
     * Null bought_back_at: extremely unlikely path (admin raw-SQL
     * intervention), fall back to a 24 h cooldown so a runaway loop
     * cannot spam an investor's inbox.
     */
    private function wasRecentlyNotified(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        $base = $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->loan_id', $this->loan->id);

        if ($this->boughtBackAt) {
            return $base
                ->whereJsonContains('data->bought_back_at', $this->boughtBackAt->toIso8601String())
                ->exists();
        }

        return $base
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Инвестиция #{$this->loan->id} е изкупена — P2P Invest")
            ->markdown('emails.loan-bought-back', [
                'name'              => $notifiable->name,
                'loanId'            => $this->loan->id,
                'originatorName'    => $this->loan->originator?->name ?? '—',
                'coverageLabel'     => $this->coverageLabel(),
                'principal'         => $this->investorPrincipal,
                'interest'          => $this->investorInterest,
                'total'             => $this->totalReceived,
                'boughtBackAt'      => $this->boughtBackAt,
                'portfolioUrl'      => config('app.url') . '/portfolio',
            ]);
    }

    /**
     * In-app inbox payload.
     *
     * **Contract** (do not break — via()'s rate-limit query depends):
     *   - `loan_id` present, int.
     *   - `bought_back_at` present and serialised as ISO-8601 string
     *     (from Carbon::toIso8601String()). Null when the input is null.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'loan_bought_back',
            'loan_id' => $this->loan->id,
            'bought_back_at' => $this->boughtBackAt?->toIso8601String(),
            'coverage_type' => $this->coverageType,
            'investor_principal' => $this->investorPrincipal,
            'investor_interest' => $this->investorInterest,
            'total_received' => $this->totalReceived,
        ];
    }

    private function coverageLabel(): string
    {
        return match ($this->coverageType) {
            'principal_only'            => 'Само главница',
            'principal_plus_interest'   => 'Главница + лихва',
            default                     => $this->coverageType,
        };
    }
}
