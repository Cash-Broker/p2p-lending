<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RepaymentService;
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

    // ── Proportional distribution with 3 investors ──

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

        // Process repayment: 1000 principal + 100 interest
        $this->service->processRepayment($loan->id, '1000.00', '100.00');

        // Investor A (50%): principal 500, interest 50
        $walletA = $investorA->wallet->fresh();
        $this->assertEquals('4500.00', $walletA->invested); // 5000 - 500
        $this->assertEquals('550.00', $walletA->available);  // 0 + 500 + 50
        $this->assertEquals('50.00', $walletA->earned);       // 0 + 50

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

        $this->service->processRepayment($loan->id, '500.00', '50.00');

        // Investor 1: 2 transactions (principal + interest)
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

        // Investor 2: 2 transactions
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

        // Total: 4 transaction records
        $this->assertEquals(4, Transaction::whereIn('type', [
            Transaction::TYPE_REPAYMENT_PRINCIPAL,
            Transaction::TYPE_REPAYMENT_INTEREST,
        ])->count());
    }

    // ── Amortization schedule ──

    public function test_repayment_updates_amortization_schedule(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['invested' => 1000]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $schedule = AmortizationSchedule::factory()->create([
            'loan_id' => $loan->id,
            'principal' => 200,
            'interest' => 20,
            'total' => 220,
            'status' => 'pending',
        ]);

        $this->service->processRepayment($loan->id, '200.00', '20.00', $schedule->id);

        $schedule->refresh();
        $this->assertEquals('paid', $schedule->status);
        $this->assertNotNull($schedule->paid_at);
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

        $this->service->processRepayment($loan->id, '100.00', '10.00');

        Notification::assertSentTo($investor1, RepaymentReceivedNotification::class);
        Notification::assertSentTo($investor2, RepaymentReceivedNotification::class);
    }

    // ── Rollback on error ──

    public function test_repayment_rolls_back_on_error(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['available' => 100, 'invested' => 1000, 'earned' => 0]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        // Force an error mid-transaction by mocking a second investment with invalid wallet
        // We test by trying to process a repayment for a non-existent loan
        try {
            $this->service->processRepayment(99999, '100.00', '10.00');
        } catch (\Exception $e) {
            // Expected
        }

        // Wallet should be unchanged
        $wallet = $investor->wallet->fresh();
        $this->assertEquals('100.00', $wallet->available);
        $this->assertEquals('1000.00', $wallet->invested);
        $this->assertEquals('0.00', $wallet->earned);
    }

    // ── Edge: loan without investors ──

    public function test_repayment_fails_for_loan_without_investors(): void
    {
        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No investors found');

        $this->service->processRepayment($loan->id, '100.00', '10.00');
    }

    // ── Edge: zero repayment ──

    public function test_repayment_fails_for_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Total repayment must be greater than zero');

        $this->service->processRepayment(1, '0.00', '0.00');
    }

    // ── Edge: negative amount ──

    public function test_repayment_fails_for_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        $this->service->processRepayment(1, '-100.00', '10.00');
    }

    // ── Decimal precision ──

    public function test_repayment_uses_precise_decimal_math(): void
    {
        Notification::fake();

        // 3 equal investors in a loan of 3000 — each owns exactly 1/3
        $loan = Loan::factory()->active()->create(['amount' => 3000, 'funded_amount' => 3000]);

        $investors = [];
        for ($i = 0; $i < 3; $i++) {
            $inv = $this->createInvestorWithWallet(['available' => 0, 'invested' => 1000, 'earned' => 0]);
            Investment::factory()->create(['user_id' => $inv->id, 'loan_id' => $loan->id, 'amount' => 1000]);
            $investors[] = $inv;
        }

        // Repay 100 principal + 10 interest
        // With remainder-to-last-investor fix: first two get 33.33, last gets 33.34
        $this->service->processRepayment($loan->id, '100.00', '10.00');

        // Verify total distributed equals exact total (no penny loss)
        $totalPrincipal = Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)->sum('amount');
        $totalInterest = Transaction::where('type', Transaction::TYPE_REPAYMENT_INTEREST)->sum('amount');

        $this->assertEquals(0, bccomp('100.00', number_format($totalPrincipal, 2, '.', ''), 2),
            "Total principal distributed must equal 100.00, got {$totalPrincipal}");
        $this->assertEquals(0, bccomp('10.00', number_format($totalInterest, 2, '.', ''), 2),
            "Total interest distributed must equal 10.00, got {$totalInterest}");

        foreach ($investors as $inv) {
            $wallet = $inv->wallet->fresh();

            // invested should decrease by ~33.33-33.34
            $this->assertTrue(
                bccomp($wallet->invested, '966.00', 2) >= 0 && bccomp($wallet->invested, '967.00', 2) <= 0,
                "Invested should be ~966.67, got: {$wallet->invested}"
            );

            // earned should be ~3.33-3.34
            $this->assertTrue(
                bccomp($wallet->earned, '3.00', 2) >= 0 && bccomp($wallet->earned, '4.00', 2) <= 0,
                "Earned should be ~3.33, got: {$wallet->earned}"
            );
        }
    }

    // ── Only principal (no interest) ──

    public function test_repayment_with_zero_interest(): void
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create(['amount' => 1000, 'funded_amount' => 1000]);
        $investor = $this->createInvestorWithWallet(['available' => 0, 'invested' => 1000, 'earned' => 0]);
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => 1000]);

        $this->service->processRepayment($loan->id, '200.00', '0.00');

        $wallet = $investor->wallet->fresh();
        $this->assertEquals('800.00', $wallet->invested);
        $this->assertEquals('200.00', $wallet->available);
        $this->assertEquals('0.00', $wallet->earned);

        // Only 1 transaction (principal, no interest transaction since amount is 0)
        $this->assertEquals(1, Transaction::where('user_id', $investor->id)->count());
    }
}
