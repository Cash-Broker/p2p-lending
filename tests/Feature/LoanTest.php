<?php

namespace Tests\Feature;

use App\Models\BorrowerAnonymizedProfile;
use App\Models\Favorite;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $walletBalances = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletBalances) {
            $wallet->forceFill($walletBalances)->save();
        }
        return $user;
    }

    // ── Marketplace listing ──

    public function test_loans_index_returns_only_published_and_funding(): void
    {
        Loan::factory()->count(2)->published()->create();
        Loan::factory()->funding()->create();
        Loan::factory()->create(['status' => 'draft']);
        Loan::factory()->active()->create();

        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/loans');

        $response->assertOk();
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_loans_index_with_filters(): void
    {
        Loan::factory()->published()->create(['type' => 'consumer', 'interest_rate' => 8]);
        Loan::factory()->published()->create(['type' => 'bridge', 'interest_rate' => 14]);
        Loan::factory()->published()->create(['type' => 'consumer', 'interest_rate' => 12]);

        $user = $this->createVerifiedInvestor();

        // Filter by type
        $response = $this->actingAs($user)->getJson('/api/loans?type[]=consumer');
        $this->assertEquals(2, $response->json('meta.total'));

        // Filter by interest rate
        $response = $this->actingAs($user)->getJson('/api/loans?interest_rate_min=10');
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_loans_index_filter_by_risk_class(): void
    {
        $loan1 = Loan::factory()->published()->create();
        BorrowerAnonymizedProfile::factory()->create(['borrower_id' => $loan1->borrower_id, 'risk_class' => 'A']);

        $loan2 = Loan::factory()->published()->create();
        BorrowerAnonymizedProfile::factory()->create(['borrower_id' => $loan2->borrower_id, 'risk_class' => 'C']);

        $loan3 = Loan::factory()->published()->create();
        BorrowerAnonymizedProfile::factory()->create(['borrower_id' => $loan3->borrower_id, 'risk_class' => 'A']);

        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/loans?risk_class[]=A');
        $this->assertEquals(2, $response->json('meta.total'));

        $response = $this->actingAs($user)->getJson('/api/loans?risk_class[]=C');
        $this->assertEquals(1, $response->json('meta.total'));

        $response = $this->actingAs($user)->getJson('/api/loans?risk_class[]=A&risk_class[]=C');
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_loans_index_includes_risk_class_in_response(): void
    {
        $loan = Loan::factory()->published()->create();
        BorrowerAnonymizedProfile::factory()->create(['borrower_id' => $loan->borrower_id, 'risk_class' => 'B']);

        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/loans');
        $this->assertEquals('B', $response->json('data.0.anonymized_profile.risk_class'));
    }

    public function test_loans_index_does_not_expose_borrower_id(): void
    {
        Loan::factory()->published()->create();
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/loans');

        $loan = $response->json('data.0');
        $this->assertArrayNotHasKey('borrower_id', $loan);
        $this->assertArrayNotHasKey('interest_rate_annual', $loan);
    }

    // ── Loan detail ──

    public function test_loan_show_returns_full_data(): void
    {
        $loan = Loan::factory()->published()->create();
        BorrowerAnonymizedProfile::factory()->create(['borrower_id' => $loan->borrower_id]);
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson("/api/loans/{$loan->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'id', 'amount', 'funded_amount', 'interest_rate', 'term_months',
                'type', 'status', 'funded_percentage',
                'originator' => ['id', 'name'],
                'anonymized_profile' => ['risk_class', 'region'],
            ]);
    }

    // ── Investment ──

    public function test_invest_success(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500,
        ], ['X-Idempotency-Key' => 'invest-success-' . uniqid()]);

        $response->assertStatus(201)
            ->assertJson(['message' => 'Investment successful.']);

        // Wallet updated
        $wallet = $user->wallet->fresh();
        $this->assertEquals('4500.00', $wallet->available);
        $this->assertEquals('500.00', $wallet->invested);

        // Loan funded_amount updated
        $this->assertEquals('500.00', $loan->fresh()->funded_amount);

        // Investment record created
        $this->assertDatabaseHas('investments', [
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'amount' => 500,
        ]);

        // Transaction record created
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_INVESTMENT,
            'amount' => 500,
        ]);

        // Transaction has IP tracking
        $tx = Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_INVESTMENT)->first();
        $this->assertNotNull($tx->ip_address);
    }

    public function test_invest_transitions_loan_to_funding(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", ['amount' => 500], ['X-Idempotency-Key' => 'funding-' . uniqid()]);

        $this->assertEquals(Loan::STATUS_FUNDING, $loan->fresh()->status);
    }

    public function test_invest_transitions_loan_to_funded_when_fully_funded(): void
    {
        $loan = Loan::factory()->funding()->create(['amount' => 1000, 'funded_amount' => 950]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", ['amount' => 50], ['X-Idempotency-Key' => 'funded-' . uniqid()]);

        // After audit fix: fully funded loans go to FUNDED, not ACTIVE
        // Admin must manually activate via Filament
        $this->assertEquals(Loan::STATUS_FUNDED, $loan->fresh()->status);
    }

    public function test_invest_published_to_funded_via_funding_when_single_investment_fills_loan(): void
    {
        // Phase 2 audit — finding #1 regression guard.
        //
        // A PUBLISHED loan (no prior investments) that gets ONE
        // investment equal to its full amount must transition through
        // FUNDING before reaching FUNDED. The state machine forbids
        // `published → funded` directly (ALLOWED_TRANSITIONS[published]
        // = [draft, funding]). Pre-fix InvestmentService tried the
        // direct jump and threw InvalidArgumentException, rolling the
        // entire investment back — admins couldn't fund single-
        // investor loans in one shot.
        //
        // Post-fix: service routes PUBLISHED → FUNDING first, then
        // FUNDING → FUNDED on the same invest() call.
        $loan = Loan::factory()->published()->create(['amount' => 1000, 'funded_amount' => 0]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $this->actingAs($user)->postJson(
            "/api/loans/{$loan->id}/invest",
            ['amount' => 1000],
            ['X-Idempotency-Key' => 'single-fill-' . uniqid()],
        )->assertSuccessful();

        $this->assertEquals(Loan::STATUS_FUNDED, $loan->fresh()->status);
        $this->assertEquals('1000.00', (string) $loan->fresh()->funded_amount);
    }

    public function test_invest_fails_insufficient_balance(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000]);
        $user = $this->createVerifiedInvestor(['available' => 100]);

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500,
        ], ['X-Idempotency-Key' => 'insuf-' . uniqid()]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_invest_fails_kyc_not_approved(): void
    {
        $loan = Loan::factory()->published()->create();
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'pending']);
        $user->wallet()->create();

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500,
        ], ['X-Idempotency-Key' => 'kyc-' . uniqid()]);

        $response->assertStatus(403);
    }

    public function test_invest_fails_loan_already_funded(): void
    {
        $loan = Loan::factory()->create(['amount' => 1000, 'funded_amount' => 1000, 'status' => 'funded']);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 100,
        ], ['X-Idempotency-Key' => 'funded-' . uniqid()]);

        $response->assertStatus(422);
    }

    public function test_invest_fails_amount_exceeds_remaining(): void
    {
        $loan = Loan::factory()->funding()->create(['amount' => 1000, 'funded_amount' => 800]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 300,
        ], ['X-Idempotency-Key' => 'exceed-' . uniqid()]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_invest_fails_below_minimum(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 10,
        ], ['X-Idempotency-Key' => 'min-' . uniqid()]);

        $response->assertStatus(422);
    }

    public function test_invest_requires_idempotency_key(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000]);
        $user = $this->createVerifiedInvestor(['available' => 5000]);

        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 500,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'X-Idempotency-Key header is required.']);
    }

    // ── Concurrency ──

    public function test_concurrent_investments_do_not_overfund(): void
    {
        $loan = Loan::factory()->funding()->create(['amount' => 1000, 'funded_amount' => 500]);

        $user1 = $this->createVerifiedInvestor(['available' => 5000]);
        $user2 = $this->createVerifiedInvestor(['available' => 5000]);

        // Simulate concurrent investments — both try to invest 500 (only 500 remaining)
        $response1 = $this->actingAs($user1)->postJson("/api/loans/{$loan->id}/invest", ['amount' => 500], ['X-Idempotency-Key' => 'conc-1-' . uniqid()]);
        $response2 = $this->actingAs($user2)->postJson("/api/loans/{$loan->id}/invest", ['amount' => 500], ['X-Idempotency-Key' => 'conc-2-' . uniqid()]);

        // One should succeed, one should fail (or both succeed but total can't exceed loan amount)
        $successCount = collect([$response1, $response2])->filter(fn ($r) => $r->status() === 201)->count();
        $loan->refresh();

        // Funded amount must NEVER exceed loan amount
        $this->assertTrue(
            bccomp($loan->funded_amount, $loan->amount, 2) <= 0,
            "Loan overfunded: funded={$loan->funded_amount}, amount={$loan->amount}"
        );

        // At least one investment should succeed
        $this->assertGreaterThanOrEqual(1, $successCount);
    }

    // ── Favorites ──

    public function test_toggle_favorite_add_and_remove(): void
    {
        $loan = Loan::factory()->published()->create();
        $user = $this->createVerifiedInvestor();

        // Add
        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/favorite");
        $response->assertStatus(201)->assertJson(['favorited' => true]);

        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'loan_id' => $loan->id]);

        // Remove
        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/favorite");
        $response->assertOk()->assertJson(['favorited' => false]);

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'loan_id' => $loan->id]);
    }

    public function test_favorites_endpoint_returns_only_user_favorites(): void
    {
        $user = $this->createVerifiedInvestor();
        $loan1 = Loan::factory()->published()->create();
        $loan2 = Loan::factory()->published()->create();

        Favorite::create(['user_id' => $user->id, 'loan_id' => $loan1->id]);

        $response = $this->actingAs($user)->getJson('/api/loans/favorites');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }
}
