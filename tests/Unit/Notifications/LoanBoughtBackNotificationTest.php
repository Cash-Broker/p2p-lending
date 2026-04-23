<?php

namespace Tests\Unit\Notifications;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Notifications\LoanBoughtBackNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase F2 Step 6 — LoanBoughtBackNotification.
 *
 * Same structure as F1 LoanWentLateNotificationTest:
 *   - Contract guards FIRST (protect via()'s dedupe query).
 *   - Rate-limit behaviour (send/skip/fallback).
 *   - Content + PII hygiene.
 *   - Queue discipline.
 */
class LoanBoughtBackNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Loan, User} */
    private function makeLoanAndInvestor(): array
    {
        $orig = Originator::create([
            'name' => 'F2 Notif Test',
            'description' => 'X',
            'buyback' => true,
        ]);
        // Distinctive borrower PII sentinels — unlikely to collide with template HTML/CSS.
        $borrower = Borrower::create([
            'full_name' => 'Borrower-PII-Sentinel-Name-Aqzx',
            'personal_id' => '9988776655443322',
            'address' => 'Borrower-PII-Sentinel-Address-Qxzm',
            'phone' => '+88888888888',
            'income' => '1500.00',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'C', 'region' => 'Y',
            'loan_purpose' => 'Y', 'collateral_type' => '—', 'age_group' => '40-50',
        ]);
        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000.00', 'funded_amount' => '1000.00',
            'interest_rate' => '12.00', 'interest_rate_annual' => '15.00',
            'term_months' => 6, 'type' => 'consumer', 'status' => 'draft',
        ]);
        $loan->setRelation('originator', $orig);
        $user = User::factory()->create(['email_verified_at' => now()]);
        return [$loan, $user];
    }

    // ─────────────────────────────────────────────────────────────────
    // CONTRACT GUARDS — protect the rate-limit query in via().
    // If they fail, do NOT update the test to match new behaviour
    // without first reading wasRecentlyNotified() and confirming
    // the dedupe still works as intended.
    // ─────────────────────────────────────────────────────────────────

    public function test_toarray_includes_bought_back_at_as_iso_string_for_rate_limit_contract(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $boughtBackAt = Carbon::create(2026, 4, 24, 10, 0, 0);
        $notification = new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: $boughtBackAt,
            investorPrincipal: '100.00',
            investorInterest: '12.50',
            totalReceived: '112.50',
            coverageType: 'principal_plus_interest',
        );

        $data = $notification->toArray($user);

        $this->assertArrayHasKey('bought_back_at', $data,
            'rate-limit query relies on data->bought_back_at — toArray() must include it');
        $this->assertIsString($data['bought_back_at'],
            'rate-limit uses ISO-8601 string equality — value must be a string');
        $this->assertSame(
            $boughtBackAt->toIso8601String(),
            $data['bought_back_at'],
            'data->bought_back_at format must match what wasRecentlyNotified() queries with',
        );
    }

    public function test_toarray_serialises_null_bought_back_at_as_null(): void
    {
        // bought_back_at is NON-NULLABLE per the constructor type hint
        // (CarbonInterface). BuybackExecutionService always sets it at
        // execution time. A null path could only emerge via raw-SQL
        // admin intervention — a degenerate scenario we don't actively
        // support but want to document with a placeholder test.
        //
        // This skipped test preserves symmetry with F1's
        // LoanWentLateNotificationTest::test_toarray_serialises_null_
        // became_late_at_as_null. If a future change adds a nullable
        // constructor path, remove the skip and assert the null
        // serialisation contract here.
        $this->markTestSkipped(
            'bought_back_at is non-nullable by constructor type hint in F2 — '
            . 'no null-serialisation path to guard. Placeholder for F1 symmetry.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // RATE-LIMIT BEHAVIOUR
    // ─────────────────────────────────────────────────────────────────

    public function test_skips_notification_for_same_bought_back_at(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $boughtBackAt = Carbon::create(2026, 4, 20);

        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => LoanBoughtBackNotification::class,
            'data' => [
                'type' => 'loan_bought_back',
                'loan_id' => $loan->id,
                'bought_back_at' => $boughtBackAt->toIso8601String(),
                'coverage_type' => 'principal_plus_interest',
                'investor_principal' => '100.00',
                'investor_interest' => '12.50',
                'total_received' => '112.50',
            ],
        ]);

        $second = new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: $boughtBackAt,
            investorPrincipal: '100.00',
            investorInterest: '12.50',
            totalReceived: '112.50',
            coverageType: 'principal_plus_interest',
        );

        $this->assertSame([], $second->via($user),
            'same (user, loan, bought_back_at) already notified → must SKIP');
    }

    public function test_sends_notification_for_different_bought_back_at(): void
    {
        // Degenerate scenario for contract symmetry with F1: an admin raw-SQL
        // intervention clears bought_back_at and re-executes; the new
        // bought_back_at timestamp unlocks a fresh delivery.
        [$loan, $user] = $this->makeLoanAndInvestor();

        $first = Carbon::create(2026, 4, 20, 10, 0, 0);
        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => LoanBoughtBackNotification::class,
            'data' => [
                'type' => 'loan_bought_back', 'loan_id' => $loan->id,
                'bought_back_at' => $first->toIso8601String(),
                'coverage_type' => 'principal_only',
                'investor_principal' => '100.00', 'investor_interest' => '0.00',
                'total_received' => '100.00',
            ],
        ]);

        $second = Carbon::create(2026, 4, 25, 14, 30, 0);
        $notification = new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: $second,
            investorPrincipal: '50.00',
            investorInterest: '5.00',
            totalReceived: '55.00',
            coverageType: 'principal_plus_interest',
        );

        $this->assertSame(['mail', 'database'], $notification->via($user),
            'different bought_back_at → fresh buyback event → must SEND');
    }

    public function test_null_bought_back_at_falls_back_to_24h_cooldown(): void
    {
        // Same reason as the null-serialisation skip above: the constructor
        // type hint (non-nullable CarbonInterface) makes this branch
        // unreachable in normal code paths. The defensive null-fallback
        // code in via() is kept for future-proofing (ops-backfill edge
        // case). If a nullable path is introduced later, replace the skip
        // with a proper reflection-based null assignment + assertion.
        $this->markTestSkipped(
            'null bought_back_at is unreachable via the typed constructor. '
            . 'Fallback code kept for symmetry with F1 and ops-backfill defense.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // CONTENT, PII, QUEUE
    // ─────────────────────────────────────────────────────────────────

    public function test_email_subject_and_body_in_bulgarian_with_loan_data(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: Carbon::create(2026, 4, 24),
            investorPrincipal: '487.50',
            investorInterest: '62.75',
            totalReceived: '550.25',
            coverageType: 'principal_plus_interest',
        );

        $mail = $notification->toMail($user);

        $this->assertStringContainsString("Инвестиция #{$loan->id} е изкупена", $mail->subject);

        $rendered = $mail->render();
        $this->assertStringContainsString('Здравейте', $rendered);
        $this->assertStringContainsString((string) $loan->id, $rendered);
        $this->assertStringContainsString('F2 Notif Test', $rendered, 'originator name public');
        $this->assertStringContainsString('Главница + лихва', $rendered, 'coverage label rendered in BG');
        $this->assertStringContainsString('487.50', $rendered, 'investor principal');
        $this->assertStringContainsString('62.75', $rendered, 'investor interest');
        $this->assertStringContainsString('550.25', $rendered, 'investor total');
        $this->assertStringContainsString('24.04.2026', $rendered, 'bought_back_at formatted');
        $this->assertStringContainsString('портфолиото', $rendered);
        $this->assertStringContainsString('Buyback е механизъм', $rendered, 'educational reassurance');
    }

    public function test_email_carries_no_borrower_pii(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: now(),
            investorPrincipal: '100.00',
            investorInterest: '10.00',
            totalReceived: '110.00',
            coverageType: 'principal_plus_interest',
        );

        $mail = $notification->toMail($user);
        $rendered = $mail->render();

        $borrower = $loan->borrower;
        $this->assertStringNotContainsString($borrower->full_name, $rendered);
        $this->assertStringNotContainsString($borrower->personal_id, $rendered);
        $this->assertStringNotContainsString($borrower->address, $rendered);
        $this->assertStringNotContainsString($borrower->phone, $rendered);

        // Database channel payload must also be PII-free.
        $data = $notification->toArray($user);
        $this->assertArrayNotHasKey('borrower_id', $data);
        $this->assertArrayNotHasKey('full_name', $data);
        $this->assertArrayNotHasKey('personal_id', $data);
        $this->assertArrayNotHasKey('address', $data);
        $this->assertArrayNotHasKey('phone', $data);
    }

    public function test_loan_type_is_not_in_email_per_pii_hygiene_decision(): void
    {
        // F1 removed loan `type` from the "went late" email (PII hygiene).
        // F2's "bought back" email carries the same decision. This test
        // pins it so a future "let's add type back" refactor surfaces the
        // policy question instead of slipping through unnoticed.
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: now(),
            investorPrincipal: '100.00',
            investorInterest: '10.00',
            totalReceived: '110.00',
            coverageType: 'principal_plus_interest',
        );
        $rendered = $notification->toMail($user)->render();

        // "Потребителски" is the BG label for loan type 'consumer' (see
        // InvestmentDetailPage.vue typeLabels). If this word appears, the
        // email has regressed the F1 PII-hygiene decision.
        $this->assertStringNotContainsString('Потребителски', $rendered);
        $this->assertStringNotContainsString('Тип кредит', $rendered);
    }

    public function test_implements_should_queue(): void
    {
        [$loan] = $this->makeLoanAndInvestor();
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new LoanBoughtBackNotification(
                loan: $loan,
                boughtBackAt: now(),
                investorPrincipal: '1',
                investorInterest: '1',
                totalReceived: '2',
                coverageType: 'principal_plus_interest',
            ),
            'must implement ShouldQueue — admin Execute click would otherwise block on SMTP per investor',
        );
    }

    public function test_notification_queues_via_facade_for_users(): void
    {
        Notification::fake();
        [$loan, $user] = $this->makeLoanAndInvestor();
        $boughtBackAt = Carbon::create(2026, 4, 24);

        $user->notify(new LoanBoughtBackNotification(
            loan: $loan,
            boughtBackAt: $boughtBackAt,
            investorPrincipal: '100.00',
            investorInterest: '12.50',
            totalReceived: '112.50',
            coverageType: 'principal_plus_interest',
        ));

        Notification::assertSentTo(
            $user,
            LoanBoughtBackNotification::class,
            fn ($n) => $n->loan->id === $loan->id
                && $n->totalReceived === '112.50'
                && $n->coverageType === 'principal_plus_interest',
        );
    }
}
