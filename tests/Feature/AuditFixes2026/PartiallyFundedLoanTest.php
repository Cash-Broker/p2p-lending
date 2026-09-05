<?php

namespace Tests\Feature\AuditFixes2026;

use App\Enums\PayoutType;
use App\Filament\Resources\LoanResource;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\Loans\LoanStatusUpdaterService;
use App\Services\PayoutAccrualService;
use App\Services\ScheduledPayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAY-30 (audit 2026-09-01, owner 2026-09-03): a partially funded loan has an
 * end — when every investor's own plan has run it becomes `repaid`, stops
 * accepting investments, and can be closed early like any other loan.
 */
class PartiallyFundedLoanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function investor(string $balance = '1100.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, $balance, Transaction::TYPE_DEPOSIT, 'seed');

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array{0: Loan, 1: User} */
    private function partiallyFundedLoan(string $stake = '1000.00', string $investable = '2000.00'): array
    {
        Notification::fake();
        $loan = Loan::factory()->published()->create([
            'amount' => $investable, 'investable_amount' => $investable, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $user = $this->investor();
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), $stake, (string) Str::uuid(), $offerId);

        $loan = $loan->fresh();
        $this->assertSame(Loan::STATUS_FUNDING, $loan->status, 'fixture: the loan is only partially funded');

        return [$loan, $user];
    }

    public function test_the_payout_run_that_pays_the_last_row_closes_the_loan(): void
    {
        [$loan, $user] = $this->partiallyFundedLoan();

        $result = app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));

        $this->assertTrue($result['auto_repaid']);
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertSame('0.00', (string) $user->wallet->fresh()->invested);

        $event = LoanEvent::where('loan_id', $loan->id)->where('to_status', Loan::STATUS_REPAID)->firstOrFail();
        $this->assertSame(Loan::STATUS_FUNDING, $event->from_status);
        $this->assertSame('partially_funded_term_completed', $event->metadata['transition_reason']);
        $this->assertSame('1000.00', $event->metadata['funded_amount']);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_the_nightly_sweep_closes_a_completed_partially_funded_loan(): void
    {
        [$loan] = $this->partiallyFundedLoan();
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(400));
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status, 'the engine alone does not flip the status');

        $result = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertContains($loan->id, $result['auto_repaid']);
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
    }

    public function test_a_partially_funded_loan_with_open_rows_stays_open_and_investable(): void
    {
        [$loan] = $this->partiallyFundedLoan();
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(40));

        $result = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertNotContains($loan->id, $result['auto_repaid']);
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);

        $newcomer = $this->investor();
        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');
        app(InvestmentService::class)->invest($newcomer, $loan->fresh(), '500.00', (string) Str::uuid(), $offerId);
        $this->assertSame('1500.00', (string) $loan->fresh()->funded_amount);
    }

    public function test_a_funding_loan_without_investors_is_left_alone(): void
    {
        $loan = Loan::factory()->published()->create(['funded_amount' => 0]);
        $loan->transitionTo(Loan::STATUS_FUNDING);

        $result = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertNotContains($loan->id, $result['auto_repaid']);
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);
    }

    public function test_no_newcomer_can_restart_a_completed_loan_before_the_sweep(): void
    {
        [$loan] = $this->partiallyFundedLoan();
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(400));
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);

        $newcomer = $this->investor();
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');

        $this->actingAs($newcomer)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500, 'loan_offer_id' => $offerId,
        ], ['X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('loan');

        $this->assertSame('1000.00', (string) $loan->fresh()->funded_amount);
        $this->assertSame('1100.00', (string) $newcomer->wallet->fresh()->available);
    }

    public function test_full_early_closure_from_funding_settles_every_position(): void
    {
        [$loan, $user] = $this->partiallyFundedLoan();
        $this->assertTrue(LoanResource::isEarlyClosable($loan));

        Carbon::setTestNow(Carbon::now()->addDays(20));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id);
        Carbon::setTestNow();

        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertSame('0.00', (string) $user->wallet->fresh()->invested);
        $this->assertSame('1000.00', (string) Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL)->sum('amount'));
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_partial_early_closure_from_funding_shrinks_the_position_and_keeps_the_loan_open(): void
    {
        [$loan, $user] = $this->partiallyFundedLoan();

        Carbon::setTestNow(Carbon::now()->addDays(20));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '400.00');
        Carbon::setTestNow();

        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);
        $this->assertSame('600.00', (string) $user->wallet->fresh()->invested);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // Review 2026-09-05: the marketplace must not keep advertising the closed
        // 400 € as raised — capacity, funded_percentage and the activation trigger
        // read funded_amount on a FUNDING loan.
        $this->assertSame('600.00', (string) $loan->fresh()->funded_amount);
        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")->assertJsonPath('funded_percentage', 30);

        // A newcomer can take exactly the real remaining capacity (1 400 €); the loan
        // activates only when investors truly hold the investable amount.
        $newcomer = $this->investor('1500.00');
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($newcomer, $loan->fresh(), '1400.00', (string) Str::uuid(), $offerId);
        $fresh = $loan->fresh();
        $this->assertSame('2000.00', (string) $fresh->funded_amount);
        $this->assertSame(Loan::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_the_admin_select_still_cannot_set_repaid_on_a_funding_loan(): void
    {
        [$loan] = $this->partiallyFundedLoan();

        $this->assertSame([Loan::STATUS_DRAFT], $loan->selectableStatusTransitions());
    }

    // ── Review revisions (design panel 2026-09-03) ──

    public function test_closing_stamps_where_the_loan_ended_from_and_the_api_exposes_it(): void
    {
        [$loan, $user] = $this->partiallyFundedLoan();
        app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));

        $fresh = $loan->fresh();
        $this->assertSame(Loan::STATUS_FUNDING, $fresh->closed_from_status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertTrue($fresh->wasClosedWithoutFullFunding());

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('status', Loan::STATUS_REPAID)
            ->assertJsonPath('closed_from_status', Loan::STATUS_FUNDING);
    }

    public function test_the_kill_switch_holds_the_funding_branch_only(): void
    {
        PlatformSetting::set('funding_auto_close_enabled', false);
        [$loan] = $this->partiallyFundedLoan();

        $result = app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));
        $this->assertFalse($result['auto_repaid']);
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);

        $sweep = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();
        $this->assertNotContains($loan->id, $sweep['auto_repaid']);
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);

        PlatformSetting::set('funding_auto_close_enabled', true);
        $sweep = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();
        $this->assertContains($loan->id, $sweep['auto_repaid_funding']);
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
    }

    public function test_the_model_refuses_funding_to_repaid_while_investor_rows_are_open(): void
    {
        [$loan] = $this->partiallyFundedLoan();

        try {
            $loan->forceFill(['status' => Loan::STATUS_REPAID])->save();
            $this->fail('a funding loan with pending investor rows must not be parked behind repaid');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('still open', $e->getMessage());
        }

        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);
    }

    public function test_a_younger_position_keeps_the_loan_open_until_its_own_plan_runs(): void
    {
        [$loan] = $this->partiallyFundedLoan();

        // A second investor joins six months later — their plan ends six months after the first one's.
        Carbon::setTestNow(Carbon::now()->addDays(180));
        $late = $this->investor();
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($late, $loan->fresh(), '500.00', (string) Str::uuid(), $offerId);
        Carbon::setTestNow();

        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(400));
        $sweep = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertNotContains($loan->id, $sweep['auto_repaid']);
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status, 'the younger position still has pending rows');
        $this->assertSame(1, bccomp((string) $late->wallet->fresh()->invested, '0', 2));
    }

    public function test_a_second_sweep_is_a_no_op(): void
    {
        [$loan] = $this->partiallyFundedLoan();
        app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);

        $sweep = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertNotContains($loan->id, $sweep['auto_repaid']);
        $this->assertSame(1, LoanEvent::where('loan_id', $loan->id)->where('to_status', Loan::STATUS_REPAID)->count());
    }
}
