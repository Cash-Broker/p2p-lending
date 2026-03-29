<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Registration ──

    public function test_user_can_register_successfully(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
            ->assertJson(['message' => 'Registration successful. Please verify your email.']);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_wallet_is_created_on_registration(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Wallet User',
            'email' => 'wallet@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $user = User::where('email', 'wallet@example.com')->first();

        $this->assertNotNull($user->wallet);
        $this->assertEquals('0.00', $user->wallet->available);
        $this->assertEquals('0.00', $user->wallet->invested);
        $this->assertEquals('0.00', $user->wallet->earned);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Another User',
            'email' => 'taken@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Weak User',
            'email' => 'weak@example.com',
            'password' => '123',
            'password_confirmation' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    // ── Login ──

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
        ]);

        $response = $this->withSession([])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Login successful.']);
    }

    public function test_login_fails_with_wrong_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
        ]);

        $response = $this->withSession([])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ── Logout ──

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->withSession([])->postJson('/api/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);
    }

    // ── Get User ──

    public function test_authenticated_user_can_get_their_profile(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['available' => 0, 'invested' => 0, 'earned' => 0]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonStructure(['wallet' => ['available', 'invested', 'earned']]);
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }
}
