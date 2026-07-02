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
        ?string $principalPerSchedule = null,
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

    public function test_invested_underflow_rolls_back_and_never_manufactures_money(): void
    {
        // Phase 2 audit follow-up — the old clamp MANUFACTURED money: when a
        // principal return exceeded the invested bucket it zeroed invested but
        // still credited the full amount to available. That silent
        // money-creation path is removed. An underflow now THROWS and rolls
        // the whole buyback back — nothing is credited, no balance is conjured.
        //
        // We force the underflow by desyncing a wallet (invested=0) from its
        // Investment row (amount=100), the artificial stand-in for a genuine
        // distribution bug. In normal operation invested == Σ investment and
        // this branch is unreachable.
        [$loan, $admin, $investors] = $this->makeScenario(
            investorCount: 2,
            eachInvestment: '100.00',
            scheduleCount: 2,
            principalPerSchedule: '100.00',
            interestPerSchedule: '10.00',
        );

        Wallet::where('user_id', $investors[0]->id)->update(['invested' => '0.00']);

        try {
            $this->service->execute($loan->id, $admin->id);
            $this->fail('Expected an underflow to throw rather than manufacture balance.');
        } catch (\App\Services\InvestedUnderflowException $e) {
            // expected — loud, safe.
        }

        // Everything rolled back: loan still late, no buyback transactions,
        // and NO money was created on the underflowing wallet.
        $loan->refresh();
        $this->assertSame('late', $loan->status, 'buyback must roll back, loan stays late');

        $postWalletA = Wallet::where('user_id', $investors[0]->id)->first();
        $this->assertSame('0.00', (string) $postWalletA->invested);
        $this->assertSame('0.00', (string) $postWalletA->available,
            'no balance manufactured on the underflowing wallet');

        $this->assertSame(
            0,
            Transaction::whereIn('type', [
                Transaction::TYPE_BUYBACK_PRINCIPAL,
                Transaction::TYPE_BUYBACK_INTEREST,
            ])->count(),
            'no buyback transactions survive the rollback',
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

    public function test_metadata_reports_actually_credited_principal_when_schedule_and_ledger_diverge(): void
    {
        // Regression (audit 2026-07-02): the loan_event used to record
        // calc->principal (Σ pending/late schedule rows), while distribute()
        // credits each investor their EXACT ledger outstanding. A 'default'
        // schedule row holds capital outside the calc sum, so the two diverge —
        // the audit record must report what was actually paid.
        [$loan, $admin, $investors] = $this->makeScenario(
            investorCount: 1,
            eachInvestment: '200.00',
            scheduleCount: 2,
            principalPerSchedule: '100.00',
            interestPerSchedule: '5.00',
        );
        $loan->amortizationSchedules()->orderBy('due_date')->first()
            ->forceFill(['status' => 'default'])->save();

        $result = $this->service->execute($loan->id, $admin->id);

        // Credited: full ledger outstanding (200) + the covered interest of
        // the single remaining unpaid row (5).
        $this->assertSame('200.00', $result->totalPrincipal);
        $this->assertSame('5.00', $result->totalInterest);
        $this->assertSame('205.00', $result->totalAmount);

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_BUYBACK_COMPLETED)
            ->first();
        $this->assertSame('200.00', $event->metadata['total_principal']);
        $this->assertSame('205.00', $event->metadata['total_amount']);

        $wallet = Wallet::where('user_id', $investors[0]->id)->first();
        $this->assertSame('0.00', $wallet->invested, 'investor fully paid out');
    }

    public function test_unpaid_borrower_rows_closed_and_default_rows_untouched_on_buyback(): void
    {
        // Terminal loans must not keep pending/late rows alive — the nightly
        // days_late refresh would tick them forever. 'default' rows stay:
        // they are explicitly out of buyback scope (admin manual handling).
        [$loan, $admin] = $this->makeScenario(scheduleCount: 3);
        $rows = $loan->amortizationSchedules()->orderBy('due_date')->get();
        $rows[0]->forceFill(['status' => 'late', 'became_late_at' => now()->subDays(5), 'days_late' => 5])->save();
        $rows[1]->forceFill(['status' => 'default'])->save();

        $this->service->execute($loan->id, $admin->id);

        $this->assertSame(0, $loan->amortizationSchedules()->whereIn('status', ['pending', 'late'])->count(),
            'pending/late rows are closed on the terminal transition');
        $this->assertSame(1, $loan->amortizationSchedules()->where('status', 'default')->count(),
            'default rows remain admin scope');
        $this->assertNotNull($loan->amortizationSchedules()->orderBy('due_date')->first()->fresh()->paid_at);
    }
}
