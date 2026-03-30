<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Role-based access ──

    public function test_admin_cannot_access_investor_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/dashboard')
            ->assertStatus(403);
    }

    public function test_unverified_investor_cannot_access_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('requires_verification', true);
    }

    public function test_verified_investor_can_access_dashboard(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk();
    }

    // ── API Resource field filtering ──

    public function test_user_response_does_not_leak_sensitive_fields(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create();

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('remember_token', $response->json());
    }

    public function test_loan_response_does_not_expose_borrower_id(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        Loan::factory()->funding()->create();

        $response = $this->actingAs($user)->getJson('/api/dashboard');
        $loans = $response->json('latest_loans');

        if (count($loans) > 0) {
            $this->assertArrayNotHasKey('borrower_id', $loans[0]);
            $this->assertArrayNotHasKey('interest_rate_annual', $loans[0]);
        }
    }

    public function test_login_response_uses_user_resource(): void
    {
        User::factory()->create([
            'email' => 'login@test.com',
            'password' => bcrypt('Password123!'),
        ]);

        $response = $this->withSession([])->postJson('/api/login', [
            'email' => 'login@test.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role']]);
        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('user'));
    }

    // ── Rate limiting ──

    public function test_public_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/register', [
                'name' => 'User',
                'email' => "user{$i}@test.com",
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'terms_accepted' => true,
            ]);
        }

        $response->assertStatus(429);
    }

    // ── Wallet security ──

    public function test_wallet_balances_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create();

        // Attempt mass-assignment of financial fields
        $wallet->fill(['available' => 999999.99]);
        $wallet->save();

        $this->assertEquals('0.00', $wallet->fresh()->available);
    }
}
