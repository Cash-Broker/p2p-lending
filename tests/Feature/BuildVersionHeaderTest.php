<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deploy self-refresh (2026-08-15): every API response carries X-Build so
 * stale SPA tabs detect a deploy and reload themselves on the next
 * navigation. Pins presence + stability of the fingerprint.
 */
class BuildVersionHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_a_stable_build_fingerprint(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $first = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
        $second = $this->actingAs($user)->getJson('/api/wallet')->assertOk();

        $this->assertNotEmpty($first->headers->get('X-Build'));
        // Same deploy → same fingerprint on every endpoint.
        $this->assertSame($first->headers->get('X-Build'), $second->headers->get('X-Build'));
    }

    public function test_unauthenticated_responses_carry_it_too(): void
    {
        // The stale-tab check must work even on 401s (expired session tabs).
        $response = $this->getJson('/api/dashboard');

        $this->assertNotEmpty($response->headers->get('X-Build'));
    }
}
