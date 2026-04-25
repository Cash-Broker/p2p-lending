<?php

namespace App\Console\Commands\Ops;

use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Sanity-check command for the Telegram integration.
 *
 * Sends one of each tier (CRITICAL / HIGH / INFO) to confirm:
 *   - .env has TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID populated.
 *   - The bot can reach the chat (user has /start-ed the bot).
 *   - All three tiers render correctly in the Telegram client.
 *
 * Usage:
 *   php artisan telegram:test
 *
 * Run after first deploy to verify the integration is alive.
 */
class TelegramTest extends Command
{
    protected $signature = 'telegram:test {--tier=all : critical|high|info|all}';

    protected $description = 'Send test messages to Telegram to verify the integration.';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('Telegram is NOT configured. Check TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env.');

            return self::FAILURE;
        }

        $tier = $this->option('tier');

        if ($tier === 'all' || $tier === 'critical') {
            $ok = $telegram->critical(
                'Test alert (CRITICAL)',
                'Това е тестово CRITICAL съобщение от artisan telegram:test. Push notification трябва да дойде.',
                ['source' => 'telegram:test', 'env' => app()->environment()],
            );
            $this->line($ok ? '✓ CRITICAL sent' : '✗ CRITICAL failed (see logs)');
        }

        if ($tier === 'all' || $tier === 'high') {
            $ok = $telegram->high(
                'Test alert (HIGH)',
                'Това е тестово HIGH съобщение от artisan telegram:test. Push notification трябва да дойде.',
                ['source' => 'telegram:test'],
            );
            $this->line($ok ? '✓ HIGH sent' : '✗ HIGH failed');
        }

        if ($tier === 'all' || $tier === 'info') {
            $ok = $telegram->info(
                'Test alert (INFO)',
                'Това е тестово INFO съобщение — silent (без push notification).',
                ['source' => 'telegram:test'],
            );
            $this->line($ok ? '✓ INFO sent' : '✗ INFO failed');
        }

        $this->info('Done. Check your Telegram chat.');

        return self::SUCCESS;
    }
}
