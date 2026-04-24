<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F5 — Filament LoanResource APR column + Ставки (rates) section
 * render-smoke tests.
 *
 * PHPUnit can't reach into the rendered HTML cheaply, but Livewire's
 * `assertSuccessful()` catches any closure-level exception thrown while
 * the table / infolist is drawn. If a future refactor breaks apr()
 * in a way that propagates to the view layer, these tests fire.
 */
class LoanResourceAPRTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeLoan(array $overrides = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'originator_id' => Originator::factory()->create()->id,
            'borrower_id' => Borrower::factory()->create()->id,
        ], $overrides));
    }

    public function test_list_page_renders_with_apr_column(): void
    {
        $this->makeLoan(['interest_rate' => '8.00', 'interest_rate_annual' => '10.50']);
        $this->actingAs($this->makeAdmin());

        Livewire::test(ListLoans::class)->assertSuccessful();
    }

    public function test_list_page_renders_with_zero_apr_loan(): void
    {
        // Loan with interest_rate_annual = 0 must not crash the column
        // — it should display "—" via the formatStateUsing fallback.
        $loan = $this->makeLoan();
        DB::table('loans')->where('id', $loan->id)->update(['interest_rate_annual' => 0]);
        $this->actingAs($this->makeAdmin());

        Livewire::test(ListLoans::class)->assertSuccessful();
    }

    public function test_edit_page_renders_ставки_section_with_marge(): void
    {
        // Edit page renders the "Ставки" section (Доходност + ГПР + Марж
        // Placeholders). Each closure must fire without error for a loan
        // in any state. Marge = 10.50 - 8.00 = 2.50.
        $loan = $this->makeLoan([
            'interest_rate' => '8.00',
            'interest_rate_annual' => '10.50',
        ]);
        $this->actingAs($this->makeAdmin());

        Livewire::test(EditLoan::class, ['record' => $loan->id])
            ->assertSuccessful();
    }
}
