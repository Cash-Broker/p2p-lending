<?php

namespace Tests\Unit\Notifications;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Notifications\EarlyRepaymentReceivedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase F3 Step 5 — EarlyRepaymentReceivedNotification.
 *
 * Same structure as F2 LoanBoughtBackNotificationTest:
 *   - Contract guards FIRST (protect via()'s dedupe query).
 *   - Rate-limit behaviour (send/skip/fallback).
 *   - Content + PII hygiene.
 *   - Queue discipline.
 */
class EarlyRepaymentReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Loan, User} */
    private function makeLoanAndInvestor(): array
    {
        $orig = Originator::create([
            'name' => 'F3 Notif Test',
            'description' => 'X',
            'buyback' => false,
        ]);
        $borrower = Borrower::create([
            'full_name' => 'Borrower-PII-Sentinel-Name-Kmnb',
            'personal_id' => '5544332211009988',
            'address' => 'Borrower-PII-Sentinel-Address-Pqrs',
            'phone' => '+77777777777',
            'income' => '2000.00',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'A', 'region' => 'Z',
            'loan_purpose' => 'Z', 'collateral_type' => '—', 'age_group' => '50-60',
        ]);
        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000.00', 'funded_amount' => '1000.00',
            'interest_rate' => '12.00', 'interest_rate_annual' => '15.00',
            'term_months' => 12, 'type' => 'consumer', 'status' => 'draft',
        ]);
        $loan->setRelation('originator', $orig);
        $user = User::factory()->create(['email_verified_at' => now()]);
        return [$loan, $user];
    }

    // ─────────────────────────────────────────────────────────────────
    // CONTRACT GUARDS — protect the rate-limit query in via().
    // ─────────────────────────────────────────────────────────────────

    public function test_toarray_includes_early_repaid_at_as_iso_string_for_rate_limit_contract(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $executedAt = Carbon::create(2026, 4, 25, 10, 0, 0);
        $notification = new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: $executedAt,
            investorPrincipal: '100.00',
            investorInterest: '12.50',
            totalReceived: '112.50',
        );

        $data = $notification->toArray($user);

        $this->assertArrayHasKey('early_repaid_at', $data,
            'rate-limit query relies on data->early_repaid_at — toArray() must include it');
        $this->assertIsString($data['early_repaid_at'],
            'rate-limit uses ISO-8601 string equality — value must be a string');
        $this->assertSame(
            $executedAt->toIso8601String(),
            $data['early_repaid_at'],
            'data->early_repaid_at format must match what wasRecentlyNotified() queries with',
        );
    }

    public function test_toarray_serialises_null_early_repaid_at_as_null(): void
    {
        $this->markTestSkipped(
            'executedAt is non-nullable by constructor type hint in F3 — '
            . 'no null-serialisation path to guard. Placeholder for F1/F2 symmetry.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // RATE-LIMIT BEHAVIOUR
    // ─────────────────────────────────────────────────────────────────

    public function test_skips_notification_for_same_early_repaid_at(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $executedAt = Carbon::create(2026, 4, 25);

        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => EarlyRepaymentReceivedNotification::class,
            'data' => [
                'type' => 'early_repayment_received',
                'loan_id' => $loan->id,
                'early_repaid_at' => $executedAt->toIso8601String(),
                'investor_principal' => '100.00',
                'investor_interest' => '10.00',
                'total_received' => '110.00',
            ],
        ]);

        $second = new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: $executedAt,
            investorPrincipal: '100.00',
            investorInterest: '10.00',
            totalReceived: '110.00',
        );

        $this->assertSame([], $second->via($user),
            'same (user, loan, early_repaid_at) already notified → must SKIP');
    }

    public function test_sends_notification_for_different_early_repaid_at(): void
    {
        // Degenerate scenario — kept for F1/F2 contract symmetry. A
        // different early_repaid_at ISO string would only arise via
        // raw-SQL admin intervention (loan.early_repaid_at is normally
        // terminal-once).
        [$loan, $user] = $this->makeLoanAndInvestor();

        $first = Carbon::create(2026, 4, 20);
        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => EarlyRepaymentReceivedNotification::class,
            'data' => [
                'type' => 'early_repayment_received', 'loan_id' => $loan->id,
                'early_repaid_at' => $first->toIso8601String(),
                'investor_principal' => '100.00',
                'investor_interest' => '10.00',
                'total_received' => '110.00',
            ],
        ]);

        $second = Carbon::create(2026, 4, 25);
        $notification = new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: $second,
            investorPrincipal: '50.00',
            investorInterest: '5.00',
            totalReceived: '55.00',
        );

        $this->assertSame(['mail', 'database'], $notification->via($user),
            'different early_repaid_at → fresh event → must SEND');
    }

    public function test_null_early_repaid_at_falls_back_to_24h_cooldown(): void
    {
        $this->markTestSkipped(
            'null executedAt is unreachable via the typed constructor. '
            . 'Fallback code kept for symmetry with F1/F2 and ops-backfill defense.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // CONTENT, PII, QUEUE
    // ─────────────────────────────────────────────────────────────────

    public function test_email_subject_and_body_in_bulgarian_with_loan_data(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: Carbon::create(2026, 4, 25),
            investorPrincipal: '487.50',
            investorInterest: '62.75',
            totalReceived: '550.25',
        );

        $mail = $notification->toMail($user);

        $this->assertStringContainsString(
            "Инвестиция #{$loan->id} е предсрочно погасена",
            $mail->subject,
        );

        $rendered = $mail->render();
        $this->assertStringContainsString('Здравейте', $rendered);
        $this->assertStringContainsString((string) $loan->id, $rendered);
        $this->assertStringContainsString('F3 Notif Test', $rendered, 'originator name public');
        $this->assertStringContainsString('487.50', $rendered, 'investor principal');
        $this->assertStringContainsString('62.75', $rendered, 'investor interest');
        $this->assertStringContainsString('550.25', $rendered, 'investor total');
        $this->assertStringContainsString('25.04.2026', $rendered, 'executedAt formatted');
        $this->assertStringContainsString('портфолиото', $rendered);
        $this->assertStringContainsString('Предсрочно погасяване означава', $rendered,
            'educational reassurance paragraph');
    }

    public function test_email_carries_no_borrower_pii(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: now(),
            investorPrincipal: '100.00',
            investorInterest: '10.00',
            totalReceived: '110.00',
        );

        $rendered = $notification->toMail($user)->render();

        $borrower = $loan->borrower;
        $this->assertStringNotContainsString($borrower->full_name, $rendered);
        $this->assertStringNotContainsString($borrower->personal_id, $rendered);
        $this->assertStringNotContainsString($borrower->address, $rendered);
        $this->assertStringNotContainsString($borrower->phone, $rendered);

        $data = $notification->toArray($user);
        $this->assertArrayNotHasKey('borrower_id', $data);
        $this->assertArrayNotHasKey('full_name', $data);
        $this->assertArrayNotHasKey('personal_id', $data);
        $this->assertArrayNotHasKey('address', $data);
        $this->assertArrayNotHasKey('phone', $data);
    }

    public function test_loan_type_is_not_in_email_per_pii_hygiene_decision(): void
    {
        // F1/F2 PII hygiene: loan `type` NOT surfaced to investors via
        // notification email. This pins that decision for F3 too.
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: now(),
            investorPrincipal: '100.00',
            investorInterest: '10.00',
            totalReceived: '110.00',
        );
        $rendered = $notification->toMail($user)->render();

        $this->assertStringNotContainsString('Потребителски', $rendered);
        $this->assertStringNotContainsString('Тип кредит', $rendered);
    }

    public function test_implements_should_queue(): void
    {
        [$loan] = $this->makeLoanAndInvestor();
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new EarlyRepaymentReceivedNotification(
                loan: $loan,
                executedAt: now(),
                investorPrincipal: '1',
                investorInterest: '1',
                totalReceived: '2',
            ),
            'must implement ShouldQueue — admin Execute click would block on SMTP otherwise',
        );
    }

    public function test_notification_queues_via_facade_for_users(): void
    {
        Notification::fake();
        [$loan, $user] = $this->makeLoanAndInvestor();
        $executedAt = Carbon::create(2026, 4, 25);

        $user->notify(new EarlyRepaymentReceivedNotification(
            loan: $loan,
            executedAt: $executedAt,
            investorPrincipal: '100.00',
            investorInterest: '10.00',
            totalReceived: '110.00',
        ));

        Notification::assertSentTo(
            $user,
            EarlyRepaymentReceivedNotification::class,
            fn ($n) => $n->loan->id === $loan->id
                && $n->totalReceived === '110.00',
        );
    }
}
