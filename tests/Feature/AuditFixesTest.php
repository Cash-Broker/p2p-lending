<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DepositService;
use App\Services\RepaymentService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $walletBalances = []): User
    {
        $user = User::factory()->kycApproved()->create([
            'email_verified_at' => now(),
        ]);

        $wallet = $user->wallet()->create();

        if ($walletBalances) {
            $wallet->forceFill($walletBalances)->save();
        }

        return $user;
    }

    private function createActiveLoanWithInvestors(int $investorCount = 3, string $loanAmount = '900.00'): array
    {
        $originator = Originator::factory()->create();
        $borrower = \App\Models\Borrower::factory()->create();
        $borrower->anonymizedProfile()->create(\Database\Factories\BorrowerAnonymizedProfileFactory::new()->definition());

        $investmentAmount = bcdiv($loanAmount, (string) $investorCount, 2);

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'amount' => $loanAmount,
            'funded_amount' => $loanAmount,
            'status' => Loan::STATUS_ACTIVE,
        ]);

        $investors = [];
        foreach (range(1, $investorCount) as $i) {
            $investor = $this->createVerifiedInvestor([
                'available' => '0.00',
                'invested' => $investmentAmount,
            ]);

            Investment::factory()->create([
                'user_id' => $investor->id,
                'loan_id' => $loan->id,
                'amount' => $investmentAmount,
            ]);

            $investors[] = $investor;
        }

        return ['loan' => $loan, 'investors' => $investors, 'investmentAmount' => $investmentAmount];
    }

    // ── Finding 1.1: Repayment rounding — no penny loss ──

    public function test_repayment_rounding_three_investors_sum_equals_total(): void
    {
        Notification::fake();

        $data = $this->createActiveLoanWithInvestors(3, '900.00');
        $loan = $data['loan'];

        $principalAmount = '100.01'; // Forces uneven split: 33.33 + 33.33 + 33.35
        $interestAmount = '10.01';

        app(RepaymentService::class)->processRepayment(
            $loan->id,
            $principalAmount,
            $interestAmount
        );

        // Sum all principal transactions — must equal exactly 100.01
        $totalPrincipalDistributed = Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)
            ->sum('amount');

        $totalInterestDistributed = Transaction::where('type', Transaction::TYPE_REPAYMENT_INTEREST)
            ->sum('amount');

        $this->assertEquals(
            0,
            bccomp($principalAmount, number_format($totalPrincipalDistributed, 2, '.', ''), 2),
            "Principal distributed ({$totalPrincipalDistributed}) must equal total ({$principalAmount})"
        );

        $this->assertEquals(
            0,
            bccomp($interestAmount, number_format($totalInterestDistributed, 2, '.', ''), 2),
            "Interest distributed ({$totalInterestDistributed}) must equal total ({$interestAmount})"
        );
    }

    // ── Finding 1.2 + 2.2: Withdrawal reservation ──

    public function test_withdrawal_reservation_reduces_available(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '5000.00']);

        app(WithdrawalService::class)->createRequest($investor->id, '1000.00', 'BG80BNBG96611020345678');

        $wallet = $investor->wallet->fresh();
        $this->assertEquals('4000.00', $wallet->available);
        $this->assertEquals('1000.00', $wallet->reserved);
    }

    public function test_withdrawal_reject_restores_available(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $investor = $this->createVerifiedInvestor(['available' => '5000.00']);

        Notification::fake();

        $withdrawal = app(WithdrawalService::class)->createRequest($investor->id, '1000.00', 'BG80BNBG96611020345678');
        app(WithdrawalService::class)->reject($withdrawal->id, $admin->id);

        $wallet = $investor->wallet->fresh();
        $this->assertEquals('5000.00', $wallet->available);
        $this->assertEquals('0.00', $wallet->reserved);
    }

    public function test_double_withdrawal_cannot_exceed_available(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '1000.00']);

        // First withdrawal of 800 should succeed
        app(WithdrawalService::class)->createRequest($investor->id, '800.00', 'BG80BNBG96611020345678');

        // Second withdrawal of 500 should fail — only 200 available
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(WithdrawalService::class)->createRequest($investor->id, '500.00', 'BG80BNBG96611020345678');
    }

    // ── Finding 2.1: Invest idempotency ──

    public function test_invest_idempotency_same_key_returns_same_investment(): void
    {
        $investor = $this->createVerifiedInvestor(['available' => '5000.00']);
        $originator = Originator::factory()->create();
        $borrower = \App\Models\Borrower::factory()->create();
        $borrower->anonymizedProfile()->create(\Database\Factories\BorrowerAnonymizedProfileFactory::new()->definition());

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'amount' => '10000.00',
            'funded_amount' => '0.00',
            'status' => Loan::STATUS_PUBLISHED,
        ]);

        $response1 = $this->actingAs($investor)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500.00,
            'loan_offer_id' => $loan->offers()->value('id'),
        ], ['X-Idempotency-Key' => 'test-key-123']);

        $response1->assertStatus(201);
        $investmentId = $response1->json('investment.id');

        $response2 = $this->actingAs($investor)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500.00,
            'loan_offer_id' => $loan->offers()->value('id'),
        ], ['X-Idempotency-Key' => 'test-key-123']);

        $response2->assertStatus(201);
        $this->assertEquals($investmentId, $response2->json('investment.id'));

        // Wallet should be debited only once
        $wallet = $investor->wallet->fresh();
        $this->assertEquals('4500.00', $wallet->available);
        $this->assertEquals('500.00', $wallet->invested);
    }

    // ── Finding 4.1: Loan status transitions ──

    public function test_loan_status_cannot_go_active_to_draft(): void
    {
        $originator = Originator::factory()->create();
        $borrower = \App\Models\Borrower::factory()->create();

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => Loan::STATUS_ACTIVE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid loan status transition');

        $loan->transitionTo(Loan::STATUS_DRAFT);
    }

    // ── Finding 4.2: Loan term immutability ──

    public function test_loan_immutability_cannot_edit_amount_on_published(): void
    {
        $originator = Originator::factory()->create();
        $borrower = \App\Models\Borrower::factory()->create();

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => Loan::STATUS_PUBLISHED,
            'amount' => '10000.00',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Cannot modify 'amount'");

        $loan->update(['amount' => '99999.00']);
    }

    // ── Finding 3.1 + 3.2: Draft loan not visible to investor ──

    public function test_draft_loan_not_visible_to_investor_via_api(): void
    {
        $investor = $this->createVerifiedInvestor();
        $originator = Originator::factory()->create();
        $borrower = \App\Models\Borrower::factory()->create();
        $borrower->anonymizedProfile()->create(\Database\Factories\BorrowerAnonymizedProfileFactory::new()->definition());

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => Loan::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}");
        $response->assertStatus(403);
    }

    // ── Finding 4.5: Repayment on non-active loan rejected ──

    public function test_repayment_on_non_active_loan_rejected(): void
    {
        $originator = Originator::factory()->create();
        $borrower = \App\Models\Borrower::factory()->create();

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => Loan::STATUS_FUNDED,
            'funded_amount' => '1000.00',
        ]);

        $investor = $this->createVerifiedInvestor(['invested' => '1000.00']);
        Investment::factory()->create([
            'user_id' => $investor->id,
            'loan_id' => $loan->id,
            'amount' => '1000.00',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Repayment can only be processed for active or late loans.');

        app(RepaymentService::class)->processRepayment($loan->id, '100.00', '10.00');
    }

    // ── Finding 2.4: Double repayment with same schedule ──

    public function test_double_repayment_same_schedule_rejected(): void
    {
        Notification::fake();

        $data = $this->createActiveLoanWithInvestors(1, '1000.00');
        $loan = $data['loan'];

        $schedule = AmortizationSchedule::factory()->create([
            'loan_id' => $loan->id,
            'status' => 'pending',
        ]);

        // First repayment succeeds
        app(RepaymentService::class)->processRepayment($loan->id, '100.00', '10.00', $schedule->id);

        $this->assertEquals('paid', $schedule->fresh()->status);

        // Second repayment with same schedule fails
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already been paid');

        app(RepaymentService::class)->processRepayment($loan->id, '100.00', '10.00', $schedule->id);
    }

    // ── Finding 8.1: DB constraints reject negative balance ──

    public function test_db_constraint_rejects_negative_balance(): void
    {
        // This test is only meaningful on MySQL/MariaDB with CHECK constraints.
        // On SQLite, CHECK constraints are not enforced, so we test the service layer instead.
        $investor = $this->createVerifiedInvestor(['available' => '100.00']);

        $this->expectException(\InvalidArgumentException::class);

        app(\App\Services\WalletService::class)->debit(
            $investor->id,
            '200.00',
            Transaction::TYPE_WITHDRAWAL,
            'Test overdraft'
        );
    }

    // ── Finding 7.1: Path traversal returns 403 ──

    public function test_path_traversal_returns_403(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($admin)->get('/admin/kyc-document/../../.env');
        $response->assertStatus(403);
    }

    // ── Finding 28: Ledger reconciliation ──

    public function test_ledger_reconciliation_passes_on_clean_data(): void
    {
        Notification::fake();

        $investor = $this->createVerifiedInvestor();

        // Process a deposit through the service
        $depositService = app(DepositService::class);
        $deposit = $depositService->createRequest($investor->id, '1000.00');
        $depositService->approve($deposit->id, 1);

        $exitCode = Artisan::call('ledger:reconcile');
        $this->assertEquals(0, $exitCode, 'Reconciliation should pass on clean data');
    }

    public function test_ledger_reconciliation_detects_mismatch(): void
    {
        Notification::fake();

        $investor = $this->createVerifiedInvestor();

        // Process a deposit through the service
        $depositService = app(DepositService::class);
        $deposit = $depositService->createRequest($investor->id, '1000.00');
        $depositService->approve($deposit->id, 1);

        // Corrupt the wallet balance directly (bypass service)
        $investor->wallet->forceFill(['available' => '9999.00'])->save();

        $exitCode = Artisan::call('ledger:reconcile');
        $this->assertEquals(1, $exitCode, 'Reconciliation should fail on corrupted data');
    }

    // ── Audit H4: audit_logs DB-level immutability ──

    public function test_audit_logs_db_trigger_blocks_update(): void
    {
        // Defense-in-depth: even raw SQL UPDATE on audit_logs (e.g. from
        // a compromised admin shell or leaked DB credentials) must fail.
        // App-layer guards in AuditLogResource prevent this through
        // Filament UI; this trigger catches everything else.
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('SQLite does not support SIGNAL — trigger only applies to MySQL/MariaDB.');
        }

        // Generate an audit_logs row by mutating an Auditable model.
        $user = User::factory()->create();
        $user->update(['name' => 'Updated Name']);

        $auditId = \DB::table('audit_logs')->latest('id')->value('id');
        $this->assertNotNull($auditId, 'Auditable trait must have written a row');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('Audit logs are immutable and cannot be updated');

        // Bypass model events entirely — this is the attack scenario:
        // raw DB access trying to rewrite history.
        \DB::table('audit_logs')->where('id', $auditId)->update(['user_id' => 999]);
    }

    public function test_audit_logs_db_trigger_blocks_delete(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('SQLite does not support SIGNAL — trigger only applies to MySQL/MariaDB.');
        }

        $user = User::factory()->create();
        $user->update(['name' => 'Updated Name']);

        $auditId = \DB::table('audit_logs')->latest('id')->value('id');
        $this->assertNotNull($auditId);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('Audit logs are immutable and cannot be deleted');

        \DB::table('audit_logs')->where('id', $auditId)->delete();
    }

    public function test_audit_logs_inserts_still_work(): void
    {
        // Sanity check: triggers must NOT block INSERT, only UPDATE/DELETE.
        // Auditable trait depends on inserts working on every model event.
        $countBefore = \DB::table('audit_logs')->count();

        $user = User::factory()->create();

        $countAfter = \DB::table('audit_logs')->count();
        $this->assertGreaterThan($countBefore, $countAfter,
            'Auditable trait must still write audit_logs rows after the immutability triggers are installed');
    }
}
