<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\RelationManagers\InvestmentsRelationManager;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Инвестиции» tab on the admin user profile (boss 2026-08-10): ONLY this
 * user's investments — never a mix with other people's.
 */
class UserInvestmentsRelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    private function investorWithBalance(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        return $user;
    }

    public function test_profile_lists_only_this_users_investments(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $owner = $this->investorWithBalance();
        $other = $this->investorWithBalance();

        $service = app(InvestmentService::class);
        $own = $service->invest($owner, $loan, '500.00', 'urm-'.uniqid(),
            $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id'));
        $foreign = $service->invest($other, $loan, '700.00', 'urm-'.uniqid(),
            $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id'));

        Livewire::test(InvestmentsRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass' => ViewUser::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertTableActionVisible('contract', $own);
    }
}
