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

    // ═════════════════════════════════════════════════════════════════
    // F2 — Buyback metadata whitelist extensions.
    // Defense-in-depth: the whitelist is the single gatekeeper between
    // admin-internal metadata and investor-visible event history.
    // ═════════════════════════════════════════════════════════════════

    public function test_metadata_whitelist_filters_adversarial_buyback_keys(): void
    {
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        // Inject a buyback_completed event carrying BOTH whitelisted AND
        // non-whitelisted keys. The sanitiser must pass only the whitelisted
        // ones — opt-in safety.
        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'buyback_completed',
            'from_status' => 'late', 'to_status' => 'bought_back',
            'triggered_by' => 'admin', 'triggered_by_user_id' => $admin->id,
            'metadata' => [
                // whitelisted — must appear
                'coverage_type' => 'principal_plus_interest',
                'total_amount' => '220.00',
                'total_principal' => '200.00',
                'total_interest' => '20.00',
                'investor_count' => 3,
                'executed_at' => '2026-04-24T10:00:00+00:00',
                // NOT whitelisted — must NOT leak
                'executed_by_admin_id' => $admin->id,
                'originator_id' => 7,
                'internal_admin_note' => 'ops runbook link',
                'late_schedule_count' => 3,
                'paid_schedule_count' => 0,
                'total_schedule_count' => 3,
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk();
        $metadata = $response->json('data.0.metadata');

        // Whitelisted keys present
        $this->assertArrayHasKey('coverage_type', $metadata);
        $this->assertArrayHasKey('total_amount', $metadata);
        $this->assertArrayHasKey('total_principal', $metadata);
        $this->assertArrayHasKey('total_interest', $metadata);
        $this->assertArrayHasKey('investor_count', $metadata);
        $this->assertArrayHasKey('executed_at', $metadata);

        // Adversarial keys filtered out
        $this->assertArrayNotHasKey('executed_by_admin_id', $metadata,
            'admin identity must NEVER leak through event metadata');
        $this->assertArrayNotHasKey('originator_id', $metadata,
            'originator_id is admin-internal — Loan.originator relation is the public source');
        $this->assertArrayNotHasKey('internal_admin_note', $metadata);
        $this->assertArrayNotHasKey('late_schedule_count', $metadata);
        $this->assertArrayNotHasKey('paid_schedule_count', $metadata);
        $this->assertArrayNotHasKey('total_schedule_count', $metadata);
    }

    public function test_whitelisted_buyback_triggered_metadata_passes_through(): void
    {
        // Positive control — buyback_triggered event metadata (cron-detection
        // snapshot) has 4 whitelisted keys. All must appear in the API.
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'buyback_triggered',
            // Pure decision event — both-null status per Q22
            'from_status' => null, 'to_status' => null,
            'triggered_by' => 'system', 'triggered_by_user_id' => null,
            'metadata' => [
                'eligible_at' => '2026-04-24T03:45:12+00:00',
                'days_since_became_late' => 62,
                'calculated_buyback_amount_at_detection' => '1234.56',
                'coverage_type' => 'principal_plus_interest',
                'originator_id' => 7,  // not whitelisted
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk();
        $metadata = $response->json('data.0.metadata');

        $this->assertSame('2026-04-24T03:45:12+00:00', $metadata['eligible_at']);
        $this->assertSame(62, $metadata['days_since_became_late']);
        $this->assertSame('1234.56', $metadata['calculated_buyback_amount_at_detection']);
        $this->assertSame('principal_plus_interest', $metadata['coverage_type']);

        // originator_id kept admin-only even in buyback_triggered events
        $this->assertArrayNotHasKey('originator_id', $metadata);
    }

    // ═════════════════════════════════════════════════════════════════
    // F3 — Early-repayment metadata whitelist extensions.
    // ═════════════════════════════════════════════════════════════════

    public function test_metadata_whitelist_filters_adversarial_early_repayment_keys(): void
    {
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'early_repayment_completed',
            'from_status' => 'active', 'to_status' => 'repaid',
            'triggered_by' => 'admin', 'triggered_by_user_id' => $admin->id,
            'metadata' => [
                // whitelisted — must appear
                'from_status'     => 'active',
                'total_amount'    => '550.25',
                'total_principal' => '487.50',
                'total_interest'  => '62.75',
                'investor_count'  => 3,
                'executed_at'     => '2026-04-25T10:00:00+00:00',
                // NOT whitelisted — must NOT leak
                'executed_by_admin_id' => $admin->id,
                'internal_admin_note'  => 'borrower phoned at 14:00',
                'bank_transfer_ref'    => 'ABC123',
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk();
        $metadata = $response->json('data.0.metadata');

        // Whitelisted keys present
        $this->assertArrayHasKey('from_status', $metadata);
        $this->assertArrayHasKey('total_amount', $metadata);
        $this->assertArrayHasKey('total_principal', $metadata);
        $this->assertArrayHasKey('total_interest', $metadata);
        $this->assertArrayHasKey('investor_count', $metadata);
        $this->assertArrayHasKey('executed_at', $metadata);

        // Adversarial keys filtered out
        $this->assertArrayNotHasKey('executed_by_admin_id', $metadata,
            'admin identity must NEVER leak through early_repayment_completed metadata');
        $this->assertArrayNotHasKey('internal_admin_note', $metadata);
        $this->assertArrayNotHasKey('bank_transfer_ref', $metadata);
    }

    public function test_whitelisted_early_repayment_completed_metadata_passes_through(): void
    {
        // Positive control — all 6 whitelisted F3 keys surface correctly.
        $loan = $this->makeLoan();
        $investor = $this->makeInvestor();
        Investment::create(['user_id' => $investor->id, 'loan_id' => $loan->id, 'amount' => '50', 'invested_at' => now()]);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'early_repayment_completed',
            'from_status' => 'late', 'to_status' => 'repaid',
            'triggered_by' => 'admin', 'triggered_by_user_id' => $admin->id,
            'metadata' => [
                'from_status'     => 'late',
                'total_amount'    => '1120.00',
                'total_principal' => '1100.00',
                'total_interest'  => '20.00',
                'investor_count'  => 2,
                'executed_at'     => '2026-04-25T14:30:00+00:00',
                'executed_by_admin_id' => $admin->id,
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events");

        $response->assertOk();
        $metadata = $response->json('data.0.metadata');

        $this->assertSame('late', $metadata['from_status']);
        $this->assertSame('1120.00', $metadata['total_amount']);
        $this->assertSame('1100.00', $metadata['total_principal']);
        $this->assertSame('20.00', $metadata['total_interest']);
        $this->assertSame(2, $metadata['investor_count']);
        $this->assertSame('2026-04-25T14:30:00+00:00', $metadata['executed_at']);

        $this->assertArrayNotHasKey('executed_by_admin_id', $metadata);
    }
}
