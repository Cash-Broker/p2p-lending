<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CSP rolls out in Report-Only mode first — any violation should
 * be observable in browser/server logs without breaking pages. Promotion
 * to enforcing mode (move CspPolicy from `report_only_presets` to
 * `presets`) happens after production logs are clean for several days.
 */
class CspHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_only_csp_header_is_emitted(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create();

        $response = $this->actingAs($user)->get('/api/wallet');

        $header = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($header, 'Report-Only CSP header missing');
    }

    public function test_csp_policy_includes_strict_directives(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create();

        $response = $this->actingAs($user)->get('/api/wallet');
        $header = $response->headers->get('Content-Security-Policy-Report-Only') ?? '';

        // default-src self
        $this->assertStringContainsString("default-src 'self'", $header);
        // script-src self only — no unsafe-inline / unsafe-eval
        $this->assertMatchesRegularExpression("/script-src[^;]*'self'/", $header);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $header);
        // object-src none
        $this->assertStringContainsString("object-src 'none'", $header);
        // frame-ancestors none
        $this->assertStringContainsString("frame-ancestors 'none'", $header);
    }

    public function test_csp_policy_allows_inline_styles_for_filament(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create();

        $response = $this->actingAs($user)->get('/api/wallet');
        $header = $response->headers->get('Content-Security-Policy-Report-Only') ?? '';

        // style-src must include unsafe-inline for Filament/Tailwind v4
        $this->assertMatchesRegularExpression("/style-src[^;]*'unsafe-inline'/", $header);
    }

    public function test_csp_policy_allows_data_and_blob_images(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create();

        $response = $this->actingAs($user)->get('/api/wallet');
        $header = $response->headers->get('Content-Security-Policy-Report-Only') ?? '';

        $this->assertMatchesRegularExpression("/img-src[^;]*data:/", $header);
        $this->assertMatchesRegularExpression("/img-src[^;]*blob:/", $header);
    }

    public function test_no_enforcing_csp_header_yet(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create();

        $response = $this->actingAs($user)->get('/api/wallet');

        // Enforcing mode would set Content-Security-Policy (no -Report-Only suffix).
        // Until production validation is done we should NOT block content.
        $this->assertNull(
            $response->headers->get('Content-Security-Policy'),
            'Enforcing CSP header should not be set until report-only validation passes'
        );
    }
}
