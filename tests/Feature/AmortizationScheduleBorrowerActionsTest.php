<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\RelationManagers\AmortizationSchedulesRelationManager;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\Loans\BorrowerPlanService;
use App\Services\WalletService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** PAY-13: the admin side of the borrower tracker — the «Погасителен план» tab of an offer loan. */
class AmortizationScheduleBorrowerActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function activeOfferLoan(): Loan
    {
        Notification::fake();
        PlatformSetting::set('borrower_tracker_auto_generate', true); // the tests exercise the tracker; prod ships OFF
        $loan = Loan::factory()->published()->create(['amount' => '1000.00', 'investable_amount' => '1000.00', 'funded_amount' => 0, 'interest_rate' => '12.00', 'term_months' => 12]);
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '1100.00', Transaction::TYPE_DEPOSIT, 'seed');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        return $loan->fresh();
    }

    private function manager(Loan $loan)
    {
        return Livewire::test(AmortizationSchedulesRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => EditLoan::class]);
    }

    public function test_create_tracker_is_offered_only_to_live_offer_loans_without_one_and_refuses_overdue_rows_without_acknowledgement(): void
    {
        $legacy = Loan::factory()->active()->create();
        $this->manager($legacy)->assertActionHidden(TestAction::make('create_tracker')->table());

        $loan = $this->activeOfferLoan();
        $this->manager($loan)->assertActionHidden(TestAction::make('create_tracker')->table()); // the activation already created one
        $this->manager($loan)->assertActionVisible(TestAction::make('mark_paid_through')->table());

        $loan->amortizationSchedules()->borrowerTracker()->delete();
        $component = $this->manager($loan);
        $component->assertActionVisible(TestAction::make('create_tracker')->table());

        // 4 months back, nothing recorded, no acknowledgement → refused, nothing written.
        $component->callAction(TestAction::make('create_tracker')->table(), data: [
            'first_due_date' => now()->subMonthsNoOverflow(4)->toDateString(),
            'paid_through' => null,
            'confirm_overdue' => false,
        ])->assertHasActionErrors(['confirm_overdue']);
        $this->assertSame(0, $loan->amortizationSchedules()->count());

        // Acknowledged → created; the overdue rows are unpaid and WILL make the loan late.
        $this->manager($loan)->callAction(TestAction::make('create_tracker')->table(), data: [
            'first_due_date' => now()->subMonthsNoOverflow(4)->toDateString(),
            'paid_through' => null,
            'confirm_overdue' => true,
        ])->assertHasNoActionErrors()->assertNotified('Планът на кредитополучателя е създаден (12 вноски)');
        $this->assertSame(12, $loan->amortizationSchedules()->borrowerTracker()->count());
        $this->assertSame(0, $loan->amortizationSchedules()->borrowerTracker()->where('status', 'paid')->count());

        // «платени до» covering the elapsed rows needs no acknowledgement.
        $other = $this->activeOfferLoan();
        $other->amortizationSchedules()->borrowerTracker()->delete();
        $this->manager($other)->callAction(TestAction::make('create_tracker')->table(), data: [
            'first_due_date' => now()->subMonthsNoOverflow(4)->toDateString(),
            'paid_through' => now()->toDateString(),
        ])->assertHasNoActionErrors();
        $this->assertSame(5, $other->amortizationSchedules()->borrowerTracker()->where('status', 'paid')->count());
        $this->artisan('loans:process-late')->assertSuccessful();
        $this->assertSame(Loan::STATUS_ACTIVE, $other->fresh()->status);
    }

    public function test_borrower_paid_row_action_records_the_fact_and_moves_no_money(): void
    {
        $loan = $this->activeOfferLoan();
        $row = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail();
        $txBefore = Transaction::count();

        $this->manager($loan)
            ->callTableAction('borrower_paid', $row, data: ['borrower_paid_on' => today()->toDateString()])
            ->assertNotified('Вноската е отбелязана като платена от кредитополучателя');

        $fresh = $row->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(today()->toDateString(), $fresh->borrower_paid_on->toDateString());
        $this->assertEquals(auth()->id(), $fresh->recorded_by);
        $this->assertSame($txBefore, Transaction::count());

        // Paid rows no longer offer the action.
        $this->manager($loan)->assertTableActionHidden('borrower_paid', $fresh);
    }

    public function test_mark_paid_through_header_action_counts_the_rows(): void
    {
        $loan = $this->activeOfferLoan();
        $loan->amortizationSchedules()->borrowerTracker()->delete();
        app(BorrowerPlanService::class)->generate($loan, now()->subMonthsNoOverflow(5), null, null);
        $rows = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->get();

        $this->manager($loan)
            ->callAction(TestAction::make('mark_paid_through')->table(), data: ['through' => $rows[2]->due_date->toDateString()])
            ->assertNotified('3 вноски отбелязани като платени');

        $this->assertSame(3, $loan->amortizationSchedules()->borrowerTracker()->where('status', 'paid')->count());
    }

    public function test_tracker_rows_can_only_have_their_date_edited_and_legacy_actions_stay_hidden(): void
    {
        $loan = $this->activeOfferLoan();
        $row = $loan->amortizationSchedules()->borrowerTracker()->orderBy('due_date')->firstOrFail();
        $component = $this->manager($loan);

        $component->assertTableActionVisible('edit', $row);
        $component->assertTableActionHidden('delete', $row);
        $component->assertActionHidden(TestAction::make('generate_schedule')->table());

        $newDate = $row->due_date->copy()->addDays(5)->toDateString();
        $component->callTableAction('edit', $row, data: ['due_date' => $newDate, 'principal' => '999.99'])
            ->assertHasNoTableActionErrors();

        $fresh = $row->fresh();
        $this->assertSame($newDate, $fresh->due_date->toDateString());
        $this->assertSame((string) $row->principal, (string) $fresh->principal, 'amounts are frozen on tracker rows');
    }
}
