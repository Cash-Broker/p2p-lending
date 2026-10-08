<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\RelationManagers\AmortizationSchedulesRelationManager;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression for the schedule-row form's "total" validation closure, which
 * type-hinted the removed Filament\Forms\Get (v5 moved it to
 * Filament\Schemas\Components\Utilities\Get). Building the validator for the
 * relation-manager create/edit form therefore threw — the same class of
 * failure as the loan status edit, surfacing as the generic
 * "filament-panels::error-notifications" toast. These tests drive the form
 * through validation so the closure is actually evaluated.
 */
class AmortizationScheduleRowFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function relationManager(Loan $loan): Testable
    {
        return Livewire::test(AmortizationSchedulesRelationManager::class, [
            'ownerRecord' => $loan,
            'pageClass' => EditLoan::class,
        ]);
    }

    public function test_add_schedule_row_passes_validation_and_persists(): void
    {
        $loan = Loan::factory()->create(['status' => Loan::STATUS_DRAFT, 'amount' => '5000.00']);

        $this->relationManager($loan)
            ->callTableAction('create', data: [
                'due_date' => now()->addDays(30)->format('Y-m-d'),
                'principal' => '100.00',
                'interest' => '10.00',
                'total' => '110.00',
            ])
            ->assertHasNoTableActionErrors();

        $row = $loan->amortizationSchedules()->first();
        $this->assertNotNull($row);
        $this->assertSame('110.00', (string) $row->total);
    }

    public function test_row_total_must_equal_principal_plus_interest(): void
    {
        $loan = Loan::factory()->create(['status' => Loan::STATUS_DRAFT, 'amount' => '5000.00']);

        // The closure now evaluates (no crash) AND still enforces the identity.
        $this->relationManager($loan)
            ->callTableAction('create', data: [
                'due_date' => now()->addDays(30)->format('Y-m-d'),
                'principal' => '100.00',
                'interest' => '10.00',
                'total' => '999.00', // != principal + interest
            ])
            ->assertHasTableActionErrors(['total']);

        $this->assertSame(0, $loan->amortizationSchedules()->count());
    }

    /**
     * Prod runs APP_TIMEZONE=Europe/Sofia while this suite runs UTC, so a
     * timezone bug in Filament's date-only fields is invisible to every other
     * test. Filament 5.10.0 parsed Eloquent's UTC-serialized dates
     * ("2026-10-14T21:00:00Z" for a Sofia midnight) without moving them into
     * the app timezone: the edit form showed — and an unchanged save wrote
     * back — the PREVIOUS day. Pinned at 22:30 UTC, when UTC and Sofia are
     * already on different calendar days.
     */
    public function test_edit_keeps_the_stored_due_date_under_the_production_timezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Sofia']);
        date_default_timezone_set('Europe/Sofia');
        $this->travelTo(Carbon::parse('2026-10-14 22:30:00', 'UTC'));

        try {
            $loan = Loan::factory()->create(['status' => Loan::STATUS_DRAFT, 'amount' => '5000.00']);
            $row = AmortizationSchedule::factory()->create([
                'loan_id' => $loan->id,
                'due_date' => '2026-10-15',
                'principal' => '100.00',
                'interest' => '10.00',
                'total' => '110.00',
            ]);

            $this->relationManager($loan)
                ->mountTableAction('edit', $row)
                ->assertTableActionDataSet(['due_date' => '2026-10-15'])
                ->callMountedTableAction()
                ->assertHasNoTableActionErrors();

            $this->assertSame('2026-10-15', $row->fresh()->due_date->toDateString());
        } finally {
            date_default_timezone_set($previousTimezone);
            config(['app.timezone' => $previousTimezone]);
        }
    }
}
