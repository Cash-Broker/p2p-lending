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
                // Investor-facing profile — required since 2026-08-10 so the
                // site never shows «Неопределен» placeholders.
                'profile_risk_class' => 'B',
                'profile_region' => 'Кюстендил',
                'profile_loan_purpose' => 'Потребителски нужди',
                'profile_age_group' => '26-35',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame(1, Borrower::count(), 'A borrower should be created inline from the loan form');

        $borrower = Borrower::first();
        $this->assertEquals('Нов Длъжник', $borrower->full_name);
        $this->assertNull($borrower->personal_id, 'No ЕГН is collected inline');

        // The anonymized profile carries the REAL values the admin entered.
        $profile = $borrower->anonymizedProfile;
        $this->assertNotNull($profile, 'Inline borrower must get an anonymized profile');
        $this->assertSame('B', $profile->risk_class);
        $this->assertSame('Кюстендил', $profile->region);
        $this->assertSame('Потребителски нужди', $profile->loan_purpose);
    }

    public function test_inline_creation_requires_the_investor_profile_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(CreateLoan::class)
            ->callFormComponentAction('borrower_id', 'createOption', data: [
                'full_name' => 'Без Профил',
                'phone' => '+359888000000',
                'address' => 'ул. Тест 1',
                'income' => '2000',
                'profile_region' => '',
                'profile_loan_purpose' => '',
            ])
            ->assertHasFormComponentActionErrors(['profile_region', 'profile_loan_purpose']);

        $this->assertSame(0, Borrower::count());
    }
}
