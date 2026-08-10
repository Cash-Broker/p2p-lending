<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Models\Investment;
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

    /** A live loan with an actual investor position — the frozen case. */
    private function investedPublishedLoan(string $amount = '5000.00'): Loan
    {
        $loan = Loan::factory()->published()->create(['amount' => $amount]);
        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        Investment::factory()->create([
            'loan_id' => $loan->id,
            'user_id' => $investor->id,
            'amount' => '100.00',
        ]);

        return $loan->fresh();
    }

    // ── 2026-08-10 (client, explicit): EVERYTHING editable, always ──

    public function test_invested_loan_terms_are_fully_editable(): void
    {
        $loan = $this->investedPublishedLoan();

        $loan->update(['amount' => '9999.00', 'term_months' => 9, 'interest_rate' => '15.00']);

        $fresh = $loan->fresh();
        $this->assertSame('9999.00', $fresh->amount);
        $this->assertSame(9, (int) $fresh->term_months);
    }

    public function test_committed_investor_snapshot_survives_loan_term_edits(): void
    {
        $loan = $this->investedPublishedLoan();
        $investment = Investment::where('loan_id', $loan->id)->first();
        $snapshotRate = (string) $investment->interest_rate;

        // The loan-level edit must never touch the investor's contracted terms.
        $loan->update(['interest_rate' => '55.55', 'term_months' => 48]);

        $this->assertSame($snapshotRate, (string) $investment->fresh()->interest_rate);
    }

    public function test_published_loan_without_investments_is_fully_editable(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => '5000.00']);

        $loan->update(['amount' => '7000.00', 'term_months' => 9, 'type' => 'business']);
        $this->assertSame('7000.00', $loan->fresh()->amount);

        $clean = EditLoan::sanitizeSaveData($loan->fresh(), ['amount' => '8000.00', 'status' => Loan::STATUS_PUBLISHED]);
        $this->assertSame('8000.00', $clean['amount']);
    }

    // ── sanitize: no stripping, only coercion clean-up + published_at ──

    public function test_sanitize_keeps_all_fields_and_normalizes_coerced_empties(): void
    {
        $loan = $this->investedPublishedLoan();

        // The full, coercion-mangled payload Filament would dehydrate.
        $payload = [
            'status' => Loan::STATUS_PUBLISHED,
            'amount' => '9999.00',
            'investable_amount' => '',   // coerced empty → must become null
            'co_borrower_id' => '',       // coerced empty → must become null
            'interest_rate' => '19.99',
        ];

        $clean = EditLoan::sanitizeSaveData($loan, $payload);

        $this->assertSame('9999.00', $clean['amount']);
        $this->assertSame('19.99', $clean['interest_rate']);
        $this->assertNull($clean['investable_amount']);
        $this->assertNull($clean['co_borrower_id']);

        $loan->update($clean);
        $this->assertEquals('9999.00', $loan->fresh()->amount);
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

    public function test_bottom_save_button_stays_bound_to_the_form(): void
    {
        // 2026-08-10 layout: the buttons render BELOW the relation managers,
        // OUTSIDE the <form> element — the submit button works only through
        // the HTML form-id association. If either half of the pair
        // disappears, «Запази» becomes a dead button.
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $loan = Loan::factory()->published()->create();

        Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
            ->assertOk()
            ->assertSeeHtml('id="form"')
            ->assertSeeHtml('form="form"');
    }
}
