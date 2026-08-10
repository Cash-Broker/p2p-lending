<?php

namespace Tests\Feature;

use App\Filament\Resources\BorrowerResource\Pages\CreateBorrower;
use App\Models\Borrower;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Кредитен рейтинг» is the client's letter scale (2026-08-10): A — топ,
 * B — много добър, C — добър — picked from a dropdown, not typed. The
 * legacy credit_score column is a string since the same-day migration.
 */
class BorrowerCreditRatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    /**
     * Baseline valid create-form payload — profile fields included since
     * the investor-facing section became required (2026-08-10).
     *
     * @return array<string, mixed>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Тест Тестов',
            'address' => 'гр. София',
            'phone' => '0888123456',
            'income' => '2500',
            'credit_score' => 'B',
            'profile_risk_class' => 'B',
            'profile_region' => 'Кюстендил',
            'profile_loan_purpose' => 'Потребителски нужди',
            'profile_age_group' => '26-35',
        ], $overrides);
    }

    public function test_admin_creates_borrower_with_letter_rating_and_investor_profile(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm($this->validForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $borrower = Borrower::latest('id')->first();
        $this->assertSame('Тест Тестов', $borrower->full_name);
        $this->assertSame('B', $borrower->credit_score);

        // The investor-facing profile carries the REAL values — never the
        // «Неопределен» placeholders (boss complaint 2026-08-10).
        $profile = $borrower->anonymizedProfile;
        $this->assertSame('B', $profile->risk_class);
        $this->assertSame('Кюстендил', $profile->region);
        $this->assertSame('Потребителски нужди', $profile->loan_purpose);
        $this->assertSame('26-35', $profile->age_group);
    }

    public function test_investor_profile_region_and_purpose_are_required(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm($this->validForm(['profile_region' => null, 'profile_loan_purpose' => null]))
            ->call('create')
            ->assertHasFormErrors(['profile_region', 'profile_loan_purpose']);

        $this->assertSame(0, Borrower::count());
    }

    public function test_rating_select_rejects_values_outside_the_scale(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm($this->validForm(['credit_score' => 'X']))
            ->call('create')
            ->assertHasFormErrors(['credit_score']);
    }

    public function test_rating_is_optional(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm($this->validForm(['full_name' => 'Без Рейтинг', 'credit_score' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Borrower::latest('id')->first()->credit_score);
    }

    public function test_letter_ratings_persist_through_the_converted_column(): void
    {
        $borrower = Borrower::create([
            'full_name' => 'Директен Запис',
            'address' => 'адрес',
            'phone' => '000',
            'income' => '1000',
            'credit_score' => 'A',
        ]);

        $this->assertSame('A', $borrower->fresh()->credit_score);
    }
}
