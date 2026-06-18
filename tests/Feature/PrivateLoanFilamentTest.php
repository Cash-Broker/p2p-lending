<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\Loan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders the loan admin after adding the Видимост select + „Линк за
 * инвеститор" action, so a runtime Filament-form/table error surfaces.
 */
class PrivateLoanFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_edit_page_renders_with_visibility_field(): void
    {
        $loan = Loan::factory()->create(['visibility' => Loan::VISIBILITY_PRIVATE]);

        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet(['visibility' => Loan::VISIBILITY_PRIVATE]);
    }

    public function test_share_link_action_generates_token(): void
    {
        $loan = Loan::factory()->published()->create(['visibility' => Loan::VISIBILITY_PRIVATE]);
        $this->assertNull($loan->share_token);

        Livewire::test(ListLoans::class)
            ->mountTableAction('share_link', $loan)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($loan->fresh()->share_token);
    }
}
