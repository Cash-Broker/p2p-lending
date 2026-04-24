<?php

namespace Tests\Audit;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\User;
use App\Services\Loans\LoanStatusUpdaterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 P3-F5 — `active → repaid` auto-closure when all schedules
 * paid. Tests the new `LoanStatusUpdaterService::autoRepayCompletedLoans`
 * method + its wire-up into the `loans:process-late` command.
 *
 * Coverage:
 *   1. Active loan with every schedule paid → transitions to REPAID + LoanEvent written.
 *   2. Active loan 11/12 paid → stays ACTIVE (not fully complete).
 *   3. Active loan with a `late` schedule → NOT auto-repaid (defensive — goes through the existing late path).
 *   4. Dry-run via artisan: no transitions executed.
 *   5. `--loan=ID`: only the targeted loan gets checked.
 *   6. LoanEvent metadata correctness.
 *   7. Multiple completed loans in a single run all transition.
 */
class Phase3AutoRepayTest extends TestCase
{
    use RefreshDatabase;

    public function test_1_active_loan_all_paid_transitions_to_repaid(): void
    {
        $loan = $this->makeActiveLoanWithAllPaidSchedules();

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
    }

    public function test_2_active_loan_with_one_unpaid_schedule_stays_active(): void
    {
        $loan = $this->makeActiveLoanWithSchedules(12);
        // Mark 11 of 12 as paid.
        $loan->amortizationSchedules()
            ->orderBy('due_date')
            ->limit(11)
            ->update(['status' => 'paid', 'paid_at' => now()]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'one pending schedule must block auto-repay');
    }

    public function test_3_active_loan_with_late_schedule_NOT_auto_repaid(): void
    {
        // Defensive: if a schedule is in `late` status, the loan has
        // delinquency. Auto-repay on completion would be incorrect —
        // the late path (or admin action) should handle it.
        $loan = $this->makeActiveLoanWithSchedules(3);
        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
        // Mark 2 paid, 1 late. paid_count != total → already covered by the
        // non-paid count check, but also confirms `late` status isn't
        // accidentally treated as completion.
        $schedules[0]->update(['status' => 'paid', 'paid_at' => now()]);
        $schedules[1]->update(['status' => 'paid', 'paid_at' => now()]);
        $schedules[2]->update(['status' => 'late', 'days_late' => 5, 'became_late_at' => now()->subDays(5)]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'late schedule must block auto-repay');
    }

    public function test_4_dry_run_via_artisan_does_not_transition(): void
    {
        $loan = $this->makeActiveLoanWithAllPaidSchedules();

        // --force bypasses the `late_check_enabled` platform setting
        // which defaults to `true` here anyway; explicit for robustness.
        Artisan::call('loans:process-late', ['--dry-run' => true, '--force' => true]);

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'dry-run must NOT transition loans');
    }

    public function test_5_targeted_loan_flag_only_checks_that_loan(): void
    {
        $loanA = $this->makeActiveLoanWithAllPaidSchedules();
        $loanB = $this->makeActiveLoanWithAllPaidSchedules();

        Artisan::call('loans:process-late', ['--loan' => $loanA->id, '--force' => true]);

        $this->assertSame(Loan::STATUS_REPAID, $loanA->fresh()->status,
            'targeted loan gets transitioned');
        $this->assertSame(Loan::STATUS_ACTIVE, $loanB->fresh()->status,
            'non-targeted loan untouched');
    }

    public function test_6_loan_event_metadata_correct(): void
    {
        $loan = $this->makeActiveLoanWithAllPaidSchedules();
        $scheduleCount = $loan->amortizationSchedules()->count();

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_STATUS_CHANGED)
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'auto-repay must write a loan_event');
        $this->assertSame(Loan::STATUS_ACTIVE, $event->from_status);
        $this->assertSame(Loan::STATUS_REPAID, $event->to_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_SYSTEM, $event->triggered_by);
        $this->assertNull($event->triggered_by_user_id);

        $meta = $event->metadata;
        $this->assertSame($scheduleCount, $meta['total_installments_paid']);
        $this->assertSame($loan->originator_id, $meta['originator_id']);
        $this->assertTrue($meta['auto_transitioned']);
        $this->assertSame('all_schedules_paid', $meta['transition_reason']);
    }

    public function test_7_multiple_completed_loans_all_transition_in_single_run(): void
    {
        $loans = [
            $this->makeActiveLoanWithAllPaidSchedules(),
            $this->makeActiveLoanWithAllPaidSchedules(),
            $this->makeActiveLoanWithAllPaidSchedules(),
        ];

        $result = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertCount(3, $result['auto_repaid']);
        foreach ($loans as $loan) {
            $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
            $this->assertContains($loan->id, $result['auto_repaid']);
        }
    }

    // ──────────────────────────────────────────────────────────────

    /**
     * Build an active loan whose amortization schedule is entirely
     * marked as paid. Uses raw SQL to stamp `paid` on all rows to
     * avoid the service-layer wallet side effects (we're testing the
     * auto-repay transition, not the repayment distribution).
     */
    private function makeActiveLoanWithAllPaidSchedules(int $termMonths = 6): Loan
    {
        $loan = $this->makeActiveLoanWithSchedules($termMonths);
        $loan->amortizationSchedules()->update(['status' => 'paid', 'paid_at' => now()]);
        return $loan->fresh();
    }

    private function makeActiveLoanWithSchedules(int $termMonths = 6): Loan
    {
        $originator = Originator::create([
            'name' => 'AutoRepay-' . uniqid(),
            'description' => 'P3-F5 audit fixture',
            'buyback' => false,
        ]);
        $borrower = Borrower::factory()->create();
        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => 'draft',
            'amount' => '1000.00',
            'funded_amount' => '1000.00',
            'interest_rate' => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months' => $termMonths,
        ]);
        // Walk the loan to active to trigger schedule generation.
        $loan->transitionTo(Loan::STATUS_PUBLISHED);
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'funded']);
        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        return $loan->fresh();
    }
}
