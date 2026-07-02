<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $walletBalances = []): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletBalances) {
            $wallet->forceFill($walletBalances)->save();
        }
        return $user;
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function test_unverified_investor_cannot_access_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('requires_verification', true);
    }

    public function test_admin_cannot_access_investor_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/dashboard')
            ->assertStatus(403);
    }

    public function test_verified_investor_gets_dashboard_data(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 5000, 'invested' => 2000, 'earned' => 150]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'wallet' => ['available', 'invested', 'earned', 'total'],
                'active_investments_count',
                'recent_transactions',
                'latest_loans',
                'monthly_earnings',
            ]);
    }

    public function test_dashboard_wallet_matches_actual_balance(): void
    {
        $user = $this->createVerifiedInvestor(['available' => 1234.56, 'invested' => 7890.12, 'earned' => 345.67]);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('wallet.available', '1234.56')
            ->assertJsonPath('wallet.invested', '7890.12')
            ->assertJsonPath('wallet.earned', '345.67')
            ->assertJsonPath('wallet.total', '9124.68');
    }

    public function test_dashboard_returns_latest_published_loans(): void
    {
        $user = $this->createVerifiedInvestor();

        Loan::factory()->count(3)->funding()->create();
        Loan::factory()->count(2)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk();
        $this->assertCount(3, $response->json('latest_loans'));
    }

    public function test_dashboard_latest_loans_never_include_private_loans(): void
    {
        // Regression: latest_loans used to filter only by status, so private
        // (link-only) loans leaked into every investor's dashboard feed.
        $user = $this->createVerifiedInvestor();

        Loan::factory()->count(2)->funding()->create();
        $private = Loan::factory()->funding()->create(['visibility' => Loan::VISIBILITY_PRIVATE]);
        $private->forceFill(['share_token' => Loan::generateShareToken()])->save();

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk();
        $loans = $response->json('latest_loans');
        $this->assertCount(2, $loans);
        $this->assertNotContains($private->id, array_column($loans, 'id'),
            'a private loan must never appear in the dashboard feed without a grant');
    }

    public function test_dashboard_loans_do_not_expose_borrower_id(): void
    {
        $user = $this->createVerifiedInvestor();
        Loan::factory()->funding()->create();

        $response = $this->actingAs($user)->getJson('/api/dashboard');
        $loans = $response->json('latest_loans');

        if (count($loans) > 0) {
            $this->assertArrayNotHasKey('borrower_id', $loans[0]);
            $this->assertArrayNotHasKey('interest_rate_annual', $loans[0]);
        }
    }

    public function test_dashboard_returns_active_investments_count(): void
    {
        $user = $this->createVerifiedInvestor();

        $activeLoan = Loan::factory()->active()->create();
        $repaidLoan = Loan::factory()->repaid()->create();

        Investment::factory()->count(2)->create(['user_id' => $user->id, 'loan_id' => $activeLoan->id]);
        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $repaidLoan->id]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('active_investments_count', 2);
    }

    public function test_dashboard_returns_max_five_recent_transactions(): void
    {
        $user = $this->createVerifiedInvestor();
        Transaction::factory()->count(8)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $this->assertCount(5, $response->json('recent_transactions'));
    }

    public function test_dashboard_monthly_earnings_has_six_months(): void
    {
        $user = $this->createVerifiedInvestor();

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $this->assertCount(6, $response->json('monthly_earnings'));
    }

    public function test_monthly_earnings_include_buyback_early_repayment_and_released_interest(): void
    {
        // Regression (audit 2026-07-02): the chart summed only scheduled
        // repayments, silently dropping buyback / early-repayment / released
        // capitalized interest — the exact bug class PortfolioController
        // already fixed by reading wallet.earned.
        $user = $this->createVerifiedInvestor();

        foreach ([
            ['type' => Transaction::TYPE_BUYBACK_INTEREST, 'amount' => 50],
            ['type' => Transaction::TYPE_EARLY_REPAYMENT_INTEREST, 'amount' => 25],
            ['type' => Transaction::TYPE_INTEREST_RELEASED, 'amount' => 25],
            ['type' => Transaction::TYPE_BUYBACK_PRINCIPAL, 'amount' => 60],
            ['type' => Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL, 'amount' => 40],
            // Locked recognition must NOT count — its release above does.
            ['type' => Transaction::TYPE_INTEREST_ACCRUED, 'amount' => 999],
        ] as $tx) {
            Transaction::factory()->create(['user_id' => $user->id] + $tx);
        }

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $month = collect($response->json('monthly_earnings'))
            ->firstWhere('month', now()->format('Y-m'));
        $this->assertNotNull($month);
        $this->assertEquals('100.00', $month['interest'], 'all interest income paths must count');
        $this->assertEquals('100.00', $month['principal'], 'all principal return paths must count');
    }

    // ── Financial precision tests ──

    public function test_wallet_total_uses_precise_decimal_math(): void
    {
        // Test that 0.1 + 0.2 = 0.30, not 0.30000000000000004
        $user = $this->createVerifiedInvestor(['available' => 0.10, 'invested' => 0.20]);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertJsonPath('wallet.total', '0.30');
    }

    public function test_wallet_balances_cannot_be_set_via_mass_assignment(): void
    {
        $user = $this->createVerifiedInvestor();

        // Simulate what would happen if someone tried to mass-assign wallet balances
        $user->wallet->fill(['available' => 999999.99, 'invested' => 0, 'earned' => 0]);
        $user->wallet->save();

        // Balance should remain 0.00 because 'available' is NOT in $fillable
        $this->assertEquals('0.00', $user->wallet->fresh()->available);
    }
}
