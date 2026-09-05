<?php

namespace Tests\Unit\Support;

use App\Support\OpsAlert;
use Tests\TestCase;

/**
 * Review 2026-09-05: `.env.example` used to ship `ADMIN_ALERT_EMAIL=` and a
 * present-but-empty env key overrides the config default with '' — every ops
 * alert would then be dropped with only a log line. Empty must mean default.
 */
class OpsAlertRecipientTest extends TestCase
{
    public function test_an_empty_recipient_falls_back_to_the_default_address(): void
    {
        config(['app.admin_email' => '']);
        $this->assertSame(OpsAlert::DEFAULT_RECIPIENT, OpsAlert::email());

        config(['app.admin_email' => null]);
        $this->assertSame(OpsAlert::DEFAULT_RECIPIENT, OpsAlert::email());
    }

    public function test_a_configured_recipient_wins(): void
    {
        config(['app.admin_email' => 'ops@example.com']);
        $this->assertSame('ops@example.com', OpsAlert::email());
    }

    public function test_the_config_file_treats_an_empty_env_value_as_unset(): void
    {
        $source = file_get_contents(config_path('app.php'));
        $this->assertStringContainsString("env('ADMIN_ALERT_EMAIL') ?: ", $source, 'config/app.php must use ?: so ADMIN_ALERT_EMAIL= does not mute the alerts');
    }
}
