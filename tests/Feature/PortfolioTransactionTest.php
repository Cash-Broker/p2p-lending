<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioTransactionTest extends TestCase
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

    // ── Portfolio ──

    public function test_portfolio_returns_only_own_investments(): void
    {
        $user1 = $this->createVerifiedInvestor();
        $user2 = $this->createVerifiedInvestor();
        $loan = Loan::factory()->active()->create();

        Investment::factory()->count(3)->create(['user_id' => $user1->id, 'loan_id' => $loan->id]);
        Investment::factory()->count(2)->create(['user_id' => $user2->id, 'loan_id' => $loan->id]);

        $response = $this->actingAs($user1)->getJson('/api/portfolio');

        $response->assertOk();
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_portfolio_includes_loan_details(): void
    {
        $user = $this->createVerifiedInvestor();
        $originator = Originator::factory()->create();
        $loan = Loan::factory()->active()->create(['originator_id' => $originator->id]);
        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $loan->id]);

        $response = $this->actingAs($user)->getJson('/api/portfolio');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'amount', 'invested_at', 'loan' => ['id', 'type', 'interest_rate', 'originator']]]]);
    }

    public function test_portfolio_summary_calculates_totals(): void
    {
        $user = $this->createVerifiedInvestor();
        $activeLoan = Loan::factory()->active()->create();
        $repaidLoan = Loan::factory()->repaid()->create();

        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $activeLoan->id, 'amount' => 1000]);
        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $repaidLoan->id, 'amount' => 500]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 75.50,
        ]);

        $response = $this->actingAs($user)->getJson('/api/portfolio/summary');

        $response->assertOk()
            ->assertJsonPath('total_invested', '1500.00')
            ->assertJsonPath('total_earned', '75.50')
            ->assertJsonPath('active_investments_count', 1)
            ->assertJsonStructure([
                'breakdown_by_status' => ['active', 'late', 'default', 'repaid'],
                'breakdown_by_originator',
            ]);

        $this->assertEquals('1000.00', $response->json('breakdown_by_status.active'));
        $this->assertEquals('500.00', $response->json('breakdown_by_status.repaid'));
    }

    public function test_portfolio_summary_breakdown_by_originator(): void
    {
        $user = $this->createVerifiedInvestor();
        $orig1 = Originator::factory()->create(['name' => 'Originator A']);
        $orig2 = Originator::factory()->create(['name' => 'Originator B']);

        $loan1 = Loan::factory()->active()->create(['originator_id' => $orig1->id]);
        $loan2 = Loan::factory()->active()->create(['originator_id' => $orig2->id]);

        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $loan1->id, 'amount' => 2000]);
        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $loan2->id, 'amount' => 3000]);

        $response = $this->actingAs($user)->getJson('/api/portfolio/summary');

        $originators = collect($response->json('breakdown_by_originator'));
        $this->assertCount(2, $originators);
    }

    public function test_portfolio_unauthenticated(): void
    {
        $this->getJson('/api/portfolio')->assertStatus(401);
        $this->getJson('/api/portfolio/summary')->assertStatus(401);
    }

    // ── Transactions ──

    public function test_transactions_returns_only_own(): void
    {
        $user1 = $this->createVerifiedInvestor();
        $user2 = $this->createVerifiedInvestor();

        Transaction::factory()->count(5)->create(['user_id' => $user1->id]);
        Transaction::factory()->count(3)->create(['user_id' => $user2->id]);

        $response = $this->actingAs($user1)->getJson('/api/transactions');

        $response->assertOk();
        $this->assertEquals(5, $response->json('meta.total'));
    }

    public function test_transactions_filter_by_type(): void
    {
        $user = $this->createVerifiedInvestor();

        Transaction::factory()->count(3)->create(['user_id' => $user->id, 'type' => Transaction::TYPE_DEPOSIT]);
        Transaction::factory()->count(2)->create(['user_id' => $user->id, 'type' => Transaction::TYPE_INVESTMENT]);

        $response = $this->actingAs($user)->getJson('/api/transactions?type[]=deposit');

        $response->assertOk();
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_transactions_filter_by_date(): void
    {
        $user = $this->createVerifiedInvestor();

        Transaction::factory()->create(['user_id' => $user->id, 'created_at' => '2026-01-15 10:00:00']);
        Transaction::factory()->create(['user_id' => $user->id, 'created_at' => '2026-02-15 10:00:00']);
        Transaction::factory()->create(['user_id' => $user->id, 'created_at' => '2026-03-15 10:00:00']);

        $response = $this->actingAs($user)->getJson('/api/transactions?date_from=2026-02-01&date_to=2026-02-28');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_transactions_pagination(): void
    {
        $user = $this->createVerifiedInvestor();
        Transaction::factory()->count(25)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/transactions');

        $response->assertOk();
        $this->assertEquals(20, count($response->json('data'))); // 20 per page
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }

    public function test_transactions_unauthenticated(): void
    {
        $this->getJson('/api/transactions')->assertStatus(401);
    }
}
