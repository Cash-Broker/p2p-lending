<?php

namespace Tests\Feature\Notifications;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Notifications\LoanWentLateNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase F1 Step 6 — LoanWentLateNotification.
 *
 * The first two tests pin the toArray() contract that the rate-limit
 * query in via() relies on. If anyone breaks the contract (drops the
 * key, changes the format), CI fails immediately with a clear message
 * — instead of the bug being discovered in production by an investor
 * complaining about duplicate emails.
 */
class LoanWentLateNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Loan, User} */
    private function makeLoanAndInvestor(): array
    {
        $orig = Originator::create(['name' => 'F1 Notif Test', 'description' => 'X', 'buyback' => false]);
        // Distinctive borrower PII strings — chosen to be unlikely to occur
        // accidentally in template HTML/CSS (no single letters, no common
        // words). The PII-leak assertion checks each is absent from output.
        $borrower = Borrower::create([
            'full_name' => 'Borrower-PII-Sentinel-Name-Zxqj',
            'personal_id' => '9999888877776666',
            'address' => 'Borrower-PII-Sentinel-Address-Vbnm',
            'phone' => '+99999999999',
            'income' => '1000.00',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);
        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000.00', 'funded_amount' => '1000.00',
            'interest_rate' => '12.00', 'interest_rate_annual' => '15.00',
            'term_months' => 6, 'type' => 'consumer', 'status' => 'draft',
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        return [$loan, $user];
    }

    // ─────────────────────────────────────────────────────────────────
    // CONTRACT GUARDS — these protect the rate-limit query in via().
    // If they fail, do NOT just update the test to match new behaviour
    // without first reading the rate-limit logic and confirming the
    // dedupe still works as intended.
    // ─────────────────────────────────────────────────────────────────

    public function test_toarray_includes_became_late_at_as_iso_string_for_rate_limit_contract(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $becameLateAt = Carbon::create(2026, 4, 18, 12, 30, 0);
        $notification = new LoanWentLateNotification($loan, $becameLateAt, 15, '125.00', '98.50');

        $data = $notification->toArray($user);

        $this->assertArrayHasKey('became_late_at', $data,
            'rate-limit query relies on data->became_late_at — toArray() must include it');
        $this->assertIsString($data['became_late_at'],
            'rate-limit uses ISO-8601 string equality — value must be a string');
        $this->assertSame(
            $becameLateAt->toIso8601String(),
            $data['became_late_at'],
            'data->became_late_at format must match what wasRecentlyNotified() queries with',
        );
    }

    public function test_toarray_serialises_null_became_late_at_as_null(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanWentLateNotification($loan, null, 0, '0.00', '0.00');

        $data = $notification->toArray($user);

        $this->assertNull($data['became_late_at'],
            'null becameLateAt must serialise as JSON null, not "" or 0 — the 24h fallback path depends on this');
    }

    // ─────────────────────────────────────────────────────────────────
    // RATE-LIMIT BEHAVIOUR
    // ─────────────────────────────────────────────────────────────────

    public function test_skips_notification_for_same_late_period(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $becameLateAt = Carbon::create(2026, 4, 1);

        // Insert a "previously delivered" notification record by hand
        // (matches what the database channel would have written).
        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => LoanWentLateNotification::class,
            'data' => [
                'type' => 'loan_went_late',
                'loan_id' => $loan->id,
                'became_late_at' => $becameLateAt->toIso8601String(),
                'days_overdue' => 10,
                'investment_amount' => '100.00',
                'outstanding_principal' => '90.00',
            ],
        ]);

        $second = new LoanWentLateNotification($loan, $becameLateAt, 10, '100.00', '90.00');
        $this->assertSame([], $second->via($user),
            'same (loan, became_late_at) tuple already notified → must SKIP (return [])');
    }

    public function test_sends_notification_for_different_late_period(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();

        // First late period: 1 April. Notification recorded.
        $first = Carbon::create(2026, 4, 1);
        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => LoanWentLateNotification::class,
            'data' => [
                'type' => 'loan_went_late', 'loan_id' => $loan->id,
                'became_late_at' => $first->toIso8601String(),
                'days_overdue' => 10, 'investment_amount' => '100.00', 'outstanding_principal' => '90.00',
            ],
        ]);

        // Loan recovered, then went late again on 20 April — different period.
        $second = Carbon::create(2026, 4, 20);
        $notification = new LoanWentLateNotification($loan, $second, 5, '100.00', '85.00');

        $this->assertSame(['mail', 'database'], $notification->via($user),
            'different became_late_at means new late period — must SEND');
    }

    public function test_null_became_late_at_falls_back_to_24h_cooldown(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();

        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => LoanWentLateNotification::class,
            'data' => [
                'type' => 'loan_went_late', 'loan_id' => $loan->id,
                'became_late_at' => null,
                'days_overdue' => 0, 'investment_amount' => '0.00', 'outstanding_principal' => '0.00',
            ],
        ]);

        $degenerate = new LoanWentLateNotification($loan, null, 0, '0.00', '0.00');
        $this->assertSame([], $degenerate->via($user),
            'null becameLateAt within 24h fallback window → SKIP');
    }

    // ─────────────────────────────────────────────────────────────────
    // CONTENT, PII, QUEUE
    // ─────────────────────────────────────────────────────────────────

    public function test_email_subject_and_body_in_bulgarian_with_loan_data(): void
    {
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanWentLateNotification($loan, Carbon::create(2026, 4, 18), 15, '125.00', '98.50');
        $mail = $notification->toMail($user);

        $this->assertStringContainsString('Инвестиция #' . $loan->id . ' е в закъснение', $mail->subject);
        $rendered = $mail->render();
        $this->assertStringContainsString('Здравейте', $rendered);
        $this->assertStringContainsString((string) $loan->id, $rendered);
        $this->assertStringContainsString('125.00', $rendered);
        $this->assertStringContainsString('15', $rendered);
        $this->assertStringContainsString('98.50', $rendered);
        $this->assertStringContainsString('портфолиото', $rendered);
        $this->assertStringContainsString('Закъснението не означава', $rendered);
    }

    public function test_email_carries_no_borrower_pii(): void
    {
        // The borrower record exists with sensitive fields. The email must
        // never leak any of them.
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanWentLateNotification($loan, now(), 5, '100.00', '90.00');

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
    }

    public function test_loan_type_is_not_in_email_per_pii_hygiene_decision(): void
    {
        // Per copy revision: type row was removed from the data table.
        // This test pins that decision so a later "let's add type back"
        // refactor surfaces the policy question, not just slips through.
        [$loan, $user] = $this->makeLoanAndInvestor();
        $notification = new LoanWentLateNotification($loan, now(), 5, '100.00', '90.00');
        $rendered = $notification->toMail($user)->render();

        $this->assertStringNotContainsString('Потребителски', $rendered);
        $this->assertStringNotContainsString('Тип', $rendered);
    }

    public function test_implements_should_queue_so_command_is_not_blocked_by_smtp(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new LoanWentLateNotification(Loan::factory()->create(), now(), 1, '1', '1'),
            'must implement ShouldQueue — command time would otherwise scale with investor count',
        );
    }

    public function test_notification_queues_via_facade_for_users(): void
    {
        Notification::fake();
        [$loan, $user] = $this->makeLoanAndInvestor();
        $becameLateAt = Carbon::create(2026, 4, 18);

        $user->notify(new LoanWentLateNotification($loan, $becameLateAt, 5, '100.00', '90.00'));

        Notification::assertSentTo($user, LoanWentLateNotification::class,
            fn ($n) => $n->loan->id === $loan->id && $n->daysLateAtTransition === 5
        );
    }
}
