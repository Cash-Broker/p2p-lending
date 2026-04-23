<?php

namespace Tests\Feature\Api;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F1 Step 5 — /api/loans/{loan}/events endpoint.
 *
 * Authorization (LoanPolicy::viewEvents) AND metadata sanitisation
 * (LoanEventResource whitelist) are the two security-critical
 * surfaces; both are pinned here.
 */
class LoanEventsApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLoan(): Loan
    {
        $orig = Originator::create(['name' => 'F1 Events API', 'description' => 'X', 'buyback' => false]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A', 'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);
        return Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000', 'funded_amount' => '1000',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 6, 'type' => 'consumer', 'status' => 'draft',
        ]);
    }

    private function makeInvestor(bool $verified = true): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
        ]);
        $user->forceFill(['kyc_status' => 'approved'])->save();
        $user->wallet()->create();
        return $user;
    }

    public function test_investor_with_position_can_view_events(): void
    {
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        LoanEvent::create([
            'loan_id' => $loan->id,
            'event_type' => LoanEvent::TYPE_WENT_LATE,
            'from_status' => 'active', 'to_status' => 'late',
            'triggered_by' => 'system', 'triggered_by_user_id' => null,
            'metadata' => ['days_late_at_transition' => 15],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'went_late')
            ->assertJsonPath('data.0.metadata.days_late_at_transition', 15);
    }

    public function test_investor_without_position_gets_403(): void
    {
        $loan = $this->makeLoan();
        $stranger = $this->makeInvestor();
        // No investment in this loan.

        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'went_late',
            'from_status' => 'active', 'to_status' => 'late',
            'triggered_by' => 'system', 'triggered_by_user_id' => null,
            'occurred_at' => now(),
        ]);

        $this->actingAs($stranger)->getJson("/api/loans/{$loan->id}/events")->assertForbidden();
    }

    public function test_metadata_whitelist_filters_non_public_keys(): void
    {
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        // Inject metadata containing both whitelisted AND non-whitelisted keys.
        // The sanitiser must pass only the whitelisted ones — opt-in safety.
        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'recovered_from_late',
            'from_status' => 'late', 'to_status' => 'active',
            'triggered_by' => 'system', 'triggered_by_user_id' => null,
            'metadata' => [
                // whitelisted
                'previous_became_late_at' => '2026-01-03T00:00:00+00:00',
                'transitioned_to' => 'active',
                // NOT whitelisted — must NOT appear in API response
                'late_schedule_count' => 3,
                'paid_schedule_count' => 1,
                'total_schedule_count' => 6,
                'internal_admin_note' => 'sensitive operator note that must never leak',
                'admin_user_id_who_did_it' => 99,
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk();
        $metadata = $response->json('data.0.metadata');

        $this->assertArrayHasKey('previous_became_late_at', $metadata);
        $this->assertArrayHasKey('transitioned_to', $metadata);

        $this->assertArrayNotHasKey('late_schedule_count', $metadata);
        $this->assertArrayNotHasKey('paid_schedule_count', $metadata);
        $this->assertArrayNotHasKey('total_schedule_count', $metadata);
        $this->assertArrayNotHasKey('internal_admin_note', $metadata);
        $this->assertArrayNotHasKey('admin_user_id_who_did_it', $metadata);
    }

    public function test_response_does_not_leak_triggered_by_user_id(): void
    {
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'status_changed',
            'from_status' => 'late', 'to_status' => 'default',
            'triggered_by' => 'admin', 'triggered_by_user_id' => $admin->id,
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk();
        $event = $response->json('data.0');
        $this->assertSame('admin', $event['triggered_by'],
            'triggered_by category is exposed (system/admin)');
        $this->assertArrayNotHasKey('triggered_by_user_id', $event,
            'admin user id must NEVER reach the investor — only the source category');
    }

    public function test_pagination_meta_present(): void
    {
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        for ($i = 0; $i < 25; $i++) {
            LoanEvent::create([
                'loan_id' => $loan->id, 'event_type' => 'status_changed',
                'from_status' => 'active', 'to_status' => 'late',
                'triggered_by' => 'system', 'triggered_by_user_id' => null,
                'occurred_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 2);
    }
}
