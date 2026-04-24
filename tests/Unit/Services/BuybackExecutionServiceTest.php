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
use App\Services\Loans\BuybackAlreadyExecutedException;
use App\Services\Loans\BuybackExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase F2 Step 2 — BuybackExecutionService: the orchestration centrepiece.
 * Each test exercises one failure path OR the happy path + a specific
 * invariant.
 */
class BuybackExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BuybackExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BuybackExecutionService::class);
    }

    /**
     * Build a late loan ready for buyback: originator with buyback=true +
     * principal_plus_interest coverage, N investors with funded wallets,
     * M unpaid schedules (status=pending), and an admin User.
     *
     * @return array{Loan, User, User[]}  [loan, admin, investors]
     */
    private function makeScenario(
        int $investorCount = 2,
        string $eachInvestment = '100.00',
        int $scheduleCount = 3,
        string $principalPerSchedule = null,
        string $interestPerSchedule = '5.00',
    ): array {
        $fundedAmount = bcmul($eachInvestment, (string) $investorCount, 2);
        $principalPerSchedule ??= bcdiv($fundedAmount, (string) $scheduleCount, 2);

        $orig = Originator::create([
            'name' => 'F2 Exec Test ' . uniqid(),
            'description' => 'Test',
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

        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => $fundedAmount, 'funded_amount' => $fundedAmount,
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => $scheduleCount, 'type' => 'consumer',
            'status' => 'late',
            'became_late_at' => now()->subDays(70),
        ]);

        for ($i = 0; $i < $scheduleCount; $i++) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'due_date' => now()->subMonths($scheduleCount - $i)->toDateString(),
                'principal' => $principalPerSchedule,
                'interest' => $interestPerSchedule,
                'total' => bcadd($principalPerSchedule, $interestPerSchedule, 2),
                'status' => 'pending',
            ]);
        }

        $investors = [];
        for ($i = 0; $i < $investorCount; $i++) {
            $u = User::factory()->create();
            // Wallet balances aren't mass-assignable — $fillable locks them
            // to prevent API-crafted balance changes. In tests we seed via
            // a raw DB update (same path the real WalletService uses via
            // forceFill).
            $u->wallet()->create();
            Wallet::where('user_id', $u->id)->update([
                'invested' => $eachInvestment,
            ]);
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
        [$loan, $admin, $investors] = $this->makeScenario(
            investorCount: 2,
            eachInvestment: '100.00',
            scheduleCount: 2,
            principalPerSchedule: '100.00',
            interestPerSchedule: '10.00',
        );

        $result = $this->service->execute($loan->id, $admin->id);

        // Aggregates: 200 principal + 20 interest = 220 total across 2 investors
        $this->assertSame($loan->id, $result->loanId);
        $this->assertSame($admin->id, $result->executedByAdminId);
        $this->assertSame('late', $result->fromStatus);
        $this->assertSame('principal_plus_interest', $result->coverageType);
        $this->assertSame('200.00', $result->totalPrincipal);
        $this->assertSame('20.00', $result->totalInterest);
        $this->assertSame('220.00', $result->totalAmount);
        $this->assertSame(2, $result->investorCount);
        $this->assertCount(2, $result->distributions);

        // Loan: terminal state, bought_back_at set
        $loan->refresh();
        $this->assertSame(Loan::STATUS_BOUGHT_BACK, $loan->status);
        $this->assertNotNull($loan->bought_back_at);

        // Each investor's wallet credited (invested → available + earned for interest)
        foreach ($investors as $investor) {
            $w = Wallet::where('user_id', $investor->id)->first();
            $this->assertGreaterThan(0, (float) $w->available,
                "investor #{$investor->id} available bucket must be credited");
            $this->assertGreaterThan(0, (float) $w->earned,
                "investor #{$investor->id} earned bucket must be credited (interest)");
        }

        // Transactions: 2 investors × 2 types (principal + interest) = 4 rows
        $txCount = Transaction::whereIn('type', [
            Transaction::TYPE_BUYBACK_PRINCIPAL,
            Transaction::TYPE_BUYBACK_INTEREST,
        ])->count();
        $this->assertSame(4, $txCount);
    }

    public function test_idempotency_second_call_throws_BuybackAlreadyExecutedException(): void
    {
        [$loan, $admin] = $this->makeScenario(
            investorCount: 1,
            eachInvestment: '100.00',
            scheduleCount: 1,
            principalPerSchedule: '100.00',
            interestPerSchedule: '10.00',
        );

        $this->service->execute($loan->id, $admin->id);

        $this->expectException(BuybackAlreadyExecutedException::class);
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_invalid_status_active_throws(): void
    {
        [$loan, $admin] = $this->makeScenario();
        // Force status to active (not in [late, default] — buyback not allowed)
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => 'active',
            'became_late_at' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Buyback is only allowed from 'late' or 'default'/");
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_dismissed_loan_throws_with_reactivate_hint(): void
    {
        [$loan, $admin] = $this->makeScenario();
        DB::table('loans')->where('id', $loan->id)->update([
            'buyback_dismissed_at' => now(),
            'buyback_dismissed_reason' => 'test',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Reactivate from the Queue first/');
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_zero_total_throws(): void
    {
        [$loan, $admin] = $this->makeScenario(scheduleCount: 2);
        // Mark ALL schedules paid → calculator returns 0 → service rejects.
        AmortizationSchedule::where('loan_id', $loan->id)->update(['status' => 'paid']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/calculated total is 0.00/');
        $this->service->execute($loan->id, $admin->id);
    }

    public function test_zero_invested_bucket_handled_via_clamp_not_rollback(): void
    {
        // Phase 2 audit — finding #2 behavior change.
        //
        // BEFORE fix: pro-rata drift pushing invested < 0 hit the
        // `chk_wallets_invested_non_negative` CHECK constraint, which
        // rolled back the entire DB::transaction — leaving operators
        // unable to close affected loans.
        //
        // AFTER fix (WalletService::creditAvailableFromInvested):
        // the clamp zeroes invested instead of going negative. Buyback
        // succeeds, wallet ends in a clamped state (invested=0,
        // available += full share), a WARNING is logged so ops see
        // cumulative drift.
        //
        // Rollback SEMANTICS for other (non-clamp) failure paths are
        // tested by the idempotency + invalid-status tests above.
        [$loan, $admin, $investors] = $this->makeScenario(
            investorCount: 2,
            eachInvestment: '100.00',
            scheduleCount: 2,
            principalPerSchedule: '100.00',
            interestPerSchedule: '10.00',
        );

        // Zero out the FIRST investor's invested bucket. Pre-fix this
        // forced a CHECK violation; post-fix it triggers the clamp.
        Wallet::where('user_id', $investors[0]->id)->update(['invested' => '0.00']);

        $this->service->execute($loan->id, $admin->id);

        // Loan DID transition — service succeeded via the clamp.
        $loan->refresh();
        $this->assertSame('bought_back', $loan->status);
        $this->assertNotNull($loan->bought_back_at);

        // Clamped wallet: invested still 0 (not negative), available
        // received the full principal share despite "insufficient"
        // invested bucket.
        $postWalletA = Wallet::where('user_id', $investors[0]->id)->first();
        $this->assertSame('0.00', (string) $postWalletA->invested,
            'clamp prevented invested from going negative');
        $this->assertTrue(
            bccomp((string) $postWalletA->available, '0', 2) > 0,
            'full principal share still credited to available',
        );
    }

    public function test_loan_event_written_with_correct_aggregate_metadata(): void
    {
        [$loan, $admin] = $this->makeScenario(
            investorCount: 2,
            eachInvestment: '100.00',
            scheduleCount: 2,
            principalPerSchedule: '100.00',
            interestPerSchedule: '10.00',
        );

        $this->service->execute($loan->id, $admin->id);

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_BUYBACK_COMPLETED)
            ->first();

        $this->assertNotNull($event, 'A buyback_completed event must be written');
        $this->assertSame('late', $event->from_status);
        $this->assertSame('bought_back', $event->to_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_ADMIN, $event->triggered_by);
        $this->assertSame($admin->id, $event->triggered_by_user_id);

        $meta = $event->metadata;
        $this->assertSame($admin->id, $meta['executed_by_admin_id']);
        $this->assertSame('principal_plus_interest', $meta['coverage_type']);
        $this->assertSame('220.00', $meta['total_amount']);
        $this->assertSame('200.00', $meta['total_principal']);
        $this->assertSame('20.00', $meta['total_interest']);
        $this->assertSame(2, $meta['investor_count']);
        $this->assertSame($loan->originator_id, $meta['originator_id']);
        $this->assertArrayHasKey('executed_at', $meta);
    }

    public function test_status_transition_late_to_bought_back(): void
    {
        [$loan, $admin] = $this->makeScenario();

        $this->service->execute($loan->id, $admin->id);

        $loan->refresh();
        $this->assertSame(Loan::STATUS_BOUGHT_BACK, $loan->status);
    }

    public function test_single_update_query_for_bought_back_at_and_status(): void
    {
        // Empirically verify the forceFill+transitionTo pattern produces
        // ONE UPDATE on loans that sets BOTH bought_back_at AND status.
        // Guards against a refactor that silently splits into two UPDATEs
        // — which would duplicate audit_logs rows and decouple the
        // terminal-state snapshot.
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
            'Exactly ONE UPDATE on loans — forceFill(bought_back_at) then transitionTo() must batch into a single persist');

        $q = array_values($loanUpdates)[0];
        $this->assertStringContainsString('bought_back_at', $q['query']);
        $this->assertStringContainsString('status', $q['query']);
    }
}
