<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_loan_can_transition_to_draft_at_model_level(): void
    {
        $loan = Loan::factory()->published()->create();

        $loan->update(['status' => Loan::STATUS_DRAFT]);

        $this->assertEquals(Loan::STATUS_DRAFT, $loan->fresh()->status);
    }

    public function test_admin_can_revert_published_loan_to_draft_via_action(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $loan = Loan::factory()->published()->create();

        Livewire::test(ListLoans::class)
            ->callTableAction('unpublish', $loan)
            ->assertHasNoErrors();

        $fresh = $loan->fresh();
        $this->assertEquals(Loan::STATUS_DRAFT, $fresh->status, 'Published loan should be revertible to draft');
        $this->assertNull($fresh->published_at, 'published_at cleared so the loan leaves investor view');
    }

    public function test_funding_loan_with_investments_cannot_be_reverted(): void
    {
        // Safety: a loan that already has investor money cannot be hidden out
        // from under them — the action is not available.
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $loan = Loan::factory()->funding()->create(['funded_amount' => '500.00']);

        Livewire::test(ListLoans::class)
            ->assertTableActionHidden('unpublish', $loan);
    }
}
