<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\EarlyRepaymentReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase F3 Step 3 — Filament LoanResource `execute_early_repayment`
 * row action (admin UI).
 *
 * Tests split into 3 axes:
 *   1. Visibility matrix (which statuses expose the action).
 *   2. Modal content (fresh-calc happy path vs error panel).
 *   3. Action execute — end-to-end money flow + notification dispatch +
 *      exactly-once-per-investor guarantee + idempotency catch.
 */
class LoanResourceEarlyRepaymentActionTest extends TestCase
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

    /**
     * Build a loan in the given status with N unpaid schedules + investors.
     *
     * @return array{Loan, array<int, User>}
     */
    private function makeLoanWithSchedulesAndInvestors(
        string $status = 'active',
        int $investorCount = 2,
        int $scheduleCount = 2,
        bool $generateSchedules = true,
    ): array {
        $orig = Originator::create([
            'name' => 'F3 Action Test ' . uniqid(),
            'description' => 'T',
            'buyback' => false,
        ]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A',
            'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);

        $fundedAmount = bcmul('100.00', (string) $investorCount, 2);
        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => $fundedAmount, 'funded_amount' => $fundedAmount,
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => $scheduleCount, 'type' => 'consumer',
            'status' => $status,
        ]);

        if ($generateSchedules) {
            $principalPer = bcdiv($fundedAmount, (string) $scheduleCount, 2);
            for ($i = 1; $i <= $scheduleCount; $i++) {
                AmortizationSchedule::create([
                    'loan_id' => $loan->id,
                    'due_date' => now()->addDays(15 * $i)->toDateString(),
                    'principal' => $principalPer,
                    'interest' => '10.00',
                    'total' => bcadd($principalPer, '10.00', 2),
                    'status' => 'pending',
                ]);
            }
        }

        $investors = [];
        for ($i = 0; $i < $investorCount; $i++) {
            $u = $this->makeInvestor();
            $u->wallet()->create();
            // Wallet invested bucket has a buffer ABOVE this loan's investment
            // amount so the last-investor-remainder pattern (which may push
            // a cent or two onto the final investor) doesn't violate the
            // wallets CHECK constraint. Mirrors production where an investor's
            // wallet.invested aggregates across many loans.
            Wallet::where('user_id', $u->id)->update(['invested' => '200.00']);
            Investment::create([
                'user_id' => $u->id, 'loan_id' => $loan->id,
                'amount' => '100.00', 'invested_at' => now(),
            ]);
            $investors[] = $u;
        }

        return [$loan->fresh(), $investors];
    }

    // ───────────────────────────────────────────────────────────────
    // VISIBILITY MATRIX
    // ───────────────────────────────────────────────────────────────

    public function test_action_visible_for_active_late_default(): void
    {
        $admin = $this->makeAdmin();
        [$active] = $this->makeLoanWithSchedulesAndInvestors(status: 'active');
        [$late]   = $this->makeLoanWithSchedulesAndInvestors(status: 'late');
        [$default] = $this->makeLoanWithSchedulesAndInvestors(status: 'default');

        $this->actingAs($admin);
        $component = Livewire::test(ListLoans::class);

        $component->assertTableActionVisible('execute_early_repayment', $active);
        $component->assertTableActionVisible('execute_early_repayment', $late);
        $component->assertTableActionVisible('execute_early_repayment', $default);
    }

    public function test_action_hidden_for_terminal_and_pre_activation_statuses(): void
    {
        $admin = $this->makeAdmin();
        [$draft]      = $this->makeLoanWithSchedulesAndInvestors(status: 'draft', generateSchedules: false);
        [$funded]     = $this->makeLoanWithSchedulesAndInvestors(status: 'funded', generateSchedules: false);
        [$repaid]     = $this->makeLoanWithSchedulesAndInvestors(status: 'repaid', generateSchedules: false);
        [$boughtBack] = $this->makeLoanWithSchedulesAndInvestors(status: 'bought_back', generateSchedules: false);

        // Additionally: already early-repaid loan hidden (even in status=repaid)
        [$earlyRepaid] = $this->makeLoanWithSchedulesAndInvestors(status: 'repaid', generateSchedules: false);
        $earlyRepaid->forceFill([
            'early_repaid_at'        => now(),
            'early_repayment_amount' => '100.00',
        ])->save();

        $this->actingAs($admin);
        $component = Livewire::test(ListLoans::class);

        $component->assertTableActionHidden('execute_early_repayment', $draft);
        $component->assertTableActionHidden('execute_early_repayment', $funded);
        $component->assertTableActionHidden('execute_early_repayment', $repaid);
        $component->assertTableActionHidden('execute_early_repayment', $boughtBack);
        $component->assertTableActionHidden('execute_early_repayment', $earlyRepaid);
    }

    // ───────────────────────────────────────────────────────────────
    // MODAL CONTENT — happy-path vs error panel
    // ───────────────────────────────────────────────────────────────

    public function test_modal_renders_fresh_calculation(): void
    {
        // When mounting the action (opening the modal), Filament invokes
        // the modalContent closure. If calc succeeds, the happy-path
        // Blade view renders without throwing.
        [$loan] = $this->makeLoanWithSchedulesAndInvestors();
        $this->actingAs($this->makeAdmin());

        Livewire::test(ListLoans::class)
            ->mountTableAction('execute_early_repayment', $loan)
            ->assertHasNoErrors();
    }

    public function test_modal_renders_error_panel_when_no_unpaid_schedules(): void
    {
        // Loan has no schedules → calculator throws → modal renders
        // error-panel branch instead of breakdown. Still mounts without
        // throwing out of the action closure.
        [$loan] = $this->makeLoanWithSchedulesAndInvestors(generateSchedules: false);
        $this->actingAs($this->makeAdmin());

        Livewire::test(ListLoans::class)
            ->mountTableAction('execute_early_repayment', $loan)
            ->assertHasNoErrors();
    }

    // ───────────────────────────────────────────────────────────────
    // EXECUTE — end-to-end integration
    // ───────────────────────────────────────────────────────────────

    public function test_execute_dispatches_notifications_exactly_once_per_investor(): void
    {
        Notification::fake();
        [$loan, $investors] = $this->makeLoanWithSchedulesAndInvestors(investorCount: 3);
        $admin = $this->makeAdmin();

        $this->actingAs($admin);
        Livewire::test(ListLoans::class)
            ->callTableAction('execute_early_repayment', $loan);

        // Loan closed with F3 markers set
        $loan->refresh();
        $this->assertSame(Loan::STATUS_REPAID, $loan->status);
        $this->assertNotNull($loan->early_repaid_at);
        $this->assertNotNull($loan->early_repayment_amount);

        // LoanEvent written by the service
        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_EARLY_REPAYMENT_COMPLETED)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('active', $event->from_status);
        $this->assertSame('repaid', $event->to_status);
        $this->assertSame($admin->id, $event->triggered_by_user_id);

        // EACH investor notified EXACTLY ONCE. Stronger assertion than
        // assertSentTo — guards against a future bug that duplicates the
        // dispatching loop.
        foreach ($investors as $investor) {
            Notification::assertSentToTimes(
                $investor,
                EarlyRepaymentReceivedNotification::class,
                1,
            );
        }

        // Global count = investor count (no extra sends to non-investors)
        Notification::assertSentTimes(
            EarlyRepaymentReceivedNotification::class,
            count($investors),
        );
    }

    // Note: the EarlyRepaymentAlreadyExecutedException catch path is
    // NOT directly testable here — the action's visibility gate hides
    // it for any loan with early_repaid_at set, and Filament's test
    // helper (callTableAction) requires the action to be visible before
    // invoking it. The idempotency/catch path is already covered at the
    // service level by EarlyRepaymentExecutionServiceTest::
    // test_idempotency_second_call_throws_already_executed_exception.
    // The Filament catch block is a 1-line delegation — visibility gate
    // (tested above in the hidden-matrix) is the primary safeguard.
}
