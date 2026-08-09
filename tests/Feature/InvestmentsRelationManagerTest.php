<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\RelationManagers\InvestmentsRelationManager;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders the investments RelationManager through Livewire so the new
 * contract columns (payout label, «Съгласие с договора») and the
 * «Договор» URL action are actually exercised at runtime — lint can't
 * catch a Filament v5 API misuse.
 */
class InvestmentsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function relationManager(Loan $loan): Testable
    {
        return Livewire::test(InvestmentsRelationManager::class, [
            'ownerRecord' => $loan,
            'pageClass' => EditLoan::class,
        ]);
    }

    private function investorWithBalance(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        return $user;
    }

    public function test_renders_investments_with_contract_evidence_column(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investorWithBalance();
        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');

        $investment = app(InvestmentService::class)
            ->invest($user, $loan, '200.00', 'rm-test-'.uniqid(), $offerId);

        $this->relationManager($loan->fresh())
            ->assertOk()
            ->assertCanSeeTableRecords([$investment])
            ->assertTableActionVisible('contract', $investment);
    }

    public function test_contract_action_hidden_for_legacy_investment_without_contract(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investorWithBalance();

        // Legacy path (no offer) — creates no contract.
        $investment = app(InvestmentService::class)
            ->invest($user, $loan, '200.00', 'rm-legacy-'.uniqid());

        $this->assertNull($investment->contract);

        $this->relationManager($loan->fresh())
            ->assertOk()
            ->assertCanSeeTableRecords([$investment])
            ->assertTableActionHidden('contract', $investment);
    }
}
