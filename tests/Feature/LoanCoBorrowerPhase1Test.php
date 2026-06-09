<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loan-overhaul Phase 1: borrower "general info" (no ЕГН), inline-creation
 * support (anonymized profile always present), and the co-debtor (съдлъжник)
 * with its own investor-facing anonymized profile.
 */
class LoanCoBorrowerPhase1Test extends TestCase
{
    use RefreshDatabase;

    private function verifiedInvestor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_borrower_can_be_created_without_personal_id(): void
    {
        // Mirrors inline creation from the loan form — general info, no ЕГН.
        $borrower = Borrower::create([
            'full_name' => 'Иван Тестов',
            'address' => 'ул. Тест 1',
            'phone' => '+359888000000',
            'income' => '2000.00',
        ]);

        $this->assertNotNull($borrower->id);
        $this->assertNull($borrower->fresh()->personal_id);
    }

    public function test_ensure_anonymized_profile_is_idempotent(): void
    {
        $borrower = Borrower::factory()->create();
        $this->assertNull($borrower->anonymizedProfile);

        $borrower->ensureAnonymizedProfile();
        $borrower->ensureAnonymizedProfile(); // second call must not double-create (borrower_id is UNIQUE)

        $this->assertEquals(1, BorrowerAnonymizedProfile::where('borrower_id', $borrower->id)->count());
        $this->assertEquals('C', $borrower->fresh()->anonymizedProfile->risk_class);
    }

    public function test_loan_co_borrower_relations_resolve(): void
    {
        $co = Borrower::factory()->create();
        $co->ensureAnonymizedProfile();

        $loan = Loan::factory()->create(['co_borrower_id' => $co->id]);

        $this->assertEquals($co->id, $loan->coBorrower->id);
        $this->assertEquals('C', $loan->coBorrowerAnonymizedProfile->risk_class);
    }

    public function test_investor_api_exposes_co_borrower_anonymized_profile_without_pii(): void
    {
        $primary = Borrower::factory()->create();
        $primary->ensureAnonymizedProfile();
        $co = Borrower::factory()->create();
        $co->ensureAnonymizedProfile();

        $loan = Loan::factory()->published()->create([
            'borrower_id' => $primary->id,
            'co_borrower_id' => $co->id,
        ]);

        $response = $this->actingAs($this->verifiedInvestor())->getJson("/api/loans/{$loan->id}");

        $response->assertOk()
            ->assertJsonPath('anonymized_profile.risk_class', 'C')
            ->assertJsonPath('co_borrower_anonymized_profile.risk_class', 'C');

        // Raw borrower / co-debtor identity must never leak.
        $response->assertJsonMissingPath('borrower_id');
        $response->assertJsonMissingPath('co_borrower_id');
    }

    public function test_loan_without_co_borrower_returns_null_co_profile(): void
    {
        $primary = Borrower::factory()->create();
        $primary->ensureAnonymizedProfile();

        $loan = Loan::factory()->published()->create([
            'borrower_id' => $primary->id,
            'co_borrower_id' => null,
        ]);

        $this->actingAs($this->verifiedInvestor())->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('co_borrower_anonymized_profile', null);
    }
}
