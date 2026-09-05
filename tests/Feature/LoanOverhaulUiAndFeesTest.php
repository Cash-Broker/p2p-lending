<?php

namespace Tests\Feature;

use App\Filament\Widgets\UpcomingDueDatesWidget;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loan-overhaul Phases 5–7: fees column (platform revenue, excluded from total),
 * investor-facing cap consistency, and the due-date dashboard query.
 */
class LoanOverhaulUiAndFeesTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedInvestor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_fees_are_separate_from_total_and_exposed_to_investors(): void
    {
        $loan = Loan::factory()->published()->create();
        $loan->amortizationSchedules()->create([
            'due_date' => now(),
            'principal' => '100.00',
            'interest' => '10.00',
            'total' => '110.00', // total excludes fees
            'fees' => '5.00',
            'status' => 'pending',
        ]);

        $this->actingAs($this->verifiedInvestor())->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('amortization_schedule.0.fees', '5.00')
            ->assertJsonPath('amortization_schedule.0.total', '110.00');
    }

    public function test_funded_percentage_and_amount_use_the_investable_cap(): void
    {
        $loan = Loan::factory()->published()->create([
            'amount' => '10000.00',
            'investable_amount' => '5000.00',
            'funded_amount' => '2500.00',
        ]);

        $this->actingAs($this->verifiedInvestor())->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('investable_amount', '5000.00')
            // 2500 / 5000 = 50% (not 2500 / 10000 = 25%).
            ->assertJsonPath('funded_percentage', 50);
    }

    public function test_due_dates_widget_surfaces_todays_active_installments_only(): void
    {
        $activeLoan = Loan::factory()->active()->create();
        $today = $activeLoan->amortizationSchedules()->create(['due_date' => now(), 'principal' => '100.00', 'interest' => '10.00', 'total' => '110.00', 'status' => 'pending']);
        $far = $activeLoan->amortizationSchedules()->create(['due_date' => now()->addDays(60), 'principal' => '100.00', 'interest' => '10.00', 'total' => '110.00', 'status' => 'pending']);
        $paid = $activeLoan->amortizationSchedules()->create(['due_date' => now(), 'principal' => '100.00', 'interest' => '10.00', 'total' => '110.00', 'status' => 'paid']);

        $draftLoan = Loan::factory()->create(['status' => 'draft']);
        $draftRow = $draftLoan->amortizationSchedules()->create(['due_date' => now(), 'principal' => '100.00', 'interest' => '10.00', 'total' => '110.00', 'status' => 'pending']);
        // PAY-13: a borrower tracker row is never paid by the admin — not a «падеж за разплащане».
        $trackerRow = $activeLoan->amortizationSchedules()->create(['plan_kind' => 'borrower_tracker', 'due_date' => now(), 'principal' => '100.00', 'interest' => '10.00', 'total' => '110.00', 'status' => 'pending']);

        $ids = UpcomingDueDatesWidget::dueInstallmentsQuery()->pluck('id')->all();
        $this->assertNotContains($trackerRow->id, $ids);  // borrower tracker → excluded (PAY-13)

        $this->assertContains($today->id, $ids);        // today, active, pending → shown
        $this->assertNotContains($far->id, $ids);       // 60 days out → excluded
        $this->assertNotContains($paid->id, $ids);      // already paid → excluded
        $this->assertNotContains($draftRow->id, $ids);  // draft loan → excluded
    }
}
