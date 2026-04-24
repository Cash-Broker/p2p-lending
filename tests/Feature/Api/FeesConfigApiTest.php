<?php

namespace Tests\Feature\Api;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4 Batch B — GET /api/fees/config.
 *
 * Covers the data contract the SPA (WithdrawalPage.vue) relies on:
 *   - Public access (no auth).
 *   - Stable nested shape (`withdrawal.enabled` bool + `withdrawal.amount`
 *     decimal-string).
 *   - Flag + amount reflected from platform_settings at request time.
 *
 * If any test in this file fails, the Vue breakdown will break silently
 * in production — so the contract tests are more important than the
 * backend-only service tests.
 */
class FeesConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_default_disabled_config(): void
    {
        $response = $this->getJson('/api/fees/config');

        $response->assertOk()
            ->assertExactJson([
                'withdrawal' => [
                    'enabled' => false,
                    'amount'  => '2.50',
                ],
            ]);
    }

    public function test_endpoint_is_public_no_auth_required(): void
    {
        // No actingAs — explicit unauthenticated request.
        $response = $this->getJson('/api/fees/config');

        $response->assertOk();
        $response->assertJsonStructure([
            'withdrawal' => ['enabled', 'amount'],
        ]);
    }

    public function test_response_reflects_enabled_toggle(): void
    {
        PlatformSetting::set('fees_withdrawal_enabled', true);

        $response = $this->getJson('/api/fees/config');

        $response->assertOk()
            ->assertJsonPath('withdrawal.enabled', true)
            ->assertJsonPath('withdrawal.amount', '2.50');
    }

    public function test_response_reflects_amount_change(): void
    {
        PlatformSetting::set('fees_withdrawal_amount', '5.75');

        $response = $this->getJson('/api/fees/config');

        $response->assertJsonPath('withdrawal.amount', '5.75');
    }

    public function test_amount_is_always_returned_as_normalised_two_decimal_string(): void
    {
        // Admin could conceivably save 5 (no decimals) via raw SQL; the
        // API must still return "5.00" so Vue's parseFloat + toFixed is
        // stable.
        PlatformSetting::where('key', 'fees_withdrawal_amount')->update(['value' => '5']);

        $response = $this->getJson('/api/fees/config');

        $response->assertJsonPath('withdrawal.amount', '5.00');
    }

    public function test_works_identically_for_authenticated_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/fees/config');

        $response->assertOk()
            ->assertJsonStructure([
                'withdrawal' => ['enabled', 'amount'],
            ]);
    }
}
