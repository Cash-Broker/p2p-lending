<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\InvestmentResource;
use App\Filament\Resources\InvestmentResource\Pages\ListInvestments;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The global admin «Инвестиции» register (boss 2026-08-10): every
 * investment across all loans in one list — who, how much, which loan,
 * which plan — with filters and a live Сума total.
 */
class InvestmentAdminListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    private function investorWithBalance(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    public function test_lists_all_investments_across_loans_with_live_total(): void
    {
        $loanA = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $loanB = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $investor = $this->investorWithBalance();

        $service = app(InvestmentService::class);
        $first = $service->invest($investor, $loanA, '1200.00', 'reg-'.uniqid(),
            $loanA->offers()->where('payout_type', PayoutType::InterestOnly)->value('id'));
        $second = $service->invest($investor, $loanB, '2000.00', 'reg-'.uniqid(),
            $loanB->offers()->where('payout_type', PayoutType::Capitalized)->value('id'));

        Livewire::test(ListInvestments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertTableActionVisible('contract', $first);
    }

    public function test_filters_by_plan(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $investor = $this->investorWithBalance();

        $service = app(InvestmentService::class);
        $interestOnly = $service->invest($investor, $loan, '100.00', 'flt-'.uniqid(),
            $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id'));
        $capitalized = $service->invest($investor, $loan, '200.00', 'flt-'.uniqid(),
            $loan->offers()->where('payout_type', PayoutType::Capitalized)->value('id'));

        Livewire::test(ListInvestments::class)
            ->filterTable('payout_type', PayoutType::InterestOnly->value)
            ->assertCanSeeTableRecords([$interestOnly])
            ->assertCanNotSeeTableRecords([$capitalized]);
    }

    public function test_legacy_investment_without_contract_hides_the_contract_action(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $investor = $this->investorWithBalance();

        $legacy = app(InvestmentService::class)->invest($investor, $loan, '100.00', 'leg-'.uniqid());

        Livewire::test(ListInvestments::class)
            ->assertCanSeeTableRecords([$legacy])
            ->assertTableActionHidden('contract', $legacy);
    }

    public function test_register_is_read_only(): void
    {
        $this->assertFalse(InvestmentResource::canCreate());
    }
}
