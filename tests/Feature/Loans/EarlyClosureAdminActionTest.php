<?php

namespace Tests\Feature\Loans;

use App\Enums\PayoutType;
use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEarlyClosure;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\EarlyRepaymentReceivedNotification;
use App\Notifications\LoanPartiallyClosedNotification;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two closure buttons on the loan page itself (Йордан 2026-08-18: «като
 * влиза в кредита там да ги има», next to the existing buttons).
 */
class EarlyClosureAdminActionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Loan, 1: User} */
    private function offerLoan(PayoutType $type = PayoutType::InterestOnly): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => '1000.00', 'investable_amount' => '1000.00', 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $offerId = $loan->offers()->where('payout_type', $type)->value('id');

        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();
        app(WalletService::class)->credit($investor->id, '1000.00', Transaction::TYPE_DEPOSIT, 'seed');
        app(InvestmentService::class)->invest($investor, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $investor];
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    public function test_both_buttons_are_on_the_loan_page(): void
    {
        $this->actingAsAdmin();
        [$loan] = $this->offerLoan();

        Livewire::test(EditLoan::class, ['record' => $loan->id])
            ->assertOk()
            ->assertActionVisible('early_closure')
            ->assertActionVisible('partial_closure');
    }

    public function test_full_closure_from_the_loan_page_repays_the_loan(): void
    {
        $this->actingAsAdmin();
        [$loan, $investor] = $this->offerLoan();

        Livewire::test(EditLoan::class, ['record' => $loan->id])
            ->callAction('early_closure', data: ['as_of' => now()->toDateString()])
            ->assertHasNoActionErrors();

        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertSame('1000.00', (string) Transaction::where('user_id', $investor->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL)->sum('amount'));

        Notification::assertSentTo($investor, EarlyRepaymentReceivedNotification::class);
    }

    public function test_partial_closure_from_the_loan_page_shrinks_the_position(): void
    {
        $this->actingAsAdmin();
        [$loan, $investor] = $this->offerLoan();

        Livewire::test(EditLoan::class, ['record' => $loan->id])
            ->callAction('partial_closure', data: ['amount' => '250.00', 'as_of' => now()->toDateString()])
            ->assertHasNoActionErrors();

        // The loan keeps running with three quarters of the position.
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        $this->assertSame('750.00', InvestmentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'late'])
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00'));

        $this->assertSame(1, LoanEarlyClosure::where('loan_id', $loan->id)->where('is_full', false)->count());
        Notification::assertSentTo($investor, LoanPartiallyClosedNotification::class);
    }

    public function test_closing_more_than_outstanding_is_refused_with_a_toast(): void
    {
        $this->actingAsAdmin();
        [$loan] = $this->offerLoan();

        Livewire::test(EditLoan::class, ['record' => $loan->id])
            ->callAction('partial_closure', data: ['amount' => '5000.00', 'as_of' => now()->toDateString()])
            ->assertNotified();

        // Nothing moved.
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        $this->assertSame(0, LoanEarlyClosure::count());
    }

    public function test_buttons_are_hidden_on_legacy_loans(): void
    {
        $this->actingAsAdmin();
        $legacy = Loan::factory()->active()->create();

        Livewire::test(EditLoan::class, ['record' => $legacy->id])
            ->assertActionHidden('early_closure')
            ->assertActionHidden('partial_closure');
    }
}
