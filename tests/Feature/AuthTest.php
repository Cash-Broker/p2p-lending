<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear rate limiter between tests — prevents cross-test poisoning
        app(\Illuminate\Cache\RateLimiter::class)->clear('127.0.0.1');
    }

    private function validRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ], $overrides);
    }

    // ── Registration ──

    public function test_user_can_register_successfully(): void
    {
        $response = $this->postJson('/api/register', $this->validRegistrationData());

        $response->assertStatus(201)
            ->assertJson(['message' => 'Registration successful. Please verify your email.']);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_wallet_is_created_on_registration(): void
    {
        $response = $this->postJson('/api/register', $this->validRegistrationData([
            'email' => 'wallet@example.com',
        ]));

        $response->assertStatus(201);

        $user = User::where('email', 'wallet@example.com')->first();
        $this->assertNotNull($user, 'User was not created');
        $this->assertNotNull($user->wallet);
        $this->assertEquals('0.00', $user->wallet->available);
        $this->assertEquals('0.00', $user->wallet->invested);
        $this->assertEquals('0.00', $user->wallet->earned);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', $this->validRegistrationData([
            'email' => 'taken@example.com',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/register', $this->validRegistrationData([
            'password' => '123',
            'password_confirmation' => '123',
        ]));

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
        $user->wallet()->create();

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
