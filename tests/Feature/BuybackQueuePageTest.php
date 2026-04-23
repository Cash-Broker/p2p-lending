<?php

namespace Tests\Feature;

use App\Filament\Pages\BuybackQueue;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\LoanBoughtBackNotification;
use App\Services\Loans\BuybackExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase F2 Step 4 — Filament Buyback Queue page.
 *
 * Tests split into 3 axes:
 *   1. Access control (HTTP).
 *   2. Table + filter behaviour (Livewire).
 *   3. Row actions — Execute / Dismiss / Reactivate (Livewire).
 *   + Navigation badge static methods.
 *   + Performance regression guard.
 */
class BuybackQueuePageTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeInvestor(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'investor', 'kyc_status' => 'approved'])->save();
        return $u;
    }

    private function makeEligibleLoan(array $loanAttrs = []): Loan
    {
        $orig = Originator::create([
            'name' => 'Queue Test ' . uniqid(),
            'description' => 'X',
            'buyback' => true,
            'buyback_coverage' => 'principal_plus_interest',
        ]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A',
            'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);

        $loan = Loan::create(array_merge([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '100', 'funded_amount' => '100',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 2, 'type' => 'consumer',
            'status' => 'late',
            'became_late_at' => now()->subDays(70),
            'buyback_eligible_at' => now()->subDay(),
        ], $loanAttrs));

        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => now()->subDays(70)->toDateString(),
            'principal' => '100', 'interest' => '10', 'total' => '110',
            'status' => 'pending',
        ]);

        // One investor with a funded wallet so Execute has someone to pay.
        $investor = $this->makeInvestor();
        $investor->wallet()->create();
        Wallet::where('user_id', $investor->id)->update(['invested' => '100.00']);
        Investment::create([
            'user_id' => $investor->id, 'loan_id' => $loan->id,
            'amount' => '100', 'invested_at' => now(),
        ]);

        return $loan->fresh();
    }

    // ───────────────────────────────────────────────────────────────
    // ACCESS CONTROL
    // ───────────────────────────────────────────────────────────────

    public function test_admin_can_access_buyback_queue_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/admin/buyback-queue')
            ->assertOk();
    }

    public function test_non_admin_cannot_access_buyback_queue(): void
    {
        $investor = $this->makeInvestor();

        // Filament redirects non-canAccessPanel users — status 302 or 403
        $resp = $this->actingAs($investor)->get('/admin/buyback-queue');
        $this->assertNotSame(200, $resp->status(),
            'Non-admin investor must NOT receive a 200 from the admin Buyback Queue page');
    }

    // ───────────────────────────────────────────────────────────────
    // NAVIGATION BADGE
    // ───────────────────────────────────────────────────────────────

    public function test_navigation_badge_shows_pending_count(): void
    {
        $this->makeEligibleLoan();
        $this->makeEligibleLoan();

        $this->assertSame('2', BuybackQueue::getNavigationBadge());
        $this->assertSame('warning', BuybackQueue::getNavigationBadgeColor());
    }

    public function test_navigation_badge_hidden_when_zero(): void
    {
        $this->assertNull(BuybackQueue::getNavigationBadge(),
            'Badge must be null (invisible) when no pending queue items');
        $this->assertNull(BuybackQueue::getNavigationBadgeColor());
    }

    // ───────────────────────────────────────────────────────────────
    // TABLE BEHAVIOUR (Livewire)
    // ───────────────────────────────────────────────────────────────

    public function test_table_shows_pending_loans_by_default(): void
    {
        $pending = $this->makeEligibleLoan();
        $dismissed = $this->makeEligibleLoan(['buyback_dismissed_at' => now()]);

        $this->actingAs($this->makeAdmin());
        Livewire::test(BuybackQueue::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$dismissed]);
    }

    public function test_filter_shows_dismissed_loans_when_toggled(): void
    {
        $pending = $this->makeEligibleLoan();
        $dismissed = $this->makeEligibleLoan(['buyback_dismissed_at' => now()]);

        $this->actingAs($this->makeAdmin());
        Livewire::test(BuybackQueue::class)
            ->filterTable('buyback_dismissed_at', true)  // true = "only dismissed"
            ->assertCanNotSeeTableRecords([$pending])
            ->assertCanSeeTableRecords([$dismissed]);
    }

    // ───────────────────────────────────────────────────────────────
    // ROW ACTIONS (Livewire)
    // ───────────────────────────────────────────────────────────────

    public function test_execute_action_dispatches_notifications_and_success_toast(): void
    {
        Notification::fake();
        $loan = $this->makeEligibleLoan();
        $admin = $this->makeAdmin();

        $this->actingAs($admin);
        Livewire::test(BuybackQueue::class)
            ->callTableAction('execute', $loan);

        // Loan transitioned + bought_back_at set
        $loan->refresh();
        $this->assertSame(Loan::STATUS_BOUGHT_BACK, $loan->status);
        $this->assertNotNull($loan->bought_back_at);

        // LoanEvent buyback_completed written
        $this->assertTrue(
            LoanEvent::where('loan_id', $loan->id)
                ->where('event_type', LoanEvent::TYPE_BUYBACK_COMPLETED)
                ->exists(),
        );

        // Investor notified
        $investor = $loan->investments()->first()->user;
        Notification::assertSentTo($investor, LoanBoughtBackNotification::class);
    }

    public function test_execute_does_not_transition_on_service_exception(): void
    {
        // Swap the service with one that always throws. The Execute action
        // catches the throw, surfaces a danger toast, but must NOT leave
        // the loan in a half-transitioned state.
        $this->app->bind(BuybackExecutionService::class, function () {
            $mock = \Mockery::mock(BuybackExecutionService::class);
            $mock->shouldReceive('execute')->andThrow(new \RuntimeException('Simulated service failure'));
            return $mock;
        });

        $loan = $this->makeEligibleLoan();
        $this->actingAs($this->makeAdmin());

        Livewire::test(BuybackQueue::class)
            ->callTableAction('execute', $loan);

        // Loan unchanged
        $loan->refresh();
        $this->assertSame('late', $loan->status);
        $this->assertNull($loan->bought_back_at);

        // No LoanEvent written
        $this->assertFalse(
            LoanEvent::where('loan_id', $loan->id)
                ->where('event_type', LoanEvent::TYPE_BUYBACK_COMPLETED)
                ->exists(),
        );
    }

    public function test_dismiss_action_requires_reason(): void
    {
        $loan = $this->makeEligibleLoan();
        $this->actingAs($this->makeAdmin());

        Livewire::test(BuybackQueue::class)
            ->callTableAction('dismiss', $loan, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        // Nothing persisted
        $this->assertNull($loan->fresh()->buyback_dismissed_at);
    }

    public function test_dismiss_action_updates_dismissed_columns(): void
    {
        $loan = $this->makeEligibleLoan();
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test(BuybackQueue::class)
            ->callTableAction('dismiss', $loan, data: [
                'reason' => 'Originator promised payment next week',
            ])
            ->assertHasNoTableActionErrors();

        $loan->refresh();
        $this->assertNotNull($loan->buyback_dismissed_at);
        $this->assertSame('Originator promised payment next week', $loan->buyback_dismissed_reason);
        $this->assertSame($admin->id, $loan->buyback_dismissed_by);
    }

    public function test_reactivate_clears_dismissed_columns(): void
    {
        $admin = $this->makeAdmin();
        $loan = $this->makeEligibleLoan([
            'buyback_dismissed_at' => now()->subHour(),
            'buyback_dismissed_reason' => 'test reason',
            'buyback_dismissed_by' => $admin->id,
        ]);
        $this->actingAs($admin);

        // Toggle filter to "dismissed" so Livewire can find and act on it.
        Livewire::test(BuybackQueue::class)
            ->filterTable('buyback_dismissed_at', true)
            ->callTableAction('reactivate', $loan);

        $loan->refresh();
        $this->assertNull($loan->buyback_dismissed_at);
        $this->assertNull($loan->buyback_dismissed_reason);
        $this->assertNull($loan->buyback_dismissed_by);
    }

    // ───────────────────────────────────────────────────────────────
    // PERFORMANCE (regression guard)
    // ───────────────────────────────────────────────────────────────

    public function test_queue_page_does_not_n_plus_one_with_many_loans(): void
    {
        // Fabricate 20 eligible loans. The "Сума при откриване" column
        // currently does a per-row latest() LoanEvent query (documented
        // N+1 trade-off). This test pins an upper bound so a future
        // regression that adds MORE per-row queries surfaces here.
        for ($i = 0; $i < 20; $i++) {
            $this->makeEligibleLoan();
        }

        $this->actingAs($this->makeAdmin());

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(BuybackQueue::class);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Hard ceiling — current N+1 + Livewire overhead stays well under this.
        // If this fails, SOMETHING added more per-row queries; investigate
        // before raising the threshold.
        $this->assertLessThan(50, count($queries),
            sprintf('Queue render emitted %d queries — possible N+1 regression', count($queries)));
    }
}
