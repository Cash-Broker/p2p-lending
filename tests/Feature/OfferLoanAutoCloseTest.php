<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\Loans\LoanStatusUpdaterService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auto-close (active → repaid) for OFFER-based loans.
 *
 * Before this fix an offer-based loan had NO path to `repaid`: the legacy
 * auto-close checked only amortization schedules (and skipped loans without
 * any), `repaid` is manual-blocklisted in the admin UI, and early repayment
 * rejects offer loans — so a fully paid-out offer loan stayed `active`
 * forever. Coverage mirrors Phase3AutoRepayTest for the offer world:
 *
 *   1. All investment-schedule rows paid → REPAID + LoanEvent + result bucket.
 *   2. A pending row blocks auto-close.
 *   3. A late row blocks auto-close (delinquency goes through late/buyback).
 *   4. LoanEvent metadata correctness (offer-specific transition_reason).
 *   5. Mixed offer + legacy investments → skipped for manual review.
 *   6. Active offer loan without generated schedules → skipped (data gap).
 *   7. Borrower-side late amortization rows block even with investors paid.
 *   8. Idempotent: a second run transitions nothing and writes no new event.
 *   9. Legacy loans are untouched by the offer branch (routing sanity).
 */
class OfferLoanAutoCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_1_offer_loan_with_all_rows_paid_transitions_to_repaid(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $this->markAllInvestmentRowsPaid($loan);

        $result = app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertContains($loan->id, $result['auto_repaid']);
    }

    public function test_2_pending_row_blocks_auto_close(): void
    {
        $loan = $this->makeActiveOfferLoan();
        // Every row but the last one paid.
        $lastRowId = $loan->investmentSchedules()->orderByDesc('due_date')->value('id');
        $loan->investmentSchedules()
            ->where('id', '!=', $lastRowId)
            ->update(['status' => 'paid', 'paid_at' => now()]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'one pending investment-schedule row must block auto-close');
    }

    public function test_3_late_row_blocks_auto_close(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $this->markAllInvestmentRowsPaid($loan);
        $loan->investmentSchedules()->orderByDesc('due_date')->limit(1)->update([
            'status' => 'late',
            'paid_at' => null,
            'became_late_at' => now()->subDays(5),
            'days_late' => 5,
        ]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'a late investment-schedule row must block auto-close');
    }

    public function test_4_loan_event_metadata_correct(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $rowCount = $loan->investmentSchedules()->count();
        $this->markAllInvestmentRowsPaid($loan);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_STATUS_CHANGED)
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'offer auto-close must write a loan_event');
        $this->assertSame(Loan::STATUS_ACTIVE, $event->from_status);
        $this->assertSame(Loan::STATUS_REPAID, $event->to_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_SYSTEM, $event->triggered_by);
        $this->assertNull($event->triggered_by_user_id);

        $meta = $event->metadata;
        $this->assertSame($rowCount, $meta['total_installments_paid']);
        $this->assertSame($loan->originator_id, $meta['originator_id']);
        $this->assertTrue($meta['auto_transitioned']);
        $this->assertSame('all_investment_schedules_paid', $meta['transition_reason']);
    }

    public function test_5_mixed_offer_and_legacy_investments_skipped(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $this->markAllInvestmentRowsPaid($loan);

        // A legacy (no-offer) investment on the same loan — its repayment
        // basis is the amortization schedule, which this pass can't verify.
        $legacyInvestor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $legacyInvestor->wallet()->create();
        Investment::factory()->create([
            'user_id' => $legacyInvestor->id,
            'loan_id' => $loan->id,
            'loan_offer_id' => null,
        ]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'mixed offer/legacy loans must be skipped for manual review');
    }

    public function test_6_offer_loan_without_schedules_skipped(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $loan->investmentSchedules()->delete();

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'an active offer loan with no generated schedules is a data gap — do not touch');
    }

    public function test_7_borrower_side_late_rows_block_auto_close(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $this->markAllInvestmentRowsPaid($loan);

        // Pre-generated borrower-side plan with a delinquent row: the late
        // path must resolve it before the loan can close cleanly.
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'due_date' => now()->subDays(20)->toDateString(),
            'principal' => '100.00',
            'interest' => '10.00',
            'total' => '110.00',
            'status' => 'late',
            'became_late_at' => now()->subDays(5),
            'days_late' => 5,
        ]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status,
            'borrower-side delinquency must block auto-close even with investors fully paid');
    }

    public function test_8_second_run_is_a_no_op(): void
    {
        $loan = $this->makeActiveOfferLoan();
        $this->markAllInvestmentRowsPaid($loan);

        $updater = app(LoanStatusUpdaterService::class);
        $first = $updater->autoRepayCompletedLoans();
        $second = $updater->autoRepayCompletedLoans();

        $this->assertContains($loan->id, $first['auto_repaid']);
        $this->assertSame([], $second['auto_repaid'], 'second run must transition nothing');
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertSame(1, LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_STATUS_CHANGED)
            ->count(), 'exactly one auto-close event must exist');
    }

    public function test_9_legacy_loans_still_use_the_amortization_basis(): void
    {
        // Routing sanity: a legacy loan (no offer investments) with a fully
        // paid amortization schedule still auto-closes exactly as before.
        $originator = Originator::create([
            'name' => 'AutoClose-Legacy-' . uniqid(),
            'description' => 'offer auto-close fixture',
            'buyback' => false,
        ]);
        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'status' => 'draft',
            'amount' => '1000.00',
            'funded_amount' => '1000.00',
            'interest_rate' => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months' => 6,
        ]);
        $loan->transitionTo(Loan::STATUS_PUBLISHED);
        \Illuminate\Support\Facades\DB::table('loans')->where('id', $loan->id)->update(['status' => 'funded']);
        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        $loan = $loan->fresh();
        $loan->amortizationSchedules()->update(['status' => 'paid', 'paid_at' => now()]);

        app(LoanStatusUpdaterService::class)->autoRepayCompletedLoans();

        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
    }

    // ──────────────────────────────────────────────────────────────

    /**
     * Build an active offer-based loan: published (offers auto-seeded), one
     * investor fully funds it under the given offer, then admin activation
     * generates the per-investment schedules.
     */
    private function makeActiveOfferLoan(PayoutType $type = PayoutType::Amortizing): Loan
    {
        $originator = Originator::create([
            'name' => 'AutoClose-' . uniqid(),
            'description' => 'offer auto-close fixture',
            'buyback' => false,
        ]);

        $loan = Loan::factory()->published()->create([
            'originator_id' => $originator->id,
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');

        $offerId = $loan->offers()->where('payout_type', $type)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);

        return $loan->fresh();
    }

    /**
     * Stamp every investment-schedule row paid via a raw update — we test
     * the transition, not the payout distribution (wallet side effects are
     * PayoutAccrualService's tested concern).
     */
    private function markAllInvestmentRowsPaid(Loan $loan): void
    {
        $loan->investmentSchedules()->update(['status' => 'paid', 'paid_at' => now()]);
    }
}
