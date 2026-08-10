<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\RelationManagers\OffersRelationManager;
use App\Models\Loan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders the offers RelationManager through Livewire so its form (the
 * Placeholder content closure) + the status-gated EditAction are actually
 * exercised — lint can't catch a runtime Filament-form error.
 */
class OffersRelationManagerTest extends TestCase
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
        return Livewire::test(OffersRelationManager::class, [
            'ownerRecord' => $loan,
            'pageClass' => EditLoan::class,
        ]);
    }

    public function test_offer_rate_edit_persists_on_published_loan(): void
    {
        $loan = Loan::factory()->published()->create();
        $offer = $loan->offers()->where('payout_type', PayoutType::Amortizing)->first();

        $this->relationManager($loan)
            ->callTableAction('edit', $offer, data: ['interest_rate' => '14.50', 'is_enabled' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame('14.50', (string) $offer->fresh()->interest_rate);
    }

    public function test_edit_action_stays_visible_on_active_loans(): void
    {
        // Client decision 2026-08-10: offers are editable in EVERY status —
        // committed investors keep their snapshotted terms regardless.
        $loan = Loan::factory()->active()->create();
        $offer = $loan->offers()->first();

        $this->relationManager($loan)
            ->assertTableActionVisible('edit', $offer)
            ->callTableAction('edit', $offer, data: ['interest_rate' => '17.25', 'is_enabled' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame('17.25', (string) $offer->fresh()->interest_rate);
    }
}
