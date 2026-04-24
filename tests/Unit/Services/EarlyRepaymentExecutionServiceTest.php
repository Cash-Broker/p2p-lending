<?php

namespace Tests\Unit\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Loans\EarlyRepaymentAlreadyExecutedException;
use App\Services\Loans\EarlyRepaymentExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase F3 Step 2 — EarlyRepaymentExecutionService.
 *
 * Each test exercises one failure path OR the happy path + a specific
 * invariant (idempotency, rollback, single-UPDATE, metadata, transition).
 * Mirror of F2 BuybackExecutionServiceTest with F3-specific semantics.
 */
class EarlyRepaymentExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private EarlyRepaymentExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EarlyRepaymentExecutionService::class);
    }

    /**
     * Active loan with 2 unpaid schedules and 2 investors, each with a
     * funded wallet. Returns [loan, admin, investors].
     *
     * @return array{Loan, User, array<int, User>}
     */
    private function makeScenario(
        int $investorCount = 2,
        string $eachInvestment = '100.00',
        string $status = 'active',
    ): array {
        $fundedAmount = bcmul($eachInvestment, (string) $investorCount, 2);

        $orig = Originator::create([
            'name' => 'F3 Exec Test ' . uniqid(),
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

        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => $fundedAmount, 'funded_amount' => $fundedAmount,
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 2, 'type' => 'consumer',
            'status' => $status,
        ]);

        $principalPer = bcdiv($fundedAmount, '2', 2);
        foreach ([15, 45] as $offset) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'due_date' => now()->addDays($offset)->toDateString(),
                'principal' => $principalPer,
                'interest' => '10.00',
                'total' => bcadd($principalPer, '10.00', 2),
                'status' => 'pending',
            ]);
        }

        $investors = [];
        for ($i = 0; $i < $investorCount; $i++) {
            $u = User::factory()->create();
            $u->wallet()->create();
            Wallet::where('user_id', $u->id)->update(['invested' => $eachInvestment]);
            Investment::create([
                'user_id' => $u->id, 'loan_id' => $loan->id,
                'amount' => $eachInvestment, 'invested_at' => now(),
            ]);
            $investors[] = $u;
        }

        $admin = User::factory()->create(['role' => 'admin']);

        return [$loan->fresh(), $admin, $investors];
    }

    public function test_happy_path_full_execution(): void
    {
        [$loan, $admin, $investors] = $this->makeScenario();

        $result = $this->service->execute($loan->id, $admin->id);

        $this->assertSame($loan->id, $result->loanId);
        $this->assertSame($admin->id, $result->executedByAdminId);
        $this->assertSame('active', $result->fromStatus);
        $this->assertSame('200.00', $result->totalPrincipal);
        // Interest for "next upcoming" schedule (+15d) = 10.00
        $this->assertSame('10.00', $result->totalInterest);
        $this->assertSame('210.00', $result->totalAmount);
        $this->assertSame(2, $result->investorCount);

        $loan->refresh();
        $this->assertSame(Loan::STATUS_REPAID, $loan->status);
        $this->assertNotNull($loan->early_repaid_at);
        $this->assertSame('210.00', (string) $loan->early_repayment_amount);

        foreach ($investors as $investor) {
            $w = Wallet::where('user_id', $investor->id)->first();
            $this->assertGreaterThan(0, (float) $w->available);
            $this->assertGreaterThan(0, (float) $w->earned);
        }

        $txCount = Transaction::whereIn('type', [
            Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL,
            Transaction::TYPE_EARLY_REPAYMENT_INTEREST,
        ])->count();
        $this->assertSame(4, $txCount, '2 investors × 2 tx types = 4 ledger rows');
    }

    public function test_idempotency_second_call_throws_already_executed_exception(): void
    {
        [$loan, $admin] = $this->makeScenario();

        $this->service->execute($loan->id, $admin->id);

        $this->expectException(EarlyRepaymentAlreadyExecutedException::class);
        $this->expectExceptionMessageMatches('/already early-repaid on/');
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_repaid_through_normal_completion_throws_invalid_argument(): void
    {
        // Differentiated message: loan reached status=repaid through scheduled
        // completion (early_repaid_at IS NULL). Should throw InvalidArgument,
        // NOT EarlyRepaymentAlreadyExecuted (that's for post-F3-execute hits).
        [$loan, $admin] = $this->makeScenario();
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => 'repaid',
            'early_repaid_at' => null,  // distinguishes from F3-closed
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already repaid through scheduled completion/');
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_bought_back_loan_throws_with_originator_message(): void
    {
        [$loan, $admin] = $this->makeScenario();
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => 'bought_back',
            'bought_back_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/originator owns the debt/');
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_pre_activation_status_throws(): void
    {
        // Loans in draft/published/funding/funded shouldn't early-repay —
        // there's nothing to close.
        [$loan, $admin] = $this->makeScenario();
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'funded']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/only active\/late\/default loans support early repayment/");
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_zero_total_throws(): void
    {
        [$loan, $admin] = $this->makeScenario();
        // Mark all schedules paid → no unpaid → calculator throws on zero.
        AmortizationSchedule::where('loan_id', $loan->id)->update(['status' => 'paid']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no unpaid schedule items/');
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_rollback_on_wallet_failure_leaves_no_partial_state(): void
    {
        // Mirror F2 pattern: first investor's wallet has invested=0, so
        // the first WalletService::earlyRepayPrincipal push goes negative
        // and CHECK rejects the UPDATE. Entire DB::transaction rolls back —
        // no Transaction rows, no loan status change, no LoanEvent.
        [$loan, $admin, $investors] = $this->makeScenario();
        Wallet::where('user_id', $investors[0]->id)->update(['invested' => '0.00']);

        $preTxCount = Transaction::count();
        $preEventCount = LoanEvent::count();

        try {
            $this->service->execute($loan->id, $admin->id);
            $this->fail('Expected wallet CHECK violation');
        } catch (\Throwable $e) {
            // any throw acceptable — specific type depends on driver
        }

        $loan->refresh();
        $this->assertSame('active', $loan->status);
        $this->assertNull($loan->early_repaid_at);
        $this->assertNull($loan->early_repayment_amount);

        $this->assertSame($preTxCount, Transaction::count(),
            'DB::transaction must roll back ALL Transaction writes');
        $this->assertSame($preEventCount, LoanEvent::count(),
            'DB::transaction must roll back the LoanEvent write');
    }

    public function test_loan_event_written_with_correct_aggregate_metadata(): void
    {
        [$loan, $admin] = $this->makeScenario();

        $this->service->execute($loan->id, $admin->id);

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_EARLY_REPAYMENT_COMPLETED)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('active', $event->from_status);
        $this->assertSame('repaid', $event->to_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_ADMIN, $event->triggered_by);
        $this->assertSame($admin->id, $event->triggered_by_user_id);

        $meta = $event->metadata;
        $this->assertSame($admin->id, $meta['executed_by_admin_id']);
        $this->assertSame('active', $meta['from_status']);
        $this->assertSame('210.00', $meta['total_amount']);
        $this->assertSame('200.00', $meta['total_principal']);
        $this->assertSame('10.00', $meta['total_interest']);
        $this->assertSame(2, $meta['investor_count']);
        $this->assertArrayHasKey('executed_at', $meta);
        $this->assertArrayNotHasKey('investor_distributions', $meta,
            'Per-investor distributions MUST NOT leak into event metadata');
    }

    public function test_status_transition_handles_all_valid_from_statuses(): void
    {
        // The service accepts active, late, and default as `from` statuses.
        // Cover all three — one makeScenario per status, verify the
        // loan_event captures the correct from_status.
        foreach (['active', 'late', 'default'] as $fromStatus) {
            [$loan, $admin] = $this->makeScenario(status: $fromStatus);

            $result = $this->service->execute($loan->id, $admin->id);

            $this->assertSame($fromStatus, $result->fromStatus);
            $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        }
    }

    public function test_single_update_query_for_early_repaid_at_and_amount_and_status(): void
    {
        // Empirical regression guard: forceFill(early_repaid_at +
        // early_repayment_amount) + transitionTo() emits ONE UPDATE on
        // loans, setting ALL THREE fields in a single persist. Split into
        // multiple UPDATEs would produce multiple audit_logs rows and
        // risk state divergence mid-transaction.
        [$loan, $admin] = $this->makeScenario();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->execute($loan->id, $admin->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $loanUpdates = array_filter(
            $queries,
            fn ($q) => preg_match('/^update\s+`?loans`?\s+set/i', $q['query']) === 1,
        );

        $this->assertCount(1, $loanUpdates,
            'Exactly ONE UPDATE on loans — forceFill batch + transitionTo persist all 3 fields in one statement');

        $sql = array_values($loanUpdates)[0]['query'];
        $this->assertStringContainsString('early_repaid_at', $sql);
        $this->assertStringContainsString('early_repayment_amount', $sql);
        $this->assertStringContainsString('status', $sql);
    }
}
