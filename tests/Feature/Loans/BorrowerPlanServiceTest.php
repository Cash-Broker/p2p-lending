<?php

namespace Tests\Feature\Loans;

use App\Enums\PayoutType;
use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BonusService;
use App\Services\InvestmentService;
use App\Services\Loans\BorrowerPlanService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PAY-13 (owner 2026-09-03): the admin-attested borrower tracking plan of an
 * offer loan — pure dates, informational amounts, zero money.
 */
class BorrowerPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function investor(string $credit = '1100.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, $credit, Transaction::TYPE_DEPOSIT, 'seed');

        return $user;
    }

    /** Invests to 100 % → auto-activation → tracker generated after commit. @return array{0: Loan, 1: User} */
    private function activeOfferLoan(string $stake = '1000.00', int $term = 12): array
    {
        Notification::fake();
        PlatformSetting::set('borrower_tracker_auto_generate', true); // the tests exercise the tracker; prod ships OFF
        $loan = Loan::factory()->published()->create([
            'amount' => $stake, 'investable_amount' => $stake, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'interest_rate_annual' => '15.00', 'term_months' => $term,
        ]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        $user = $this->investor(bcadd($stake, '100.00', 2));
        app(InvestmentService::class)->invest($user, $loan->fresh(), $stake, (string) Str::uuid(), $offerId);

        return [$loan->fresh(), $user];
    }

    private function service(): BorrowerPlanService
    {
        return app(BorrowerPlanService::class);
    }

    private function walletSnapshot(int $userId): array
    {
        $w = Wallet::where('user_id', $userId)->firstOrFail();

        return [(string) $w->available, (string) $w->reserved, (string) $w->invested, (string) $w->accrued, (string) $w->earned];
    }

    public function test_the_linear_plan_sums_principal_to_the_base_and_never_goes_negative(): void
    {
        // 50 € over 360 months — the pair that breaks the annuity (PAY-40) is harmless here.
        $tiny = Loan::factory()->active()->create(['amount' => '50.00', 'investable_amount' => '50.00', 'funded_amount' => '50.00', 'interest_rate_annual' => '20.00', 'term_months' => 360]);
        $rows = $this->service()->buildRows($tiny, Carbon::parse('2026-10-01'), null);
        $this->assertCount(360, $rows);
        $sum = array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r['principal'], 2), '0.00');
        $this->assertSame('50.00', $sum);
        foreach ($rows as $r) {
            $this->assertGreaterThanOrEqual(0, bccomp($r['principal'], '0', 2));
            $this->assertSame(bcadd($r['principal'], $r['interest'], 2), $r['total']);
            $this->assertFalse($r['paid']);
        }
        $this->assertSame('2026-10-01', $rows[0]['due_date']);
        $this->assertSame('2026-11-01', $rows[1]['due_date']);
        $this->assertSame('2056-09-01', $rows[359]['due_date']);

        // A real loan: 13 000 € / 84 months, generated into the table.
        [$loan] = $this->activeOfferLoan('13000.00', 84);
        $loan->amortizationSchedules()->borrowerTracker()->delete();
        $created = $this->service()->generate($loan, Carbon::parse('2026-10-15'), null, null);
        $this->assertSame(84, $created);
        $tracker = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->get();
        $this->assertCount(84, $tracker);
        $this->assertSame('13000.00', $tracker->reduce(fn (string $c, $r) => bcadd($c, (string) $r->principal, 2), '0.00'));
        $this->assertTrue($tracker->every(fn ($r) => $r->status === 'pending' && $r->isBorrowerTracker()));
        $this->assertSame('2026-10-15', $tracker->first()->due_date->toDateString());
        $this->assertSame(0, $loan->amortizationSchedules()->legacyPlan()->count());
    }

    public function test_generate_refuses_legacy_loans_fundable_loans_and_existing_trackers(): void
    {
        // Legacy loan (no offer investment).
        $legacy = Loan::factory()->active()->create(['term_months' => 12]);
        try {
            $this->service()->generate($legacy, now(), null, null);
            $this->fail('legacy loans have no tracker');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Легаси', $e->getMessage());
        }

        // Funding-stage offer loan (partially funded) — open owner question, refused.
        Notification::fake();
        $funding = Loan::factory()->published()->create(['amount' => '2000.00', 'investable_amount' => '2000.00', 'funded_amount' => 0, 'interest_rate' => '12.00', 'term_months' => 12]);
        $offerId = $funding->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($this->investor(), $funding->fresh(), '1000.00', (string) Str::uuid(), $offerId);
        $this->assertSame(Loan::STATUS_FUNDING, $funding->fresh()->status);
        $capBefore = $funding->fresh()->fundingCap();
        try {
            $this->service()->generate($funding->fresh(), now(), null, null);
            $this->fail('fundable loans are refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('активен или закъснял', $e->getMessage());
        }
        $this->assertSame(0, $funding->amortizationSchedules()->count());
        $this->assertSame($capBefore, $funding->fresh()->fundingCap());

        // Existing tracker is never overwritten.
        [$loan] = $this->activeOfferLoan();
        $before = $loan->amortizationSchedules()->count();
        $this->assertSame(12, $before, 'tracker generated at activation');
        try {
            $this->service()->generate($loan, now(), null, null);
            $this->fail('existing tracker must not be replaced');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('вече има план', $e->getMessage());
        }
        $this->assertSame($before, $loan->amortizationSchedules()->count());
    }

    public function test_paid_through_marks_elapsed_rows_paid_without_any_transaction(): void
    {
        [$loan, $user] = $this->activeOfferLoan();
        $loan->amortizationSchedules()->borrowerTracker()->delete();
        $admin = User::factory()->create(['role' => 'admin']);
        $txBefore = Transaction::count();
        $walletBefore = $this->walletSnapshot($user->id);

        $this->service()->generate($loan, now()->subMonthsNoOverflow(4), now()->subMonthsNoOverflow(2), $admin->id);

        $tracker = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->get();
        $paid = $tracker->where('status', 'paid');
        $this->assertCount(3, $paid, 'rows due 4, 3 and 2 months ago are attested');
        foreach ($paid as $row) {
            $this->assertSame($row->due_date->toDateString(), $row->borrower_paid_on->toDateString());
            $this->assertEquals($admin->id, $row->recorded_by);
            $this->assertNotNull($row->paid_at);
        }
        $this->assertCount(9, $tracker->where('status', 'pending'));
        $this->assertSame($txBefore, Transaction::count());
        $this->assertSame($walletBefore, $this->walletSnapshot($user->id));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_record_borrower_payment_flips_the_row_and_moves_no_money(): void
    {
        [$loan, $user] = $this->activeOfferLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $row = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail();
        $txBefore = Transaction::count();
        $walletBefore = $this->walletSnapshot($user->id);
        Carbon::setTestNow($row->due_date->copy()->addDays(3)->setTime(10, 0));

        $updated = $this->service()->recordBorrowerPayment($loan->id, $row->id, $row->due_date->copy()->addDay(), $admin->id);

        $this->assertSame('paid', $updated->status);
        $this->assertSame($row->due_date->copy()->addDay()->toDateString(), $updated->borrower_paid_on->toDateString());
        $this->assertEquals($admin->id, $updated->recorded_by);
        $this->assertTrue($updated->paid_at->equalTo(now()));
        $this->assertSame($txBefore, Transaction::count());
        $this->assertSame($walletBefore, $this->walletSnapshot($user->id));
        $this->assertTrue(AuditLog::where('model_type', AmortizationSchedule::class)->where('model_id', $row->id)->where('action', 'updated')->exists());

        // Double click → the second call finds a paid row and refuses.
        try {
            $this->service()->recordBorrowerPayment($loan->id, $row->id, now(), $admin->id);
            $this->fail('a paid row cannot be attested twice');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('вече е отбелязана', $e->getMessage());
        }
    }

    public function test_record_borrower_payment_rejects_future_date_foreign_row_and_legacy_loan(): void
    {
        [$loan] = $this->activeOfferLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $row = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail();

        try {
            $this->service()->recordBorrowerPayment($loan->id, $row->id, now()->addDays(2), $admin->id);
            $this->fail('future dates are refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('бъдещето', $e->getMessage());
        }

        // A row of ANOTHER loan is not found under this loan id.
        $other = Loan::factory()->active()->create();
        $foreign = $other->amortizationSchedules()->create(['due_date' => now(), 'principal' => '10.00', 'interest' => '1.00', 'total' => '11.00', 'status' => 'pending']);
        try {
            $this->service()->recordBorrowerPayment($loan->id, $foreign->id, now(), $admin->id);
            $this->fail('foreign rows are refused');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // Legacy loans keep going through «Погашения».
        try {
            $this->service()->recordBorrowerPayment($other->id, $foreign->id, now(), $admin->id);
            $this->fail('legacy loans are refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Погашения', $e->getMessage());
        }

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_mark_paid_through_counts_only_unpaid_rows_due_by_the_date(): void
    {
        [$loan] = $this->activeOfferLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $loan->amortizationSchedules()->borrowerTracker()->delete();
        $this->service()->generate($loan, now()->subMonthsNoOverflow(5), null, null);
        $rows = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->get();
        $this->service()->recordBorrowerPayment($loan->id, $rows[0]->id, $rows[0]->due_date, $admin->id);

        $count = $this->service()->markPaidThrough($loan->id, $rows[2]->due_date, $admin->id);

        $this->assertSame(2, $count, 'rows 2 and 3 — row 1 was already paid');
        $this->assertSame(3, $loan->amortizationSchedules()->borrowerTracker()->where('status', 'paid')->count());
        $this->assertSame(9, $loan->amortizationSchedules()->borrowerTracker()->where('status', 'pending')->count());
    }

    public function test_overdue_preview_counts_unpaid_rows_past_the_grace_period(): void
    {
        [$loan] = $this->activeOfferLoan();
        $service = $this->service();

        // First due 4 months + 11 days ago, nothing paid: rows 1–5 are past
        // due + the 10-day grace (row 5 fell due 11 days ago); row 6 is next month.
        $this->assertSame(5, $service->overduePreview($loan, now()->subMonthsNoOverflow(4)->subDays(11), null));
        // «платени до» two months back covers rows 1–3 → rows 4 and 5 remain overdue and unrecorded.
        $this->assertSame(2, $service->overduePreview($loan, now()->subMonthsNoOverflow(4)->subDays(11), now()->subMonthsNoOverflow(2)));
        $this->assertSame(0, $service->overduePreview($loan, now()->subMonthsNoOverflow(4)->subDays(11), now()));
        $this->assertSame(0, $service->overduePreview($loan, now()->addMonth(), null));
    }

    public function test_tracker_rows_recorded_paid_never_qualify_a_conditional_bonus(): void
    {
        // Reni 2026-08-18: a bonus unlocks only on RECEIVED investor payouts. The
        // BonusService fallback counts amortization rows for legacy positions — a
        // borrower tracker row the admin recorded `paid` must not slip in there.
        [$loan, $user] = $this->activeOfferLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $since = now()->subMinute();

        // Force the legacy fallback: no investor rows at all.
        InvestmentSchedule::where('loan_id', $loan->id)->delete();
        foreach ($loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->limit(3)->get() as $row) {
            $this->service()->recordBorrowerPayment($loan->id, $row->id, today(), $admin->id);
        }
        $this->assertSame(3, $loan->amortizationSchedules()->where('status', 'paid')->count());

        $this->assertSame('0.00', app(BonusService::class)->qualifiedInvestedAmount($user->id, $since, 3));
    }
}
