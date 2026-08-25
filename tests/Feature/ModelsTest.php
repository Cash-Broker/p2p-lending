<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\DepositRequest;
use App\Models\Favorite;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    // ── User ──

    public function test_user_can_be_created_with_all_fields(): void
    {
        $user = User::factory()->create([
            'role' => 'investor',
            'kyc_status' => 'approved',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'investor',
            'kyc_status' => 'approved',
        ]);
    }

    public function test_admin_user_can_be_created(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isInvestor());
    }

    public function test_investor_registration_creates_wallet(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Wallet Test',
            'email' => 'wallettest@example.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'wallettest@example.com')->first();
        $this->assertNotNull($user->wallet);
        $this->assertEquals('0.00', $user->wallet->available);
        $this->assertEquals('0.00', $user->wallet->invested);
        $this->assertEquals('0.00', $user->wallet->earned);
    }

    // ── Borrower & Anonymized Profile ──

    public function test_borrower_personal_id_is_encrypted(): void
    {
        $borrower = Borrower::factory()->create(['personal_id' => '1234567890']);

        // Raw DB value should not be plaintext
        $raw = \DB::table('borrowers')->where('id', $borrower->id)->value('personal_id');
        $this->assertNotEquals('1234567890', $raw);

        // But model accessor decrypts it
        $this->assertEquals('1234567890', $borrower->fresh()->personal_id);
    }

    public function test_borrower_has_anonymized_profile(): void
    {
        $borrower = Borrower::factory()->create();
        $profile = BorrowerAnonymizedProfile::factory()->create([
            'borrower_id' => $borrower->id,
            'risk_class' => 'B',
        ]);

        $this->assertEquals('B', $borrower->fresh()->anonymizedProfile->risk_class);
    }

    // ── Loan Relationships ──

    public function test_loan_belongs_to_originator_and_borrower(): void
    {
        $originator = Originator::factory()->create();
        $borrower = Borrower::factory()->create();

        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
        ]);

        $this->assertEquals($originator->id, $loan->originator->id);
        $this->assertEquals($borrower->id, $loan->borrower->id);
    }

    public function test_loan_has_many_investments(): void
    {
        $loan = Loan::factory()->create();
        Investment::factory()->count(3)->create(['loan_id' => $loan->id]);

        $this->assertCount(3, $loan->fresh()->investments);
    }

    public function test_loan_has_anonymized_profile_through_borrower(): void
    {
        $borrower = Borrower::factory()->create();
        BorrowerAnonymizedProfile::factory()->create([
            'borrower_id' => $borrower->id,
            'risk_class' => 'A',
        ]);
        $loan = Loan::factory()->create(['borrower_id' => $borrower->id]);

        $this->assertEquals('A', $loan->anonymizedProfile->risk_class);
    }

    // ── Investment ──

    public function test_investment_belongs_to_user_and_loan(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->create();

        $investment = Investment::factory()->create([
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'amount' => 500.00,
        ]);

        $this->assertEquals($user->id, $investment->user->id);
        $this->assertEquals($loan->id, $investment->loan->id);
        $this->assertEquals('500.00', $investment->amount);
    }

    // ── DepositRequest ──

    public function test_deposit_request_generates_unique_reference_code(): void
    {
        $deposit1 = DepositRequest::factory()->create();
        $deposit2 = DepositRequest::factory()->create();

        $this->assertNotEmpty($deposit1->reference_code);
        $this->assertStringStartsWith('DEP-', $deposit1->reference_code);
        $this->assertNotEquals($deposit1->reference_code, $deposit2->reference_code);
    }

    // ── Favorites ──

    public function test_favorite_unique_constraint_prevents_duplicates(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->create();

        Favorite::factory()->create(['user_id' => $user->id, 'loan_id' => $loan->id]);

        $this->expectException(QueryException::class);
        Favorite::factory()->create(['user_id' => $user->id, 'loan_id' => $loan->id]);
    }

    // ── Originator ──

    public function test_originator_has_many_loans(): void
    {
        $originator = Originator::factory()->create();
        Loan::factory()->count(3)->create(['originator_id' => $originator->id]);

        $this->assertCount(3, $originator->fresh()->loans);
    }

    // ── User relationships ──

    public function test_user_has_many_investments_and_transactions(): void
    {
        $user = User::factory()->create();
        Investment::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->fresh()->investments);
    }
}
