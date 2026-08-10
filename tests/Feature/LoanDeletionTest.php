<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Loan deletion from the admin list (boss 2026-08-10) — row action + bulk.
 * The single rule everywhere: ONLY drafts with zero funding and no
 * investments. Loans investors have money in are financial records —
 * removing them needs the (still open) refund/reversal flow.
 */
class LoanDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_admin_deletes_a_draft_loan_from_the_list(): void
    {
        $draft = Loan::factory()->create(['status' => 'draft', 'funded_amount' => 0]);

        Livewire::test(ListLoans::class)
            ->callTableAction('delete', $draft);

        $this->assertDatabaseMissing('loans', ['id' => $draft->id]);
        // Auto-seeded offers cascade with their loan.
        $this->assertDatabaseMissing('loan_offers', ['loan_id' => $draft->id]);
    }

    public function test_delete_action_hidden_for_non_draft_loans(): void
    {
        $published = Loan::factory()->published()->create(['funded_amount' => 0]);

        Livewire::test(ListLoans::class)
            ->assertTableActionHidden('delete', $published);
    }

    public function test_delete_action_hidden_for_draft_with_investment(): void
    {
        $draft = Loan::factory()->create(['status' => 'draft', 'funded_amount' => 0]);
        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        Investment::factory()->create([
            'loan_id' => $draft->id,
            'user_id' => $investor->id,
            'amount' => '100.00',
        ]);

        Livewire::test(ListLoans::class)
            ->assertTableActionHidden('delete', $draft);
    }

    public function test_bulk_delete_removes_only_eligible_drafts(): void
    {
        $eligible = Loan::factory()->create(['status' => 'draft', 'funded_amount' => 0]);
        $published = Loan::factory()->published()->create(['funded_amount' => 0]);

        $draftWithInvestment = Loan::factory()->create(['status' => 'draft', 'funded_amount' => 0]);
        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        Investment::factory()->create([
            'loan_id' => $draftWithInvestment->id,
            'user_id' => $investor->id,
            'amount' => '100.00',
        ]);

        Livewire::test(ListLoans::class)
            ->callTableBulkAction('delete_drafts', [$eligible, $published, $draftWithInvestment]);

        $this->assertDatabaseMissing('loans', ['id' => $eligible->id]);
        $this->assertDatabaseHas('loans', ['id' => $published->id]);
        $this->assertDatabaseHas('loans', ['id' => $draftWithInvestment->id]);
    }
}
