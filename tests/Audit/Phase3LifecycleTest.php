<?php

namespace Tests\Audit;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Loans\LoanStatusUpdaterService;
use App\Services\RepaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Step 3 — Lifecycle completeness audit.
 *
 * Probes for "stuck state" scenarios — intermediate loan / wallet /
 * request states with no automated exit path. Also verifies FK
 * RESTRICT behaviour that protects against orphaned child rows.
 *
 * Findings tagged P3-F5 onward.
 */
class Phase3LifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════════════════════
    // P3-F5 candidate — `active → repaid` has no automation
    // ══════════════════════════════════════════════════════════════

    public function test_P3_F5_active_loan_auto_closes_when_all_schedules_paid(): void
    {
        // **P3-F5 FIXED** — see DECISIONS.md P3-02.
        //
        // Previous (pre-fix) behaviour: cleanly-completing loans stayed
        // ACTIVE forever until admin manually transitioned them.
        //
        // Post-fix: `LoanStatusUpdaterService::autoRepayCompletedLoans`
        // is called by the `loans:process-late` cron after existing
        // late/recovery passes. Transitions any `status=active` loan
        // whose schedules are all `paid` to `repaid`.
        //
        // Test verifies the NEW auto-close path. If this test ever
        // regresses, the v1 operational burden of manually closing
        // every completed loan returns.
        [$loan, $investor] = $this->makeActiveLoanWithSchedule();

        // Process all scheduled repayments.
        foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            app(RepaymentService::class)->processRepayment($loan->id, $s->id);
        }

        // All schedules paid.
        $unpaidCount = $loan->amortizationSchedules()->whereNotIn('status', ['paid'])->count();
        $this->assertSame(0, $unpaidCount);

        // Pre-autoRepay: still active.
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);

        // Call the P3-F5 auto-repay pass. Transitions to REPAID.
        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status,
            'P3-F5 fix: active loan with all schedules paid auto-closes to REPAID');
    }

    public function test_admin_workaround_manual_transition_closes_completed_loan(): void
    {
        // Until P3-F5 is fixed, the admin workaround is to manually
        // call transitionTo(STATUS_REPAID) via Filament. Verify the
        // state machine permits it (ACTIVE → REPAID is allowed).
        [$loan, $investor] = $this->makeActiveLoanWithSchedule();
        foreach ($loan->amortizationSchedules()->orderBy('due_date')->get() as $s) {
            app(RepaymentService::class)->processRepayment($loan->id, $s->id);
        }

        $loan->fresh()->transitionTo(Loan::STATUS_REPAID);
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════
    // P3-F6 candidate — FUNDED loan requires manual activation
    // ══════════════════════════════════════════════════════════════

    public function test_P3_F6_funded_loan_stays_funded_without_manual_activate(): void
    {
        // **Documents finding P3-F6 (LOW-MEDIUM).**
        //
        // A loan that reaches FUNDED requires admin to click
        // "Активирай" in Filament LoanResource. If admin forgets /
        // is on vacation / has to review, the loan sits FUNDED:
        //   - investors' money is in `wallet.invested` already
        //   - NO amortization schedule exists yet (generated on
        //     funded → active)
        //   - NO repayments possible (RepaymentService rejects
        //     non-active-or-late loans)
        //   - Borrower may not even know they can start paying
        //
        // The state machine DOES permit funded → active; there's
        // just no automation or reminder. Not a bug per se — admin
        // activation is a deliberate gate (verify loan agreement
        // was signed, borrower identified, etc.). But the lack
        // of any stale-loan alert is a gap.
        $loan = $this->makeLoanInStatus(Loan::STATUS_FUNDED);

        $this->assertSame(Loan::STATUS_FUNDED, $loan->fresh()->status);
        $this->assertSame(0, $loan->amortizationSchedules()->count(),
            'schedule only generated on funded → active');

        // Test passes — it asserts the documented behaviour so
        // future closure of this gap (e.g. stale-FUNDED alert)
        // triggers a review.
    }

    // ══════════════════════════════════════════════════════════════
    // P3-F7 candidate — withdrawal infinite-pending
    // ══════════════════════════════════════════════════════════════

    public function test_P3_F7_withdrawal_stays_pending_without_admin_action(): void
    {
        // **Documents finding P3-F7 (LOW).**
        //
        // Investor submits a withdrawal → status=pending, funds
        // moved to `wallet.reserved`. If admin never approves/rejects,
        // funds stay reserved indefinitely. No auto-expiration, no
        // stale-request alert, no investor-side cancellation.
        //
        // Acceptable at v1 scale (single-admin manual workflow,
        // SLA < 1 business day in practice). Phase 4 observability
        // could add an admin dashboard counter: "N withdrawals
        // pending > 3 days".
        $investor = $this->makeInvestorWithBalance('1000.00');

        $req = WithdrawalRequest::create([
            'user_id'    => $investor->id,
            'amount'     => '100.00',
            'iban'       => 'BG80BNBG96611020345678',
            'status'     => 'pending',
            'ip_address' => '127.0.0.1',
        ]);

        // Simulate time passing with no admin action.
        DB::table('withdrawal_requests')
            ->where('id', $req->id)
            ->update(['created_at' => now()->subDays(30)]);

        $this->assertSame('pending', $req->fresh()->status,
            'P3-F7: no auto-expiration on withdrawal requests');
        $this->assertTrue(
            $req->fresh()->created_at->lessThan(now()->subDays(29)),
            'stale request confirmed (30 days old, still pending)',
        );
    }

    // ══════════════════════════════════════════════════════════════
    // FK RESTRICT — orphan prevention
    // ══════════════════════════════════════════════════════════════

    public function test_cannot_delete_loan_with_investments_fk_restrict(): void
    {
        // Verifies investments.loan_id foreign key RESTRICTS deletion
        // of a loan that has investments. Protects against orphaned
        // Investment rows which would break the wallet reconstruction
        // audit + investor portfolio queries.
        $loan = $this->makeLoanInStatus(Loan::STATUS_FUNDING);
        $investor = $this->makeInvestorWithBalance('1000.00');
        Investment::create([
            'user_id'         => $investor->id,
            'loan_id'         => $loan->id,
            'amount'          => '100.00',
            'invested_at'     => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            DB::table('loans')->where('id', $loan->id)->delete();
            $this->fail('FK RESTRICT must prevent loan deletion with existing investments');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        }
    }

    public function test_cannot_delete_user_with_investments_fk_restrict(): void
    {
        // Similar FK guard on users. In practice Users are NEVER
        // deleted (AccountDeletionService anonymises instead), but
        // the DB constraint is defense-in-depth against raw DELETEs.
        $investor = $this->makeInvestorWithBalance('1000.00');
        $loan = $this->makeLoanInStatus(Loan::STATUS_FUNDING);
        Investment::create([
            'user_id'         => $investor->id,
            'loan_id'         => $loan->id,
            'amount'          => '100.00',
            'invested_at'     => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            DB::table('users')->where('id', $investor->id)->delete();
            $this->fail('FK RESTRICT must prevent user deletion with existing investments');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        }
    }

    public function test_account_anonymization_preserves_fk_integrity(): void
    {
        // AccountDeletionService.deleteAccount anonymises (NOT
        // deletes) the User row, preserving all FK relations. Verify
        // that after anonymisation, related Investment / Transaction
        // rows still resolve their user_id to a valid user row.
        $investor = $this->makeInvestorWithBalance('0.00');
        $loan = $this->makeLoanInStatus(Loan::STATUS_FUNDING);

        // No investments → user's invested bucket is 0, can be
        // anonymised. Create just a transaction (which also has FK).
        \App\Models\Transaction::create([
            'user_id'     => $investor->id,
            'type'        => 'deposit',
            'amount'      => '500.00',
            'description' => 'pre-anonymisation',
            'ip_address'  => '127.0.0.1',
        ]);

        // Simulate anonymisation manually (the service needs current
        // password + specific state; we forceFill directly to test
        // the FK preservation aspect).
        $investor->forceFill([
            'name'  => "Изтрит потребител #{$investor->id}",
            'email' => "deleted_{$investor->id}@removed.p2pinvest.bg",
        ])->save();

        // Transaction's FK still resolves.
        $txn = \App\Models\Transaction::where('user_id', $investor->id)->first();
        $this->assertNotNull($txn);
        $this->assertNotNull($txn->user);
        $this->assertStringStartsWith('Изтрит потребител', $txn->user->name);
    }

    // ══════════════════════════════════════════════════════════════
    // KYC lifecycle
    // ══════════════════════════════════════════════════════════════

    public function test_user_stuck_at_kyc_submitted_cannot_invest(): void
    {
        // Registration flow: user → kyc_status=pending → submits →
        // submitted → admin reviews → approved/rejected.
        //
        // If admin never reviews: user stays submitted. They can
        // browse the marketplace but cannot invest (invest middleware
        // requires kyc_status=approved). Not a bug — standard KYC
        // flow — but documents that there's no timeout / auto-approve.
        $user = User::factory()->create(['kyc_status' => 'submitted']);
        $user->email_verified_at = now();
        $user->save();

        $loan = $this->makeLoanInStatus(Loan::STATUS_FUNDING);

        $this->actingAs($user);
        $resp = $this->postJson(
            "/api/loans/{$loan->id}/invest",
            ['amount' => 100],
            ['X-Idempotency-Key' => 'kyc-submitted-' . uniqid()],
        );
        $resp->assertForbidden();  // `kyc` middleware rejects
    }

    // ══════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * @return array{0: Loan, 1: User}
     */
    private function makeActiveLoanWithSchedule(): array
    {
        $investor = $this->makeInvestorWithBalance('2000.00');
        $originator = Originator::create([
            'name' => 'Life-Audit-' . uniqid(),
            'description' => 'Phase 3 lifecycle audit',
            'buyback' => false,
        ]);
        $borrower = Borrower::factory()->create();
        $loan = Loan::create([
            'originator_id'        => $originator->id,
            'borrower_id'          => $borrower->id,
            'amount'               => '1000.00',
            'funded_amount'        => 0,
            'interest_rate'        => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months'          => 3,
            'type'                 => 'consumer',
            'status'               => 'draft',
        ]);
        $loan->transitionTo(Loan::STATUS_PUBLISHED);
        $loan->forceFill(['published_at' => now()])->save();
        app(\App\Services\InvestmentService::class)->invest(
            $investor, $loan->fresh(), '1000.00', (string) Str::uuid(),
        );
        $loan = $loan->fresh();
        if ($loan->status === Loan::STATUS_FUNDING) {
            $loan->transitionTo(Loan::STATUS_FUNDED);
        }
        $loan->transitionTo(Loan::STATUS_ACTIVE);
        return [$loan->fresh(), $investor];
    }

    private function makeLoanInStatus(string $status): Loan
    {
        $originator = Originator::create([
            'name' => 'Life-' . uniqid(),
            'description' => 'x',
            'buyback' => false,
        ]);
        $borrower = Borrower::factory()->create();
        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id'   => $borrower->id,
            'status'        => 'draft',
            'amount'        => '1000.00',
            'funded_amount' => '0.00',
            'interest_rate' => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months'   => 6,
        ]);
        // Raw SQL bypass the updating hook so we can stage any status
        // without running side effects (e.g. schedule generation on
        // funded→active). Acceptable for lifecycle-gap probing.
        DB::table('loans')->where('id', $loan->id)->update(['status' => $status]);
        return $loan->fresh();
    }

    private function makeInvestorWithBalance(string $balance): User
    {
        static $counter = 0;
        $counter++;
        $u = User::factory()->kycApproved()->create([
            'email' => "life-audit-{$counter}-" . uniqid() . "@test.local",
        ]);
        // Wallet::$fillable = ['user_id'] only — balance fields are
        // deliberately non-mass-assignable (security measure against
        // crafted API requests). Use forceFill to bypass for test setup.
        $wallet = Wallet::firstOrCreate(['user_id' => $u->id]);
        $wallet->forceFill([
            'available' => $balance, 'invested' => 0, 'earned' => 0, 'reserved' => 0,
        ])->save();
        return $u->fresh();
    }
}
