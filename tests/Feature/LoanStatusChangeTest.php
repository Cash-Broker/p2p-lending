<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_published_loan_can_transition_to_draft_at_model_level(): void
    {
        $loan = Loan::factory()->published()->create();

        $loan->update(['status' => Loan::STATUS_DRAFT]);

        $this->assertEquals(Loan::STATUS_DRAFT, $loan->fresh()->status);
    }

    // ── Status changes go through the row actions (the safe, tested path) ──

    public function test_admin_can_publish_a_draft_loan(): void
    {
        $this->actingAs($this->admin());
        $loan = Loan::factory()->create(['status' => Loan::STATUS_DRAFT]);

        Livewire::test(ListLoans::class)
            ->callTableAction('publish', $loan)
            ->assertHasNoErrors();

        $fresh = $loan->fresh();
        $this->assertEquals(Loan::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_admin_can_revert_published_loan_to_draft(): void
    {
        $this->actingAs($this->admin());
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
        $this->actingAs($this->admin());
        $loan = Loan::factory()->funding()->create(['funded_amount' => '500.00']);

        Livewire::test(ListLoans::class)
            ->assertTableActionHidden('unpublish', $loan);
    }

    // ── The edit page must render for a live loan (regression for the form) ──

    public function test_edit_page_renders_for_published_loan(): void
    {
        $this->actingAs($this->admin());
        $loan = Loan::factory()->published()->create();

        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->assertOk();
    }
}
