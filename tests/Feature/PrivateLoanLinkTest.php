<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Private (link-only) loans: hidden from the public board, reachable only via
 * the share link which grants the opener persistent access. Covers the full
 * access matrix + the link → invest → portfolio flow.
 */
class PrivateLoanLinkTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private function privateLoan(array $overrides = []): Loan
    {
        $loan = Loan::factory()->published()->create(array_merge([
            'visibility' => Loan::VISIBILITY_PRIVATE,
        ], $overrides));
        $loan->forceFill(['share_token' => Loan::generateShareToken()])->save();

        return $loan->refresh();
    }

    public function test_private_loan_is_hidden_from_the_public_board(): void
    {
        $public = Loan::factory()->published()->create();
        $private = $this->privateLoan();

        $ids = collect(
            $this->actingAs($this->investor())->getJson('/api/loans')->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($public->id));
        $this->assertFalse($ids->contains($private->id));
    }

    public function test_investor_without_access_is_blocked_everywhere(): void
    {
        $loan = $this->privateLoan();
        $user = $this->investor();
        $offerId = $loan->offers()->value('id');

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")->assertStatus(403);
        $this->actingAs($user)->getJson("/api/loans/{$loan->id}/offer-quotes")->assertStatus(403);
        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/favorite")->assertStatus(403);
        $this->actingAs($user)->postJson(
            "/api/loans/{$loan->id}/invest",
            ['amount' => 100, 'loan_offer_id' => $offerId],
            ['X-Idempotency-Key' => 'blocked-'.uniqid()],
        )->assertStatus(403);
    }

    public function test_share_link_grants_access_then_show_works(): void
    {
        $loan = $this->privateLoan();
        $user = $this->investor();

        $this->actingAs($user)->getJson("/api/loans/shared/{$loan->share_token}")
            ->assertOk()
            ->assertJsonPath('loan_id', $loan->id);

        $this->assertDatabaseHas('loan_grants', ['loan_id' => $loan->id, 'user_id' => $user->id]);

        // Subsequent calls (no token) now pass via the grant.
        $this->actingAs($user)->getJson("/api/loans/{$loan->id}")->assertOk();
    }

    public function test_link_is_idempotent(): void
    {
        $loan = $this->privateLoan();
        $user = $this->investor();

        $this->actingAs($user)->getJson("/api/loans/shared/{$loan->share_token}")->assertOk();
        $this->actingAs($user)->getJson("/api/loans/shared/{$loan->share_token}")->assertOk();

        $this->assertSame(1, LoanGrant::where('loan_id', $loan->id)->where('user_id', $user->id)->count());
    }

    public function test_invalid_and_public_tokens_404(): void
    {
        $public = Loan::factory()->published()->create();
        $public->forceFill(['share_token' => Loan::generateShareToken()])->save();

        $user = $this->investor();
        $this->actingAs($user)->getJson('/api/loans/shared/nonexistent-token')->assertStatus(404);
        // A public loan never resolves through the private-link endpoint.
        $this->actingAs($user)->getJson("/api/loans/shared/{$public->share_token}")->assertStatus(404);
    }

    public function test_admin_and_position_holders_have_access(): void
    {
        $loan = $this->privateLoan();
        $admin = User::factory()->create(['role' => 'admin']);
        $investor = $this->investor();

        $this->assertTrue($loan->isAccessibleBy($admin));
        $this->assertFalse($loan->isAccessibleBy($investor));

        // A position alone grants access (no explicit grant needed).
        $loan->investments()->create([
            'user_id' => $investor->id,
            'amount' => '100.00',
            'invested_at' => now(),
        ]);
        $this->assertTrue($loan->fresh()->isAccessibleBy($investor));
    }

    public function test_link_to_invest_to_portfolio_flow(): void
    {
        $loan = $this->privateLoan(['amount' => 10000, 'investable_amount' => 10000, 'funded_amount' => 0]);
        $user = $this->investor();
        $offerId = $loan->offers()->value('id');

        $this->actingAs($user)->getJson("/api/loans/shared/{$loan->share_token}")->assertOk();

        $this->actingAs($user)->postJson(
            "/api/loans/{$loan->id}/invest",
            ['amount' => 1000, 'loan_offer_id' => $offerId],
            ['X-Idempotency-Key' => 'link-invest'],
        )->assertStatus(201);

        $portfolioLoanIds = collect(
            $this->actingAs($user)->getJson('/api/portfolio')->assertOk()->json('data')
        )->pluck('loan.id');

        $this->assertTrue($portfolioLoanIds->contains($loan->id));
    }
}
