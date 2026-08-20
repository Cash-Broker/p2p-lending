<?php

namespace Tests\Feature;

use App\Mail\AdminLoginAlertMail;
use App\Models\AdminTrustedIp;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Compensating control for absence of admin 2FA — every admin login fires an
 * email alert flagging known vs new IPs. See DECISIONS.md.
 */
class AdminLoginAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    private function investor(): User
    {
        return User::factory()->create([
            'role' => 'investor',
            'email_verified_at' => now(),
        ]);
    }

    private function fireLogin(User $user, string $ip = '203.0.113.10'): void
    {
        // Simulate login with a request bound to the desired IP.
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $this->get('/'); // bind a request to the container
        event(new Login('web', $user, false));
    }

    public function test_admin_login_from_new_ip_sends_alert_with_new_ip_subject(): void
    {
        $admin = $this->admin();

        $this->fireLogin($admin, '198.51.100.20');

        Mail::assertQueued(AdminLoginAlertMail::class, function (AdminLoginAlertMail $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->isKnownIp === false
                && str_contains($mail->envelope()->subject, 'NEW IP');
        });
    }

    public function test_admin_login_from_trusted_ip_sends_alert_with_known_ip_subject(): void
    {
        $admin = $this->admin();
        AdminTrustedIp::create([
            'user_id' => $admin->id,
            'ip_address' => '203.0.113.50',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->fireLogin($admin, '203.0.113.50');

        Mail::assertQueued(AdminLoginAlertMail::class, function (AdminLoginAlertMail $mail) {
            return $mail->isKnownIp === true
                && str_contains($mail->envelope()->subject, 'Known IP');
        });
    }

    public function test_investor_login_does_not_send_alert(): void
    {
        $investor = $this->investor();

        $this->fireLogin($investor, '203.0.113.99');

        Mail::assertNothingQueued();
    }

    public function test_failed_login_does_not_send_alert(): void
    {
        // The Login event fires only on successful auth, so a failed POST to
        // /admin/login (or anywhere else) cannot trigger the listener.
        // Verified by NOT firing the event and ensuring no mail.
        $this->admin();

        Mail::assertNothingQueued();
    }

    public function test_repeated_logins_from_same_ip_are_consolidated(): void
    {
        $admin = $this->admin();
        $ip = '198.51.100.77';

        // First login → email immediately.
        $this->fireLogin($admin, $ip);

        // Logins 2-10 → suppressed.
        for ($i = 2; $i <= 10; $i++) {
            $this->fireLogin($admin, $ip);
        }

        // 11th login → consolidated email.
        $this->fireLogin($admin, $ip);

        // Logins 12+ within window → suppressed.
        $this->fireLogin($admin, $ip);
        $this->fireLogin($admin, $ip);

        Mail::assertQueuedCount(2);

        Mail::assertQueued(AdminLoginAlertMail::class, function (AdminLoginAlertMail $mail) {
            return $mail->consolidatedCount === 1; // first email
        });

        Mail::assertQueued(AdminLoginAlertMail::class, function (AdminLoginAlertMail $mail) {
            return $mail->consolidatedCount === 11; // consolidated email
        });
    }

    public function test_alert_email_contains_ip_user_agent_and_timestamp(): void
    {
        $admin = $this->admin();

        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.42',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Test Browser)',
        ]);
        $this->get('/');
        event(new Login('web', $admin, false));

        Mail::assertQueued(AdminLoginAlertMail::class, function (AdminLoginAlertMail $mail) {
            return $mail->ipAddress === '198.51.100.42'
                && $mail->userAgent === 'Mozilla/5.0 (Test Browser)'
                && $mail->occurredAt !== null;
        });
    }

    public function test_login_from_known_ip_updates_last_seen_at(): void
    {
        $admin = $this->admin();
        $trusted = AdminTrustedIp::create([
            'user_id' => $admin->id,
            'ip_address' => '203.0.113.55',
            'first_seen_at' => now()->subDays(10),
            'last_seen_at' => now()->subDays(10),
        ]);

        $this->fireLogin($admin, '203.0.113.55');

        $this->assertTrue($trusted->fresh()->last_seen_at->isToday());
    }

    public function test_trust_ip_link_adds_record(): void
    {
        $admin = $this->admin();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'admin.trust-ip',
            now()->addDays(7),
            ['user' => $admin->id, 'ip' => '198.51.100.5'],
        );

        $response = $this->get($url);

        $response->assertOk();
        $this->assertDatabaseHas('admin_trusted_ips', [
            'user_id' => $admin->id,
            'ip_address' => '198.51.100.5',
        ]);
    }

    public function test_trust_ip_link_rejects_unsigned(): void
    {
        $admin = $this->admin();

        $response = $this->get("/admin/trust-ip/{$admin->id}/198.51.100.5");

        $response->assertStatus(403);
        $this->assertDatabaseMissing('admin_trusted_ips', [
            'user_id' => $admin->id,
            'ip_address' => '198.51.100.5',
        ]);
    }

    public function test_trust_ip_link_for_non_admin_404s(): void
    {
        $investor = $this->investor();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'admin.trust-ip',
            now()->addDays(7),
            ['user' => $investor->id, 'ip' => '198.51.100.5'],
        );

        $response = $this->get($url);

        $response->assertStatus(404);
    }

    // ── Prod cache store (regression, audit 2026-07-02) ──

    /**
     * The suite runs CACHE_STORE=array (phpunit.xml), whose increment() treats
     * a missing key as 0 and returns 1 — so the counter bug that killed this
     * alert in production was invisible here for months. Production runs the
     * DATABASE store, where increment() on a missing key returns false. These
     * tests pin the behaviour on the store that actually ships.
     */
    private function useDatabaseCacheStore(): void
    {
        config(['cache.default' => 'database']);
        Cache::store('database')->flush();
    }

    public function test_first_login_alerts_on_the_database_cache_store(): void
    {
        $this->useDatabaseCacheStore();
        $admin = $this->admin();

        $this->fireLogin($admin, '198.51.100.30');

        // Before the fix this was zero: Cache::increment() returned false on
        // the missing key, so `$count === 1` never matched.
        Mail::assertQueued(AdminLoginAlertMail::class, 1);
    }

    public function test_repeated_logins_consolidate_on_the_database_cache_store(): void
    {
        $this->useDatabaseCacheStore();
        $admin = $this->admin();

        // Logins 1..10 — only the first one emails.
        for ($i = 0; $i < 10; $i++) {
            $this->fireLogin($admin, '198.51.100.30');
        }
        Mail::assertQueued(AdminLoginAlertMail::class, 1);

        // The 11th crosses the threshold and sends ONE consolidated email.
        $this->fireLogin($admin, '198.51.100.30');
        Mail::assertQueued(AdminLoginAlertMail::class, 2);
        Mail::assertQueued(
            AdminLoginAlertMail::class,
            fn (AdminLoginAlertMail $mail) => $mail->consolidatedCount === 11,
        );

        // Everything after it stays quiet until the window expires.
        $this->fireLogin($admin, '198.51.100.30');
        Mail::assertQueued(AdminLoginAlertMail::class, 2);
    }

    /** A different IP is a different window — it must alert on its own. */
    public function test_second_ip_alerts_separately_on_the_database_cache_store(): void
    {
        $this->useDatabaseCacheStore();
        $admin = $this->admin();

        $this->fireLogin($admin, '198.51.100.30');
        $this->fireLogin($admin, '203.0.113.77');

        Mail::assertQueued(AdminLoginAlertMail::class, 2);
    }
}
