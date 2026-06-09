<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\CreateLoan;
use App\Models\Borrower;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanInlineBorrowerTest extends TestCase
{
    use RefreshDatabase;

    public function test_borrower_can_be_created_inline_from_the_loan_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->assertSame(0, Borrower::count());

        Livewire::test(CreateLoan::class)
            ->callFormComponentAction('borrower_id', 'createOption', data: [
                'full_name' => 'Нов Длъжник',
                'phone' => '+359888000000',
                'address' => 'ул. Тест 1',
                'income' => '2000',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame(1, Borrower::count(), 'A borrower should be created inline from the loan form');

        $borrower = Borrower::first();
        $this->assertEquals('Нов Длъжник', $borrower->full_name);
        $this->assertNotNull($borrower->anonymizedProfile, 'Inline borrower must get an anonymized profile');
        $this->assertNull($borrower->personal_id, 'No ЕГН is collected inline');
    }
}
