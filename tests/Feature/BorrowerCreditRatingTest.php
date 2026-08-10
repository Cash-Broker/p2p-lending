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

    public function test_admin_creates_borrower_with_letter_rating(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm([
                'full_name' => 'Тест Тестов',
                'address' => 'гр. София',
                'phone' => '0888123456',
                'income' => '2500',
                'credit_score' => 'B',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $borrower = Borrower::latest('id')->first();
        $this->assertSame('Тест Тестов', $borrower->full_name);
        $this->assertSame('B', $borrower->credit_score);
    }

    public function test_rating_select_rejects_values_outside_the_scale(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm([
                'full_name' => 'Тест Тестов',
                'address' => 'гр. София',
                'phone' => '0888123456',
                'income' => '2500',
                'credit_score' => 'X',
            ])
            ->call('create')
            ->assertHasFormErrors(['credit_score']);
    }

    public function test_rating_is_optional(): void
    {
        Livewire::test(CreateBorrower::class)
            ->fillForm([
                'full_name' => 'Без Рейтинг',
                'address' => 'гр. Пловдив',
                'phone' => '0888999999',
                'income' => '1800',
                'credit_score' => null,
            ])
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
