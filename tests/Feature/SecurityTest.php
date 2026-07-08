<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Security Headers ──

    public function test_responses_include_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        // camera=(self) — NOT camera=() — because the KYC live selfie calls
        // getUserMedia; an empty allowlist disables the camera for our own
        // origin and no browser permission prompt can override it.
        $response->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
    }

    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/user');

        // Even 401 responses should have security headers
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ── Role protection ──

    public function test_role_cannot_be_set_via_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Hacker',
            'email' => 'hacker@test.com',
            'password' => 'Password123!',
            'role' => 'admin', // Attempt to escalate privilege
        ]);

        // Should be investor (default), not admin
        $this->assertEquals('investor', $user->fresh()->role);
    }

    public function test_kyc_status_cannot_be_set_via_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Hacker',
            'email' => 'hacker2@test.com',
            'password' => 'Password123!',
            'kyc_status' => 'approved', // Attempt to bypass KYC
        ]);

        $this->assertEquals('pending', $user->fresh()->kyc_status);
    }

    // ── Password policy ──

    public function test_registration_rejects_password_without_uppercase(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'test@test.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_rejects_password_without_number(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'test@test.com',
            'password' => 'PasswordABC!',
            'password_confirmation' => 'PasswordABC!',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_rejects_password_without_symbol(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'test@test.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_accepts_strong_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'strong@test.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(201);
    }
}
