<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pentest Phase B finding [LOW-Phase B-1]: unauthenticated requests to a
 * `web`+`auth` route used to crash with `RouteNotFoundException: Route [login]
 * not defined.` because Laravel's `Authenticate` middleware tries to redirect
 * via `route('login')`. The application has only `auth:sanctum` (API) routes
 * and a Filament admin panel — no web-named `login` route was registered.
 *
 * Fix: a stub `login` named route in routes/web.php that serves the Vue SPA
 * shell (the SPA's own router renders the login page).
 *
 * These tests guard against regression — if the stub is removed, the response
 * codes below will flip back to 500 and the ERROR log will fill with
 * RouteNotFoundException entries.
 */
class UnauthenticatedRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_route_is_named_and_resolves(): void
    {
        // route('login') used to throw — must succeed now.
        $url = route('login');
        $this->assertSame(url('/login'), $url);
    }

    public function test_unauthenticated_browser_request_to_protected_web_route_redirects_to_login(): void
    {
        // /admin/kyc-document/* is gated by `web`+`auth`. Without auth, an HTML
        // request must redirect to /login (302), not crash with 500.
        $response = $this->get('/admin/kyc-document/anything', ['Accept' => 'text/html']);

        $response->assertRedirect('/login');
    }

    public function test_unauthenticated_json_request_to_protected_web_route_returns_401(): void
    {
        $response = $this->getJson('/admin/kyc-document/anything');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_request_to_api_route_returns_401_json(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_login_route_is_registered_in_router(): void
    {
        // We can't render the SPA shell in tests without a Vite manifest, so
        // just confirm the named route is bound and resolves to the GET method.
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('login');

        $this->assertNotNull($route, 'login route must be defined to satisfy Laravel auth middleware redirects');
        $this->assertContains('GET', $route->methods());
    }
}
