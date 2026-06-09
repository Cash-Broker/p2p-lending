<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Models\Loan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Identifies WHY the status dropdown save was failing, and proves the fix.
 *
 * Filament's edit form dehydrates the WHOLE record on save. On a non-draft loan
 * the model's immutability guard throws the moment any IMMUTABLE_AFTER_DRAFT
 * field looks changed — and the dehydrated payload coerces values — so a plain
 * status change tripped the guard → Filament's generic "error-notifications"
 * toast. EditLoan::sanitizeSaveData strips those fields so the status change
 * goes through.
 */
class LoanEditSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // EditRecord schema/save testing needs the panel to be the current one.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ── The blocker ──

    public function test_guard_blocks_a_locked_field_change_on_a_live_loan(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => '5000.00']);

        $this->expectException(\LogicException::class);

        // This is what the full-payload form save was effectively doing — and
        // why "Запази" blew up.
        $loan->update(['status' => Loan::STATUS_DRAFT, 'amount' => '9999.00']);
    }

    // ── The fix ──

    public function test_sanitize_strips_locked_fields_so_status_change_saves(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => '5000.00']);
        $originalBorrower = $loan->borrower_id;

        // The full, coercion-mangled payload Filament would dehydrate.
        $payload = [
            'status' => Loan::STATUS_DRAFT,
            'amount' => '9999.00',       // tampered
            'investable_amount' => '',   // coerced empty
            'co_borrower_id' => '',       // coerced empty (stored null)
            'interest_rate' => '99.99',
            'borrower_id' => 0,           // coerced
            'type' => 'business',
        ];

        $clean = EditLoan::sanitizeSaveData($loan, $payload);

        foreach (Loan::IMMUTABLE_AFTER_DRAFT as $field) {
            $this->assertArrayNotHasKey($field, $clean, "{$field} must be stripped on a live loan");
        }
        $this->assertSame(Loan::STATUS_DRAFT, $clean['status']);
        $this->assertNull($clean['published_at']);

        // The update now succeeds and ONLY the status changed.
        $loan->update($clean);

        $fresh = $loan->fresh();
        $this->assertEquals(Loan::STATUS_DRAFT, $fresh->status);
        $this->assertEquals('5000.00', $fresh->amount);
        $this->assertEquals($originalBorrower, $fresh->borrower_id);
    }

    public function test_sanitize_stamps_published_at_when_publishing_a_draft(): void
    {
        $loan = Loan::factory()->create(['status' => Loan::STATUS_DRAFT, 'published_at' => null]);

        $clean = EditLoan::sanitizeSaveData($loan, ['status' => Loan::STATUS_PUBLISHED]);

        $this->assertNotNull($clean['published_at']);
    }

    public function test_sanitize_keeps_everything_editable_for_draft_loans(): void
    {
        $loan = Loan::factory()->create(['status' => Loan::STATUS_DRAFT]);

        $clean = EditLoan::sanitizeSaveData($loan, ['status' => Loan::STATUS_DRAFT, 'amount' => '7777.00']);

        $this->assertArrayHasKey('amount', $clean);
        $this->assertSame('7777.00', $clean['amount']);
    }

    // ── End-to-end: the REAL Filament form save ──
    //
    // The sanitize tests above exercise the helper in isolation — they passed
    // even while the live form was broken. The actual production failure was a
    // dead `Filament\Forms\Get` type-hint on the (disabled-but-still-validated)
    // `investable_amount` field's rule closure: Filament v5 removed that class,
    // so building the validator for the published-loan edit threw, surfacing as
    // the generic "filament-panels::error-notifications" toast. Only a test that
    // drives the Livewire component through validation catches it.

    public function test_status_change_on_published_loan_saves_through_filament_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $loan = Loan::factory()->published()->create();

        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->fillForm(['status' => Loan::STATUS_DRAFT])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $loan->fresh();
        $this->assertSame(Loan::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->published_at);
    }

    public function test_published_loan_edit_form_mounts_without_error(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $loan = Loan::factory()->published()->create();

        // Mounting alone resolves the schema (incl. the investable_amount rule
        // closure signature). A dead Get/Set type-hint blows up here too.
        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->assertOk();
    }
}
