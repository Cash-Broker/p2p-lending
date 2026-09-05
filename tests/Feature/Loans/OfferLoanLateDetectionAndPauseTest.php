<?php

namespace Tests\Feature\Loans;

use App\Enums\PayoutType;
use App\Filament\Widgets\UpcomingDueDatesWidget;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LoanPayoutsPausedNotification;
use App\Notifications\LoanWentLateNotification;
use App\Notifications\PayoutsPausedAdminNotification;
use App\Services\InvestmentService;
use App\Services\Loans\BorrowerPlanService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\Loans\LateDetectionService;
use App\Services\Loans\PayoutPauseService;
use App\Services\ScheduledPayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAY-13 (owner 2026-09-03) end to end: tracker → late → pause → resume /
 * terminal, with conservation and the ledger reconciling at every step.
 */
class OfferLoanLateDetectionAndPauseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function investor(string $credit): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, $credit, Transaction::TYPE_DEPOSIT, 'seed');

        return $user;
    }

    /** Fully funded → auto-activated offer loan in AUTOMATIC payout mode. @return array{0: Loan, 1: User} */
    private function activeOfferLoan(PayoutType $type = PayoutType::Amortizing, string $stake = '1000.00'): array
    {
        Notification::fake();
        PlatformSetting::set('borrower_tracker_auto_generate', true); // the tests exercise the tracker; prod ships OFF
        $loan = Loan::factory()->published()->create([
            'amount' => $stake, 'investable_amount' => $stake, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'interest_rate_annual' => '15.00', 'term_months' => 12,
            'payout_mode' => Loan::PAYOUT_MODE_AUTOMATIC,
        ]);
        $offerId = $loan->offers()->where('payout_type', $type)->value('id');
        $user = $this->investor(bcadd($stake, '100.00', 2));
        app(InvestmentService::class)->invest($user, $loan->fresh(), $stake, (string) Str::uuid(), $offerId);

        return [$loan->fresh(), $user];
    }

    /**
     * Drive a fresh loan to the PAUSED state: first borrower installment never
     * recorded → late at due+11 (03:30) → threshold 10 days → paused at the
     * 04:00 run of due+36 (row 2 is due by then and would otherwise be paid).
     *
     * @return array{0: Loan, 1: User, 2: Carbon}
     */
    private function pausedLoan(): array
    {
        [$loan, $user] = $this->activeOfferLoan();
        $firstDue = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail()->due_date->copy();

        Carbon::setTestNow($firstDue->copy()->addDays(11)->setTime(3, 30));
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame(Loan::STATUS_LATE, $loan->fresh()->status);

        PlatformSetting::set('payout_pause_enabled', true);
        PlatformSetting::set('payout_pause_late_days', 10);

        Carbon::setTestNow($firstDue->copy()->addDays(36)->setTime(4, 0));
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertNotNull($loan->fresh()->payouts_paused_at);

        return [$loan->fresh(), $user, $firstDue];
    }

    public function test_activation_generates_the_tracker_after_commit_for_offer_loans_only(): void
    {
        [$loan] = $this->activeOfferLoan();

        $tracker = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->get();
        $this->assertCount(12, $tracker);
        $this->assertTrue($tracker->every(fn ($r) => $r->status === 'pending'));
        $this->assertSame(today()->addMonthsNoOverflow(1)->toDateString(), $tracker->first()->due_date->toDateString());
        $this->assertSame('1000.00', $tracker->reduce(fn (string $c, $r) => bcadd($c, (string) $r->principal, 2), '0.00'));

        // The tracker is not a funding cap, not a «падеж за разплащане», not a legacy plan.
        $this->assertSame('1000.00', $loan->fundingCap());
        $this->assertTrue($loan->isFullyFunded());
        $this->assertSame(0, $loan->amortizationSchedules()->legacyPlan()->count());
        $this->assertSame(0, UpcomingDueDatesWidget::dueInstallmentsQuery()->where('loan_id', $loan->id)->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // Legacy loan (no offer): the classic 12-row plan, zero tracker rows.
        Notification::fake();
        $legacy = Loan::factory()->published()->create(['amount' => '1000.00', 'investable_amount' => '1000.00', 'funded_amount' => 0, 'interest_rate' => '10.00', 'term_months' => 12]);
        app(InvestmentService::class)->invest($this->investor('1100.00'), $legacy->fresh(), '1000.00', (string) Str::uuid());
        $this->assertSame(12, $legacy->amortizationSchedules()->legacyPlan()->count());
        $this->assertSame(0, $legacy->amortizationSchedules()->borrowerTracker()->count());
    }

    public function test_tracker_late_then_pause_then_the_engine_pays_nothing(): void
    {
        [$loan, $user] = $this->activeOfferLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $firstDue = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail()->due_date->copy();

        // Inside the grace period nothing happens.
        Carbon::setTestNow($firstDue->copy()->addDays(5)->setTime(3, 30));
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);

        // Past the grace period: row late → loan late → investor e-mail with THEIR outstanding.
        Carbon::setTestNow($firstDue->copy()->addDays(11)->setTime(3, 30));
        $this->artisan('loans:process-late')->assertSuccessful();
        $fresh = $loan->fresh();
        $this->assertSame(Loan::STATUS_LATE, $fresh->status);
        $this->assertNotNull($fresh->became_late_at);
        $this->assertSame(1, $fresh->amortizationSchedules()->borrowerTracker()->where('status', 'late')->count());
        $this->assertTrue(LoanEvent::where('loan_id', $loan->id)->where('event_type', LoanEvent::TYPE_WENT_LATE)->exists());
        Notification::assertSentTo($user, LoanWentLateNotification::class, fn (LoanWentLateNotification $n) => $n->investorOutstandingPrincipal === '1000.00' && $n->investorTotalAmount === '1000.00');

        // Below the threshold the platform still pays «по график» (row 1 due).
        PlatformSetting::set('payout_pause_enabled', true);
        PlatformSetting::set('payout_pause_late_days', 10);
        Carbon::setTestNow($firstDue->copy()->addDays(20)->setTime(4, 0));
        $txBefore = Transaction::count();
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertNull($loan->fresh()->payouts_paused_at);
        $this->assertGreaterThan($txBefore, Transaction::count(), 'below the threshold the platform still fronts the payout');
        $this->assertSame('0', PlatformMetric::read('last_payouts_pause_newly_paused'));

        // Threshold crossed: paused, row 2 is due and NOT paid.
        Carbon::setTestNow($firstDue->copy()->addDays(36)->setTime(4, 0));
        $txBefore = Transaction::count();
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $fresh = $loan->fresh();
        $this->assertNotNull($fresh->payouts_paused_at);
        $this->assertTrue($fresh->isPayoutPaused());
        $this->assertSame($txBefore, Transaction::count(), 'a paused loan moves no money');
        $this->assertSame(1, InvestmentSchedule::where('loan_id', $loan->id)->where('status', 'pending')->whereDate('due_date', '<=', today())->count(), 'row 2 is withheld, still pending');
        $event = LoanEvent::where('loan_id', $loan->id)->where('event_type', LoanEvent::TYPE_STATUS_CHANGED)->whereNull('from_status')->latest('id')->firstOrFail();
        $this->assertSame('payouts_paused', $event->metadata['kind']);
        $this->assertSame(10, $event->metadata['threshold_days']);
        $this->assertSame('1', PlatformMetric::read('last_payouts_pause_newly_paused'));
        $this->assertSame('1', PlatformMetric::read('last_payouts_loans_paused'));
        $this->assertSame('success', PlatformMetric::read('last_payouts_status'), 'paused ≠ failed');
        Notification::assertSentTo($user, LoanPayoutsPausedNotification::class, fn (LoanPayoutsPausedNotification $n) => $n->loanId === $loan->id && $n->withheldRows === 1);
        Notification::assertSentTo($admin, PayoutsPausedAdminNotification::class, fn (PayoutsPausedAdminNotification $n) => in_array($loan->id, $n->loanIds, true) && $n->thresholdDays === 10);
        $this->assertSame('0', PlatformMetric::read('last_payouts_pause_failed'));

        // The manual button hits the same gate.
        $manual = app(ScheduledPayoutService::class)->runForLoan($loan->fresh());
        $this->assertTrue($manual['paused']);
        $this->assertSame($txBefore, Transaction::count());

        // Investor API: the flag and the withheld row.
        $portfolio = $this->actingAs($user)->getJson('/api/portfolio')->assertOk()->json();
        $inv = collect($portfolio['data'] ?? $portfolio)->first(fn ($i) => ($i['loan']['id'] ?? null) === $loan->id);
        $this->assertNotNull($inv, 'investment present in the portfolio');
        $this->assertTrue($inv['loan']['payouts_paused']);
        $this->assertNotNull($inv['loan']['payouts_paused_at']);
        $schedule = collect($inv['schedule'])->sortBy('due_date')->values();
        $this->assertFalse($schedule[0]['withheld'], 'row 1 was paid before the pause');
        $this->assertSame('paid', $schedule[0]['status']);
        $this->assertTrue($schedule[1]['withheld'], 'row 2 is due and withheld');
        $this->assertSame('pending', $schedule[1]['status']);
        $this->assertFalse($schedule[2]['withheld'], 'future rows are just pending');
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_pause_never_happens_while_the_setting_is_off_even_when_very_late(): void
    {
        [$loan] = $this->activeOfferLoan();
        $firstDue = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail()->due_date->copy();

        Carbon::setTestNow($firstDue->copy()->addDays(11)->setTime(3, 30));
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame(Loan::STATUS_LATE, $loan->fresh()->status);

        // Setting OFF (the seeded default): 400 days later the engine still pays — Reni's «по график».
        $this->assertFalse(PlatformSetting::get('payout_pause_enabled', false));
        Carbon::setTestNow($firstDue->copy()->addDays(400)->setTime(4, 0));
        $txBefore = Transaction::count();
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertNull($loan->fresh()->payouts_paused_at);
        $this->assertGreaterThan($txBefore, Transaction::count());
        $this->assertSame(0, LoanEvent::where('loan_id', $loan->id)->whereNull('from_status')->count());
    }

    public function test_recovery_after_the_borrower_pays_resumes_and_catches_up_exactly_once(): void
    {
        [$loan, $user] = $this->pausedLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $txBefore = Transaction::count();

        foreach ($loan->amortizationSchedules()->borrowerTracker()->where('status', 'late')->get() as $row) {
            app(BorrowerPlanService::class)->recordBorrowerPayment($loan->id, $row->id, today(), $admin->id);
        }
        $this->assertSame($txBefore, Transaction::count(), 'attestation moves no money');

        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status, 'recovered (R1: paid_at ≥ became_late_at)');
        $this->assertNotNull($loan->fresh()->payouts_paused_at, 'the stamp clears at the payout run, not here');

        $this->artisan('loans:process-payouts')->assertSuccessful();
        $fresh = $loan->fresh();
        $this->assertNull($fresh->payouts_paused_at);
        $resumed = LoanEvent::where('loan_id', $loan->id)->where('event_type', LoanEvent::TYPE_STATUS_CHANGED)->whereNull('from_status')->latest('id')->firstOrFail();
        $this->assertSame('payouts_resumed', $resumed->metadata['kind']);
        $this->assertSame('borrower_rows_settled', $resumed->metadata['resume_reason']);
        $this->assertSame('1', PlatformMetric::read('last_payouts_pause_resumed'));

        // Catch-up: every investor row due by today is paid, each reference exactly once.
        $due = InvestmentSchedule::where('loan_id', $loan->id)->whereDate('due_date', '<=', today())->get();
        $this->assertCount(2, $due);
        $this->assertTrue($due->every(fn ($r) => $r->status === 'paid'));
        $refs = Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->pluck('reference');
        $this->assertSame($refs->count(), $refs->unique()->count());
        $this->assertSame(2, $refs->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // Run to term: Σ principal returned == invested (conservation).
        Carbon::setTestNow(now()->addMonths(12));
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $returned = Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->get(['amount'])
            ->reduce(fn (string $c, $r) => bcadd($c, (string) $r->amount, 2), '0.00');
        $this->assertSame('1000.00', $returned);
        $this->assertSame('0.00', (string) $user->wallet->fresh()->invested);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_disabling_the_setting_frees_the_money_immediately_and_the_reconciler_clears_the_stamp(): void
    {
        [$loan] = $this->pausedLoan();
        PlatformSetting::set('payout_pause_enabled', false);

        // The gate reads stamp AND setting — the manual button pays right away.
        $result = app(ScheduledPayoutService::class)->runForLoan($loan->fresh());
        $this->assertFalse($result['paused']);
        $this->assertGreaterThan(0, $result['released_count']);
        $this->assertNotNull($loan->fresh()->payouts_paused_at, 'stamp persists until the reconciler runs');

        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertNull($loan->fresh()->payouts_paused_at);
        $resumed = LoanEvent::where('loan_id', $loan->id)->whereNull('from_status')->latest('id')->firstOrFail();
        $this->assertSame('payouts_resumed', $resumed->metadata['kind']);
        $this->assertSame('setting_disabled', $resumed->metadata['resume_reason']);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_a_defaulted_paused_loan_gets_no_resume_event_and_no_payout(): void
    {
        [$loan] = $this->pausedLoan();
        $loan->fresh()->transitionTo(Loan::STATUS_DEFAULT);
        $eventsBefore = LoanEvent::where('loan_id', $loan->id)->count();
        $txBefore = Transaction::count();

        $this->artisan('loans:process-payouts')->assertSuccessful();

        $fresh = $loan->fresh();
        $this->assertSame(Loan::STATUS_DEFAULT, $fresh->status);
        $this->assertNotNull($fresh->payouts_paused_at, 'terminal/default loans keep the stamp silently');
        $this->assertSame($eventsBefore, LoanEvent::where('loan_id', $loan->id)->count(), 'no false «възобновени»');
        $this->assertSame($txBefore, Transaction::count());
    }

    public function test_the_pause_is_idempotent_across_runs_and_announces_once(): void
    {
        [$loan, $user] = $this->pausedLoan();
        $admin = User::factory()->create(['role' => 'admin']);

        Carbon::setTestNow(now()->addDay());
        $this->artisan('loans:process-payouts')->assertSuccessful();
        Carbon::setTestNow(now()->addDay());
        $this->artisan('loans:process-payouts')->assertSuccessful();

        $this->assertSame(1, LoanEvent::where('loan_id', $loan->id)->whereNull('from_status')->count(), 'one payouts_paused event');
        Notification::assertSentToTimes($user, LoanPayoutsPausedNotification::class, 1);
        // The admin was created after the pause — the later runs announce nothing.
        Notification::assertNotSentTo($admin, PayoutsPausedAdminNotification::class);
        $this->assertSame('0', PlatformMetric::read('last_payouts_pause_newly_paused'));
        $this->assertSame('1', PlatformMetric::read('last_payouts_loans_paused'));
    }

    public function test_a_manual_late_to_active_flip_does_not_release_withheld_money(): void
    {
        [$loan] = $this->pausedLoan();
        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE); // admin Select flip
        $txBefore = Transaction::count();

        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertNotNull($loan->fresh()->payouts_paused_at, 'the row-level clock keeps the pause');
        $this->assertSame($txBefore, Transaction::count());

        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame(Loan::STATUS_LATE, $loan->fresh()->status, '03:30 re-marks the loan late');
    }

    public function test_the_late_check_kill_switch_does_not_block_a_resume(): void
    {
        [$loan] = $this->pausedLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        PlatformSetting::set('late_check_enabled', false);
        app(BorrowerPlanService::class)->markPaidThrough($loan->id, today(), $admin->id);

        $this->artisan('loans:process-late')->assertSuccessful(); // disabled — no-op
        $this->assertSame(Loan::STATUS_LATE, $loan->fresh()->status);

        $txBefore = Transaction::count();
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertNull($loan->fresh()->payouts_paused_at, 'the reconciler lives in the payout run, not behind late_check_enabled');
        $this->assertGreaterThan($txBefore, Transaction::count(), 'withheld rows paid');
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_a_manual_late_status_without_late_tracker_rows_never_pauses(): void
    {
        [$loan] = $this->activeOfferLoan();
        PlatformSetting::set('payout_pause_enabled', true);
        PlatformSetting::set('payout_pause_late_days', 0);
        $loan->fresh()->transitionTo(Loan::STATUS_LATE); // admin-set, no late rows, became_late_at null

        Carbon::setTestNow(now()->addMonths(2));
        $this->artisan('loans:process-payouts')->assertSuccessful();

        $this->assertNull($loan->fresh()->payouts_paused_at);
        $this->assertGreaterThan(0, Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->count(), 'still paid «по график»');
    }

    public function test_a_full_early_closure_settles_the_tracker_rows(): void
    {
        [$loan] = $this->pausedLoan();
        $admin = User::factory()->create(['role' => 'admin']);

        app(EarlyClosureExecutionService::class)->execute($loan->id, $admin->id, null, today());

        $fresh = $loan->fresh();
        $this->assertSame(Loan::STATUS_REPAID, $fresh->status);
        $this->assertSame(0, $fresh->amortizationSchedules()->borrowerTracker()->whereIn('status', ['pending', 'late'])->count(), 'no zombie tracker rows on a closed loan');
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_days_late_snapshots_tick_only_on_live_loans(): void
    {
        $row = ['plan_kind' => 'borrower_tracker', 'due_date' => now()->subDays(40)->toDateString(), 'principal' => '10.00', 'interest' => '1.00', 'total' => '11.00', 'status' => 'late', 'became_late_at' => now()->subDays(30), 'days_late' => 5];

        $repaid = Loan::factory()->repaid()->create();
        $zombie = $repaid->amortizationSchedules()->create($row);

        $defaulted = Loan::factory()->active()->create();
        $defaulted->transitionTo(Loan::STATUS_LATE);
        $defaulted->transitionTo(Loan::STATUS_DEFAULT);
        $watched = $defaulted->amortizationSchedules()->create($row);

        $updated = app(LateDetectionService::class)->refreshDaysLateSnapshots(Carbon::now());

        $this->assertSame(1, $updated);
        $this->assertSame(5, (int) $zombie->fresh()->days_late, 'closed loans stop ticking');
        $this->assertSame(40, (int) $watched->fresh()->days_late, 'default loans are still watched');
    }

    public function test_a_crashing_pause_reconciler_does_not_stop_the_payout_run(): void
    {
        [$loan] = $this->activeOfferLoan();
        $this->mock(PayoutPauseService::class, function ($mock) {
            $mock->shouldReceive('reconcile')->andThrow(new \RuntimeException('deadlock'));
        });

        Carbon::setTestNow(now()->addMonths(2));
        $txBefore = Transaction::count();
        $this->artisan('loans:process-payouts')->assertSuccessful();

        $this->assertGreaterThan($txBefore, Transaction::count(), 'investors were paid although step 0 crashed');
        $this->assertSame('1', PlatformMetric::read('last_payouts_pause_failed'));
        $this->assertSame('success', PlatformMetric::read('last_payouts_status'));
    }

    public function test_the_tracker_is_not_generated_at_activation_while_the_setting_is_off(): void
    {
        // Owner 2026-09-05: the seeded default is OFF — a new offer loan must not be
        // able to become «закъснял» on its own until an automation path is chosen.
        Notification::fake();
        $this->assertFalse(PlatformSetting::get('borrower_tracker_auto_generate', false));
        $loan = Loan::factory()->published()->create([
            'amount' => '1000.00', 'investable_amount' => '1000.00', 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12, 'payout_mode' => Loan::PAYOUT_MODE_AUTOMATIC,
        ]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($this->investor('1100.00'), $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        $this->assertSame(0, $loan->amortizationSchedules()->count());

        // Months later: still active, still paid «по график», no late e-mail — exactly like before PAY-13.
        Carbon::setTestNow(now()->addMonths(3));
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->artisan('loans:process-payouts')->assertSuccessful();
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        Notification::assertNotSentTo($loan->investments()->first()->user, LoanWentLateNotification::class);
        $this->assertGreaterThan(0, Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->count());
    }
}
