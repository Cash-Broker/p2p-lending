<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * «Кредити» / «Оригинатори» in the public navigation (Reni 2026-08-20).
 *
 * The gate itself is a frontend concern (covered by
 * resources/js/utils/publicGate.test.js). What is worth pinning server-side is
 * narrow but real: the two paths must keep reaching the SPA shell rather than a
 * login redirect or a 404, no loan data may be readable without a session, and
 * the hand-maintained sitemap must not silently fall behind the routes.
 */
class PublicLandingPagesTest extends TestCase
{
    private const PUBLIC_LANDING_PATHS = ['/loans', '/originators'];

    /**
     * The catch-all in routes/web.php serves these today. The assertion earns
     * its place the day someone registers a real web route on one of them (or
     * tightens the catch-all regex): a guest would then be redirected or 404'd
     * instead of getting the register-first page.
     */
    public function test_public_landing_subpages_serve_the_spa_shell_to_guests(): void
    {
        foreach (self::PUBLIC_LANDING_PATHS as $path) {
            $response = $this->get($path);

            $response->assertStatus(200);
            $response->assertSee('id="app"', false);
        }
    }

    public function test_loan_data_stays_closed_to_guests(): void
    {
        $this->getJson('/api/loans')->assertUnauthorized();
        $this->getJson('/api/portfolio')->assertUnauthorized();
    }

    /**
     * public/sitemap.xml is written by hand and its own header promises launch
     * day is "just flip robots.txt" — which only holds while every public route
     * is listed.
     */
    public function test_sitemap_lists_the_public_landing_subpages(): void
    {
        $sitemap = file_get_contents(public_path('sitemap.xml'));

        foreach (self::PUBLIC_LANDING_PATHS as $path) {
            $this->assertStringContainsString("https://vamaasset.bg{$path}</loc>", $sitemap);
        }
    }
}
