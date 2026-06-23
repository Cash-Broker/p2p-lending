<?php

namespace Tests\Audit;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\DepositRequest;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AmortizationService;
use App\Services\DepositService;
use App\Services\InvestmentService;
use App\Services\Loans\BuybackExecutionService;
use App\Services\Loans\EarlyRepaymentExecutionService;
use App\Services\RepaymentService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 — Wallet integrity + cross-feature reconciliation audit.
 *
 * Complements Phase2FinancialCorrectnessTest (pure math) with lifecycle
 * integration scenarios. Each scenario simulates a realistic sequence
 * of operations and asserts two invariants:
 *
 *   1. Per-user wallet reconstruction — `wallet.available + invested +
 *      earned` values reconstructed from the user's transaction history
 *      match the live DB wallet. Catches missed / duplicated wallet
 *      mutations in service code.
 *   2. Global balance conservation — `Σ(available + invested + reserved)
 *      across all users + Σ external outflows` = `Σ external inflows +
 *      Σ interest inflows`. Catches money appearing from thin air or
 *      silently disappearing.
 *
 * Opt-in via `php artisan test testsuite=Audit`. NOT in default CI.
 *
 * Scenario naming: A1..A* = wallet invariants, B1..B* = cross-feature.
 */
class Phase2WalletIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Silence investor notifications across these scenarios — they ride
        // a separate code path (Notification::fake intercepts) and are not
        // part of the wallet invariant audit.
        Notification::fake();
    }

    // ══════════════════════════════════════════════════════════════════
    // CATEGORY A — Wallet invariant scenarios
    // ══════════════════════════════════════════════════════════════════

    public function test_A1_simple_single_investor_full_repayment_cycle(): void
    {
        $inv = $this->makeInvestorWithDeposit('2000.00');
        $loan = $this->makeActiveLoan(
            amount: '1000', rate: '12', term: 6,
            fundings: [$inv->id => '1000.00'],
        );

        foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loan, $s);
        }

        $this->assertAllSchedulesPaid($loan);
        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A2_multi_investor_pro_rata_full_repayment(): void
    {
        $inv1 = $this->makeInvestorWithDeposit('2000.00');
        $inv2 = $this->makeInvestorWithDeposit('2000.00');
        $inv3 = $this->makeInvestorWithDeposit('2000.00');
        $loan = $this->makeActiveLoan(
            amount: '1000', rate: '10', term: 3,
            fundings: [$inv1->id => '500.00', $inv2->id => '300.00', $inv3->id => '200.00'],
        );

        foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loan, $s);
        }

        $this->assertAllSchedulesPaid($loan);
        foreach ([$inv1, $inv2, $inv3] as $u) {
            $this->assertWalletReconstructs($u);
        }
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A3_invest_partial_repay_then_early_repayment(): void
    {
        $inv = $this->makeInvestorWithDeposit('3000.00');
        $loan = $this->makeActiveLoan(
            amount: '2000', rate: '12', term: 6,
            fundings: [$inv->id => '2000.00'],
        );

        // Pay 2 of 6 scheduled installments, then early-repay the rest.
        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
        foreach ($schedules->take(2) as $s) {
            $this->processScheduledRepayment($loan, $s);
        }
        $this->executeEarlyRepayment($loan);

        $this->assertSame('repaid', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->early_repaid_at);
        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A4_invest_partial_repay_late_then_buyback(): void
    {
        $inv = $this->makeInvestorWithDeposit('3000.00');
        $loan = $this->makeActiveLoan(
            amount: '2000', rate: '14', term: 6,
            fundings: [$inv->id => '2000.00'],
            buyback: true,
        );

        $schedules = $loan->amortizationSchedules()->orderBy('due_date')->get();
        foreach ($schedules->take(2) as $s) {
            $this->processScheduledRepayment($loan, $s);
        }
        $this->forceLoanLate($loan);
        $this->executeBuyback($loan);

        $this->assertSame('bought_back', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->bought_back_at);
        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A5_deposit_invest_repay_withdraw_full_money_trail(): void
    {
        $inv = $this->makeInvestorWithDeposit('1500.00');
        $loan = $this->makeActiveLoan(
            amount: '1000', rate: '12', term: 3,
            fundings: [$inv->id => '1000.00'],
        );
        foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loan, $s);
        }

        // Post-repayment available balance = 500 leftover deposit + 1000
        // principal back + ~interest. Withdraw all of it.
        $available = (string) $inv->wallet->fresh()->available;
        $this->requestAndApproveWithdrawal($inv, $available);

        $wallet = $inv->wallet->fresh();
        $this->assertSame('0.00', (string) $wallet->available, 'available should be zeroed');
        $this->assertSame('0.00', (string) $wallet->reserved, 'reserved should be zero');
        $this->assertSame('0.00', (string) $wallet->invested, 'invested should be zero');
        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A6_withdrawal_fee_on_accounted_correctly(): void
    {
        \App\Models\PlatformSetting::set('fees_withdrawal_enabled', true);
        \App\Models\PlatformSetting::set('fees_withdrawal_amount', '2.50');

        $inv = $this->makeInvestorWithDeposit('1000.00');
        // Withdraw 500 → TYPE_WITHDRAWAL 497.50 + TYPE_FEE 2.50.
        $this->requestAndApproveWithdrawal($inv, '500.00');

        $txns = Transaction::where('user_id', $inv->id)->get()->keyBy('type');
        $this->assertSame('497.50', (string) $txns['withdrawal']->amount, 'net withdrawal');
        $this->assertSame('2.50', (string) $txns['fee']->amount, 'fee amount');

        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A7_multi_investor_buyback_after_partial_payments(): void
    {
        $investors = [
            $this->makeInvestorWithDeposit('5000.00'),
            $this->makeInvestorWithDeposit('5000.00'),
            $this->makeInvestorWithDeposit('5000.00'),
        ];
        $loan = $this->makeActiveLoan(
            amount: '3000', rate: '12', term: 6,
            fundings: [
                $investors[0]->id => '1500.00',
                $investors[1]->id => '1000.00',
                $investors[2]->id => '500.00',
            ],
            buyback: true,
        );

        foreach ($loan->amortizationSchedules()->orderBy('due_date')->take(3)->get() as $s) {
            $this->processScheduledRepayment($loan, $s);
        }
        $this->forceLoanLate($loan);
        $this->executeBuyback($loan);

        foreach ($investors as $u) {
            $this->assertWalletReconstructs($u);
        }
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A8_two_loans_same_investor_parallel_lifecycles(): void
    {
        $inv = $this->makeInvestorWithDeposit('10000.00');
        $loanA = $this->makeActiveLoan(
            amount: '1000', rate: '10', term: 3,
            fundings: [$inv->id => '1000.00'],
        );
        $loanB = $this->makeActiveLoan(
            amount: '2000', rate: '15', term: 6,
            fundings: [$inv->id => '2000.00'],
            buyback: true,
        );

        // Loan A: full repayment.
        foreach ($loanA->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loanA, $s);
        }
        // Loan B: 2 payments then buyback.
        foreach ($loanB->amortizationSchedules()->orderBy('due_date')->take(2)->get() as $s) {
            $this->processScheduledRepayment($loanB, $s);
        }
        $this->forceLoanLate($loanB);
        $this->executeBuyback($loanB);

        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A9_multiple_withdrawals_in_sequence(): void
    {
        $inv = $this->makeInvestorWithDeposit('3000.00');
        $this->requestAndApproveWithdrawal($inv, '500.00');
        $this->requestAndApproveWithdrawal($inv, '1000.00');
        $this->requestAndApproveWithdrawal($inv, '250.00');

        $wallet = $inv->wallet->fresh();
        $this->assertSame('1250.00', (string) $wallet->available);
        $this->assertSame('0.00', (string) $wallet->reserved);

        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_A10_mix_early_repayment_buyback_normal_across_three_loans(): void
    {
        $inv1 = $this->makeInvestorWithDeposit('6000.00');
        $inv2 = $this->makeInvestorWithDeposit('6000.00');

        // Loan N: normal repayment.
        $loanN = $this->makeActiveLoan(
            amount: '1000', rate: '8', term: 3,
            fundings: [$inv1->id => '600.00', $inv2->id => '400.00'],
        );
        foreach ($loanN->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loanN, $s);
        }

        // Loan E: early repayment.
        $loanE = $this->makeActiveLoan(
            amount: '1500', rate: '14', term: 6,
            fundings: [$inv1->id => '800.00', $inv2->id => '700.00'],
        );
        foreach ($loanE->amortizationSchedules()->orderBy('due_date')->take(1)->get() as $s) {
            $this->processScheduledRepayment($loanE, $s);
        }
        $this->executeEarlyRepayment($loanE);

        // Loan B: buyback.
        $loanB = $this->makeActiveLoan(
            amount: '2000', rate: '18', term: 6,
            fundings: [$inv1->id => '1000.00', $inv2->id => '1000.00'],
            buyback: true,
        );
        $this->forceLoanLate($loanB);
        $this->executeBuyback($loanB);

        foreach ([$inv1, $inv2] as $u) {
            $this->assertWalletReconstructs($u);
        }
        $this->assertGlobalBalanceInvariant();
    }

    // ══════════════════════════════════════════════════════════════════
    // CATEGORY B — Cross-feature terminal-state & reconciliation
    // ══════════════════════════════════════════════════════════════════

    public function test_B1_buyback_rejected_on_already_repaid_loan(): void
    {
        $inv = $this->makeInvestorWithDeposit('2000.00');
        $loan = $this->makeActiveLoan(
            amount: '1000', rate: '12', term: 3,
            fundings: [$inv->id => '1000.00'],
            buyback: true,
        );
        foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loan, $s);
        }
        // Loan's schedule is fully paid — but loan status still 'active'
        // (no auto-transition). Force-transition to 'repaid'.
        $loan->fresh()->transitionTo(Loan::STATUS_REPAID);

        // Now attempt buyback on the repaid loan. Must throw
        // (state machine rejects repaid → bought_back; not in
        // ALLOWED_TRANSITIONS[repaid]).
        $threw = false;
        try {
            app(BuybackExecutionService::class)->execute($loan->id, $this->makeAdmin()->id);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'buyback on repaid loan should throw');

        // Wallet state should be unchanged from post-repayment snapshot.
        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_B2_early_repayment_rejected_on_bought_back_loan(): void
    {
        $inv = $this->makeInvestorWithDeposit('2000.00');
        $loan = $this->makeActiveLoan(
            amount: '1000', rate: '15', term: 6,
            fundings: [$inv->id => '1000.00'],
            buyback: true,
        );
        $this->forceLoanLate($loan);
        $this->executeBuyback($loan);

        $this->assertSame('bought_back', $loan->fresh()->status);

        // Attempt early repayment on bought_back loan.
        $threw = false;
        try {
            app(EarlyRepaymentExecutionService::class)->execute($loan->id, $this->makeAdmin()->id);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'early repayment on bought_back loan should throw');

        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_B3_double_buyback_rejected(): void
    {
        $inv = $this->makeInvestorWithDeposit('2000.00');
        $loan = $this->makeActiveLoan(
            amount: '1000', rate: '15', term: 6,
            fundings: [$inv->id => '1000.00'],
            buyback: true,
        );
        $this->forceLoanLate($loan);
        $this->executeBuyback($loan);

        // First buyback succeeded. Second must fail.
        $threw = false;
        try {
            app(BuybackExecutionService::class)->execute($loan->id, $this->makeAdmin()->id);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'second buyback should throw');

        $this->assertWalletReconstructs($inv);
        $this->assertGlobalBalanceInvariant();
    }

    public function test_B4_shared_investors_across_parallel_loans_consistent_total(): void
    {
        $inv1 = $this->makeInvestorWithDeposit('10000.00');
        $inv2 = $this->makeInvestorWithDeposit('10000.00');

        $loans = [];
        foreach (range(1, 3) as $i) {
            $loans[] = $this->makeActiveLoan(
                amount: '1000', rate: (string) (10 + $i), term: 3,
                fundings: [$inv1->id => '500.00', $inv2->id => '500.00'],
            );
        }

        // Full repayment on all three loans.
        foreach ($loans as $loan) {
            foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
                $this->processScheduledRepayment($loan, $s);
            }
        }

        foreach ([$inv1, $inv2] as $u) {
            $this->assertWalletReconstructs($u);
        }
        $this->assertGlobalBalanceInvariant();
    }

    public function test_B5_simulated_six_month_accounting_cycle(): void
    {
        \App\Models\PlatformSetting::set('fees_withdrawal_enabled', true);
        \App\Models\PlatformSetting::set('fees_withdrawal_amount', '2.50');

        $investors = array_map(
            fn () => $this->makeInvestorWithDeposit('10000.00'),
            range(1, 3),
        );

        // 3 overlapping loans, different cadences + outcomes.
        $loanA = $this->makeActiveLoan(
            amount: '2000', rate: '12', term: 6,
            fundings: [
                $investors[0]->id => '1000.00',
                $investors[1]->id => '600.00',
                $investors[2]->id => '400.00',
            ],
        );
        $loanB = $this->makeActiveLoan(
            amount: '1500', rate: '15', term: 3,
            fundings: [
                $investors[0]->id => '500.00',
                $investors[1]->id => '500.00',
                $investors[2]->id => '500.00',
            ],
            buyback: true,
        );
        $loanC = $this->makeActiveLoan(
            amount: '3000', rate: '10', term: 6,
            fundings: [
                $investors[0]->id => '1000.00',
                $investors[1]->id => '1000.00',
                $investors[2]->id => '1000.00',
            ],
        );

        // A: 4 of 6 payments then early repay.
        foreach ($loanA->amortizationSchedules()->orderBy('due_date')->take(4)->get() as $s) {
            $this->processScheduledRepayment($loanA, $s);
        }
        $this->executeEarlyRepayment($loanA);

        // B: 1 payment, then buyback.
        foreach ($loanB->amortizationSchedules()->orderBy('due_date')->take(1)->get() as $s) {
            $this->processScheduledRepayment($loanB, $s);
        }
        $this->forceLoanLate($loanB);
        $this->executeBuyback($loanB);

        // C: full repayment.
        foreach ($loanC->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            $this->processScheduledRepayment($loanC, $s);
        }

        // Each investor pulls out some cash (with fee).
        foreach ($investors as $u) {
            $this->requestAndApproveWithdrawal($u, '1000.00');
        }

        foreach ($investors as $u) {
            $this->assertWalletReconstructs($u);
        }
        $this->assertGlobalBalanceInvariant();

        // Fee collection check: 3 withdrawals × 2.50 = 7.50 fee total.
        $totalFees = Transaction::where('type', 'fee')->sum('amount');
        $this->assertEquals('7.50', number_format((float) $totalFees, 2, '.', ''));
    }

    // ══════════════════════════════════════════════════════════════════
    // Helpers — scenario builders
    // ══════════════════════════════════════════════════════════════════

    private ?User $adminCache = null;

    private function makeAdmin(): User
    {
        // Per-test cache (NOT static — RefreshDatabase wipes the table
        // between test methods so a persistent static would hold a stale
        // FK id, producing loan_events.triggered_by_user_id violations).
        if (! $this->adminCache || ! User::find($this->adminCache->id)) {
            $this->adminCache = User::factory()->create(['role' => 'admin']);
        }
        return $this->adminCache;
    }

    private function makeInvestor(): User
    {
        static $counter = 0;
        $counter++;
        $user = User::factory()->kycApproved()->create([
            'email' => "audit-inv-{$counter}-" . uniqid() . "@test.local",
        ]);
        if (! $user->wallet) {
            Wallet::create(['user_id' => $user->id, 'available' => 0, 'invested' => 0, 'earned' => 0, 'reserved' => 0]);
        }
        return $user;
    }

    private function makeInvestorWithDeposit(string $amount): User
    {
        $inv = $this->makeInvestor();
        // Go through the DepositService so a TYPE_DEPOSIT transaction is
        // written — wallet reconstruction depends on that.
        $admin = $this->makeAdmin();
        $depositService = app(DepositService::class);
        $request = $depositService->createRequest($inv->id, $amount);
        $depositService->approve($request->id, $admin->id);
        return $inv->fresh();
    }

    /**
     * Build a loan that is fully funded + active with an amortization
     * schedule. Handles the whole pipeline:
     *   draft → published → (invest from each funding) → funded → active
     */
    private function makeActiveLoan(string $amount, string $rate, int $term, array $fundings, bool $buyback = false): Loan
    {
        $originator = Originator::create([
            'name' => 'Audit-Orig-' . uniqid(),
            'description' => 'Audit fixture',
            'buyback' => $buyback,
        ]);
        $borrower = Borrower::factory()->create();
        $loan = Loan::create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'amount' => $amount,
            'funded_amount' => 0,
            'interest_rate' => $rate,
            'interest_rate_annual' => bcadd($rate, '2.00', 2),
            'term_months' => $term,
            'type' => 'consumer',
            'status' => 'draft',
        ]);
        $loan->transitionTo(Loan::STATUS_PUBLISHED);
        $loan->forceFill(['published_at' => now()])->save();

        $investmentService = app(InvestmentService::class);
        foreach ($fundings as $userId => $fundAmount) {
            $user = User::findOrFail($userId);
            $investmentService->invest($user, $loan->fresh(), $fundAmount, (string) Str::uuid());
        }
        $loan->refresh();
        if ($loan->status === Loan::STATUS_FUNDING) {
            $loan->transitionTo(Loan::STATUS_FUNDED);
        }
        // Generate schedule (transitionTo(active) does this via booted()).
        $loan->transitionTo(Loan::STATUS_ACTIVE);
        return $loan->fresh();
    }

    private function processScheduledRepayment(Loan $loan, AmortizationSchedule $schedule): void
    {
        // Amounts are derived from the installment by the service now.
        app(RepaymentService::class)->processRepayment($loan->id, $schedule->id);
    }

    private function forceLoanLate(Loan $loan): void
    {
        $loan->refresh();
        if ($loan->status !== Loan::STATUS_LATE) {
            $loan->transitionTo(Loan::STATUS_LATE);
        }
        // Mark earliest unpaid schedule as late so buyback calc finds it.
        $firstUnpaid = $loan->amortizationSchedules()
            ->whereIn('status', ['pending'])
            ->orderBy('due_date')
            ->first();
        if ($firstUnpaid) {
            $firstUnpaid->update(['status' => 'late', 'days_late' => 30, 'became_late_at' => now()->subDays(30)]);
        }
        $loan->forceFill(['became_late_at' => now()->subDays(30)])->save();
    }

    private function executeBuyback(Loan $loan): void
    {
        app(BuybackExecutionService::class)->execute($loan->id, $this->makeAdmin()->id);
    }

    private function executeEarlyRepayment(Loan $loan): void
    {
        app(EarlyRepaymentExecutionService::class)->execute($loan->id, $this->makeAdmin()->id);
    }

    private function requestAndApproveWithdrawal(User $user, string $amount): void
    {
        $service = app(WithdrawalService::class);
        $req = $service->createRequest($user->id, $amount, 'BG80BNBG96611020345678');
        $service->approve($req->id, $this->makeAdmin()->id);
    }

    private function assertAllSchedulesPaid(Loan $loan): void
    {
        $unpaid = $loan->amortizationSchedules()->whereNotIn('status', ['paid'])->count();
        $this->assertSame(0, $unpaid, "Loan #{$loan->id} has unpaid schedules");
    }

    // ══════════════════════════════════════════════════════════════════
    // Invariant assertions
    // ══════════════════════════════════════════════════════════════════

    /**
     * Rebuild the user's wallet buckets from transaction history and
     * assert match against the live DB wallet. Reserved is verified
     * to be 0 (scenarios complete all pending withdrawal requests).
     */
    private function assertWalletReconstructs(User $user): void
    {
        $wallet = $user->fresh()->wallet;
        $this->assertNotNull($wallet, "User #{$user->id} has no wallet");

        $available = '0.00';
        $invested = '0.00';
        $earned = '0.00';

        $txns = Transaction::where('user_id', $user->id)->orderBy('id')->get();
        foreach ($txns as $t) {
            $amt = (string) $t->amount;
            match ($t->type) {
                'deposit' => $available = bcadd($available, $amt, 2),
                'withdrawal' => $available = bcsub($available, $amt, 2),
                'fee' => $available = bcsub($available, $amt, 2),
                'investment' => [
                    $available = bcsub($available, $amt, 2),
                    $invested = bcadd($invested, $amt, 2),
                ],
                'repayment_principal', 'buyback_principal', 'early_repayment_principal' => [
                    $available = bcadd($available, $amt, 2),
                    // INDEPENDENT oracle — does NOT mirror any clamp. With the
                    // exact per-investor distribution, invested must reconstruct
                    // by plain subtraction and must never go negative (the
                    // hardened WalletService would have thrown instead of
                    // recording a principal return that underflows). If this
                    // ever goes negative, that's a real distribution bug.
                    $invested = bcsub($invested, $amt, 2),
                ],
                'repayment_interest', 'buyback_interest', 'early_repayment_interest' => [
                    $available = bcadd($available, $amt, 2),
                    $earned = bcadd($earned, $amt, 2),
                ],
                default => $this->fail("Unknown transaction type '{$t->type}' on txn #{$t->id}"),
            };
        }

        $msg = sprintf(
            'User #%d wallet mismatch.  reconstructed=(available:%s invested:%s earned:%s)  db=(available:%s invested:%s earned:%s reserved:%s)  %d txns',
            $user->id,
            $available, $invested, $earned,
            $wallet->available, $wallet->invested, $wallet->earned, $wallet->reserved,
            $txns->count(),
        );
        $this->assertSame($available, (string) $wallet->available, $msg);
        $this->assertSame($invested, (string) $wallet->invested, $msg);
        $this->assertSame($earned, (string) $wallet->earned, $msg);
        $this->assertSame('0.00', (string) $wallet->reserved, "User #{$user->id} reserved not zero: {$wallet->reserved}");
    }

    /**
     * Platform-wide balance conservation.
     *
     *   Σ(available + invested + reserved) across wallets
     *   ==
     *   Σ(deposits) − Σ(withdrawals) − Σ(fees)
     *     + Σ(interest-type credits from borrowers / buyback / early_repay)
     *
     * `earned` is a CUMULATIVE COUNTER, not a balance bucket (interest
     * is credited to `available` AND `earned` at the same time), so it
     * is excluded from the balance sum.
     *
     * Principal repayment / buyback_principal / early_repayment_principal
     * are internal (invested → available) and net to zero in the balance
     * sum — they are NOT external inflows.
     */
    private function assertGlobalBalanceInvariant(): void
    {
        $balanceSum = DB::table('wallets')->selectRaw(
            'SUM(available + invested + reserved) AS s',
        )->value('s');
        $balanceSum = number_format((float) ($balanceSum ?? 0), 2, '.', '');

        $deposits = (string) Transaction::where('type', 'deposit')->sum('amount');
        $withdrawals = (string) Transaction::where('type', 'withdrawal')->sum('amount');
        $fees = (string) Transaction::where('type', 'fee')->sum('amount');
        $interestIn = Transaction::whereIn('type', [
            'repayment_interest', 'buyback_interest', 'early_repayment_interest',
        ])->sum('amount');

        $expected = bcadd(
            bcsub(bcsub((string) $deposits, (string) $withdrawals, 2), (string) $fees, 2),
            number_format((float) $interestIn, 2, '.', ''),
            2,
        );

        // ZERO tolerance. The pro-rata drift / clamp money-creation path is
        // fixed (exact per-investor distribution + hardened WalletService), so
        // for DECIMAL(12,2) money the only acceptable conservation drift is
        // none. Any nonzero drift is a real leak, not "expected pro-rata".
        $toleranceLimit = '0.00';

        $drift = bcsub($balanceSum, $expected, 2);
        $absDrift = bccomp($drift, '0', 2) < 0 ? bcmul($drift, '-1', 2) : $drift;

        $this->assertTrue(
            bccomp($absDrift, $toleranceLimit, 2) <= 0,
            sprintf(
                "Global balance invariant drift exceeds tolerance.\n  Σ(wallets.available + invested + reserved) = %s\n  Σ(deposits) − Σ(withdrawals) − Σ(fees) + Σ(interest_in) = %s\n  actual drift = %s  (tolerance ≤ %s EUR per scenario)\n  deposits=%s withdrawals=%s fees=%s interest_in=%s",
                $balanceSum, $expected, $drift, $toleranceLimit,
                $deposits, $withdrawals, $fees, number_format((float) $interestIn, 2, '.', ''),
            ),
        );
    }
}
