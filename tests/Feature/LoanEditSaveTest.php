<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
