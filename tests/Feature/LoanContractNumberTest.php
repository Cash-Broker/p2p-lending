<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\CreateLoan;
use App\Models\Loan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hand-entered loan contract number (boss 2026-08-10): the admin types
 * the REAL paperwork number at creation; unique when present; nothing is
 * auto-generated.
 */
class LoanContractNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    public function test_contract_number_field_exists_on_the_create_form(): void
    {
        Livewire::test(CreateLoan::class)
            ->assertFormFieldExists('contract_number');
    }

    public function test_contract_number_persists_and_stays_editable(): void
    {
        $loan = Loan::factory()->create(['contract_number' => '1042/2026']);
        $this->assertSame('1042/2026', $loan->fresh()->contract_number);

        // Editable like everything else on the loan (2026-08-10 decision).
        $loan->update(['contract_number' => '1043/2026']);
        $this->assertSame('1043/2026', $loan->fresh()->contract_number);
    }

    public function test_contract_number_is_unique_when_present(): void
    {
        Loan::factory()->create(['contract_number' => 'DUP/2026']);

        $this->expectException(QueryException::class);
        Loan::factory()->create(['contract_number' => 'DUP/2026']);
    }

    public function test_multiple_loans_without_contract_number_are_fine(): void
    {
        Loan::factory()->create(['contract_number' => null]);
        Loan::factory()->create(['contract_number' => null]);

        $this->assertSame(2, Loan::whereNull('contract_number')->count());
    }
}
