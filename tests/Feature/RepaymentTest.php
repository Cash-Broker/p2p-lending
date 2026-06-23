<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RepaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\RepaymentReceivedNotification;
use Tests\TestCase;

class RepaymentTest extends TestCase
{
    use RefreshDatabase;

    private RepaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RepaymentService::class);
    }

    private function createInvestorWithWallet(array $walletBalances = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletBalances) {
            $wallet->forceFill($walletBalances)->save();
        }
        return $user;
    }

    /**
     * Add an installment row to a loan's schedule. Amounts are now DERIVED
     * from this row by RepaymentService — the admin no longer types them.
     */
    private function addInstallment(Loan $loan, string $principal, string $interest, int $monthsAhead = 1): AmortizationSchedule
    {
        return AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => now()->addMonths($monthsAhead)->toDateString(),
            'principal' => $principal,
            'interest' => $interest,
            'total' => bcadd($principal, $interest, 2),
            'status' => 'pending',
        ]);
    }

    // ── Proportional distribution with 3 investors (NON-final installment) ──

    public function test_repayment_distributes_proportionally_to_three_investors(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 10000, 'funded_amount' => 10000]);

        // Investor A: 50%, B: 30%, C: 20%
        $investorA = $this->createInvestorWithWallet(['available' => 0, 'invested' => 5000, 'earned' => 0]);
        $investorB = $this->createInvestorWithWallet(['available' => 0, 'invested' => 3000, 'earned' => 0]);
        $investorC = $this->createInvestorWithWallet(['available' => 0, 'invested' => 2000, 'earned' => 0]);

        Investment::factory()->create(['user_id' => $investorA->id, 'loan_id' => $loan->id, 'amount' => 5000]);
        Investment::factory()->create(['user_id' => $investorB->id, 'loan_id' => $loan->id, 'amount' => 3000]);
        Investment::factory()->create(['user_id' => $investorC->id, 'loan_id' => $loan->id, 'amount' => 2000]);

        // Two pending installments → the one we pay is NON-final → proportional.
        $row = $this->addInstallment($loan, '1000.00', '100.00', 1);
        $this->addInstallment($loan, '9000.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        // Investor A (50%): principal 500, interest 50
        $walletA = $investorA->wallet->fresh();
        $this->assertEquals('4500.00', $walletA->invested);
        $this->assertEquals('550.00', $walletA->available);
        $this->assertEquals('50.00', $walletA->earned);

        // Investor B (30%): principal 300, interest 30
        $walletB = $investorB->wallet->fresh();
        $this->assertEquals('2700.00', $walletB->invested);
        $this->assertEquals('330.00', $walletB->available);
        $this->assertEquals('30.00', $walletB->earned);

        // Investor C (20%): principal 200, interest 20
        $walletC = $investorC->wallet->fresh();
        $this->assertEquals('1800.00', $walletC->invested);
        $this->assertEquals('220.00', $walletC->available);
        $this->assertEquals('20.00', $walletC->earned);
    }

    // ── Transaction records ──

    public function test_repayment_creates_transaction_records_for_each_investor(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);

        $investor1 = $this->createInvestorWithWallet(['invested' => 600]);
        $investor2 = $this->createInvestorWithWallet(['invested' => 400]);

        Investment::factory()->create(['user_id' => $investor1->id, 'loan_id' => $loan->id, 'amount' => 600]);
        Investment::factory()->create(['user_id' => $investor2->id, 'loan_id' => $loan->id, 'amount' => 400]);

        $row = $this->addInstallment($loan, '500.00', '50.00', 1);
        $this->addInstallment($loan, '500.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $investor1->id,
            'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL,
            'amount' => '300.00', // 60% of 500
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $investor1->id,
            'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => '30.00', // 60% of 50
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $investor2->id,
            'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL,
            'amount' => '200.00', // 40% of 500
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $investor2->id,
            'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => '20.00', // 40% of 50
        ]);

        $this->assertEquals(4, Transaction::whereIn('type', [
            Transaction::TYPE_REPAYMENT_PRINCIPAL,
            Transaction::TYPE_REPAYMENT_INTEREST,
        ])->count());
    }

    // ── Amortization schedule status ──

    public function test_repayment_marks_installment_paid(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['invested' => 1000]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $row = $this->addInstallment($loan, '200.00', '20.00', 1);
        $this->addInstallment($loan, '800.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        $row->refresh();
        $this->assertEquals('paid', $row->status);
        $this->assertNotNull($row->paid_at);
    }

    // ── Amounts are DERIVED from the row, never the caller (audit CRITICAL) ──

    public function test_repayment_distributes_exactly_the_installment_amounts(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['available' => 0, 'invested' => 1000]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $row = $this->addInstallment($loan, '80.00', '10.00', 1);
        $this->addInstallment($loan, '920.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        // Exactly the row's 80 + 10 moved — not a penny more (no fat-finger path).
        $wallet = $investor->wallet->fresh();
        $this->assertEquals('920.00', $wallet->invested);
        $this->assertEquals('90.00', $wallet->available);
        $this->assertEquals('10.00', $wallet->earned);
    }

    // ── Final installment returns each investor's EXACT outstanding ──

    public function test_final_installment_zeroes_invested_for_every_investor(): void
    {
        Notification::fake();

        // 3 equal investors, 1000 funded, full schedule of 3 equal installments.
        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investors = [];
        for ($i = 0; $i < 3; $i++) {
            $inv = $this->createInvestorWithWallet(['available' => 0, 'invested' => bcdiv('1000', '3', 2)]);
            Investment::factory()->create(['user_id' => $inv->id, 'loan_id' => $loan->id, 'amount' => bcdiv('1000', '3', 2)]);
            $investors[] = $inv;
        }
        // invested seeded as 333.33 each (1000/3 truncated) — fix the last so
        // Σ invested == funded 1000.00 exactly (mirrors real funding).
        $investors[2]->wallet->forceFill(['invested' => '333.34'])->save();
        Investment::where('user_id', $investors[2]->id)->update(['amount' => '333.34']);

        // Annuity-ish schedule whose principals sum to 1000.00.
        $rows = [
            $this->addInstallment($loan, '333.00', '5.00', 1),
            $this->addInstallment($loan, '333.00', '3.00', 2),
            $this->addInstallment($loan, '334.00', '1.00', 3),
        ];

        foreach ($rows as $row) {
            $this->service->processRepayment($loan->id, $row->id);
        }

        // Every investor's invested bucket is EXACTLY zero — zero drift, no clamp.
        foreach ($investors as $inv) {
            $this->assertEquals('0.00', $inv->wallet->fresh()->invested,
                "investor #{$inv->id} invested must be exactly 0 after full repayment");
        }

        // Σ principal returned == funded 1000.00 exactly.
        $totalPrincipal = Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->sum('amount');
        $this->assertSame(0, bccomp('1000.00', number_format((float) $totalPrincipal, 2, '.', ''), 2));
    }

    // ── Per-investor principal conservation == invested (the key invariant) ──

    public function test_each_investor_principal_returned_equals_their_invested(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);

        // Uneven split: 500 / 300 / 200.
        $amounts = ['500.00', '300.00', '200.00'];
        $investors = [];
        foreach ($amounts as $amt) {
            $inv = $this->createInvestorWithWallet(['available' => 0, 'invested' => $amt]);
            Investment::factory()->create(['user_id' => $inv->id, 'loan_id' => $loan->id, 'amount' => $amt]);
            $investors[] = [$inv, $amt];
        }

        $rows = [
            $this->addInstallment($loan, '333.34', '8.00', 1),
            $this->addInstallment($loan, '333.33', '5.00', 2),
            $this->addInstallment($loan, '333.33', '2.00', 3),
        ];
        foreach ($rows as $row) {
            $this->service->processRepayment($loan->id, $row->id);
        }

        foreach ($investors as [$inv, $amt]) {
            $returned = Transaction::where('user_id', $inv->id)
                ->where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)
                ->sum('amount');
            $this->assertSame(
                0,
                bccomp($amt, number_format((float) $returned, 2, '.', ''), 2),
                "investor #{$inv->id} must be returned EXACTLY their invested {$amt}, got {$returned}"
            );
            $this->assertEquals('0.00', $inv->wallet->fresh()->invested);
        }
    }

    // ── Notifications ──

    public function test_repayment_sends_notification_to_each_investor(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);

        $investor1 = $this->createInvestorWithWallet(['invested' => 600]);
        $investor2 = $this->createInvestorWithWallet(['invested' => 400]);

        Investment::factory()->create(['user_id' => $investor1->id, 'loan_id' => $loan->id, 'amount' => 600]);
        Investment::factory()->create(['user_id' => $investor2->id, 'loan_id' => $loan->id, 'amount' => 400]);

        $row = $this->addInstallment($loan, '100.00', '10.00', 1);
        $this->addInstallment($loan, '900.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        Notification::assertSentTo($investor1, RepaymentReceivedNotification::class);
        Notification::assertSentTo($investor2, RepaymentReceivedNotification::class);
    }

    // ── Idempotency: an already-paid installment cannot be re-distributed ──

    public function test_repayment_rejects_already_paid_installment(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['available' => 0, 'invested' => 1000]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $row = $this->addInstallment($loan, '200.00', '20.00', 1);
        $this->addInstallment($loan, '800.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        // Second call on the same installment must throw and move no money.
        try {
            $this->service->processRepayment($loan->id, $row->id);
            $this->fail('Expected an exception re-paying an already-paid installment.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already been paid', $e->getMessage());
        }

        $wallet = $investor->wallet->fresh();
        $this->assertEquals('800.00', $wallet->invested); // unchanged by the 2nd call
        $this->assertEquals('220.00', $wallet->available);
        $this->assertEquals(1, Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->count());
    }

    // ── Rollback on error (non-existent loan) ──

    public function test_repayment_rolls_back_on_error(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['available' => 100, 'invested' => 1000, 'earned' => 0]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        try {
            $this->service->processRepayment(99999, 1);
        } catch (ModelNotFoundException $e) {
            // Expected
        }

        $wallet = $investor->wallet->fresh();
        $this->assertEquals('100.00', $wallet->available);
        $this->assertEquals('1000.00', $wallet->invested);
        $this->assertEquals('0.00', $wallet->earned);
    }

    // ── Edge: loan without investors ──

    public function test_repayment_fails_for_loan_without_investors(): void
    {
        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $row = $this->addInstallment($loan, '100.00', '10.00', 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No investors found');

        $this->service->processRepayment($loan->id, $row->id);
    }

    // ── Edge: unknown installment id ──

    public function test_repayment_fails_for_unknown_installment(): void
    {
        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['invested' => 1000]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $this->expectException(ModelNotFoundException::class);
        $this->service->processRepayment($loan->id, 999999);
    }

    // ── Edge: zero installment ──

    public function test_repayment_fails_for_zero_installment(): void
    {
        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['invested' => 1000]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $row = $this->addInstallment($loan, '0.00', '0.00', 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to distribute');

        $this->service->processRepayment($loan->id, $row->id);
    }

    // ── Only principal (no interest) ──

    public function test_repayment_with_zero_interest(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['available' => 0, 'invested' => 1000, 'earned' => 0]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $row = $this->addInstallment($loan, '200.00', '0.00', 1);
        $this->addInstallment($loan, '800.00', '0.00', 2);

        $this->service->processRepayment($loan->id, $row->id);

        $wallet = $investor->wallet->fresh();
        $this->assertEquals('800.00', $wallet->invested);
        $this->assertEquals('200.00', $wallet->available);
        $this->assertEquals('0.00', $wallet->earned);

        // Only 1 transaction (principal, no interest transaction since amount is 0)
        $this->assertEquals(1, Transaction::where('user_id', $investor->id)->count());
    }
}
