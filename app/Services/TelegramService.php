<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends alerts to a Telegram chat via the Bot API.
 *
 * Three severity tiers:
 *   - critical(): 🔴 push notification, must-see (production exceptions,
 *     ledger mismatches, suspicious failed logins, health endpoint critical).
 *   - high(): 🟠 push notification, look when convenient (admin logins,
 *     executed buybacks/early repayments, big money movements).
 *   - info(): 🟡 silent (no push), daily digests + cron summaries.
 *
 * Configuration via config/services.php → reads TELEGRAM_BOT_TOKEN +
 * TELEGRAM_CHAT_ID from .env. If either is missing, send is a no-op.
 *
 * Failures are logged via Laravel Log facade (warning level) but never
 * thrown — Telegram outage must not break the parent flow.
 */
class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    private const TIMEOUT_SECONDS = 5;

    private const MAX_MESSAGE_CHARS = 4000; // Telegram limit is 4096; 4000 leaves headroom.

    public function __construct(
        private ?string $token = null,
        private ?string $defaultChatId = null,
    ) {
        $this->token = $this->token ?? config('services.telegram.bot_token');
        $this->defaultChatId = $this->defaultChatId ?? config('services.telegram.chat_id');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->token) && ! empty($this->defaultChatId);
    }

    public function sendMessage(string $text, ?string $chatId = null, array $opts = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $chatId = $chatId ?? $this->defaultChatId;
        $url = self::API_BASE.$this->token.'/sendMessage';

        // Truncate if too long — keep first chars + footer marker.
        if (mb_strlen($text) > self::MAX_MESSAGE_CHARS) {
            $text = mb_substr($text, 0, self::MAX_MESSAGE_CHARS - 50)
                ."\n\n<i>... (truncated)</i>";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->post($url, [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $opts['parse_mode'] ?? 'HTML',
                'disable_web_page_preview' => true,
                'disable_notification' => $opts['silent'] ?? false,
            ]);

            if (! $response->successful()) {
                Log::warning('Telegram message failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            // The request URL carries the bot token — never let it reach the log.
            Log::warning('Telegram exception', ['error' => str_replace((string) $this->token, '[bot-token]', $e->getMessage())]);

            return false;
        }
    }

    public function critical(string $title, string $body, array $context = []): bool
    {
        return $this->sendMessage($this->formatMessage('🔴', 'CRITICAL', $title, $body, $context));
    }

    public function high(string $title, string $body, array $context = []): bool
    {
        return $this->sendMessage($this->formatMessage('🟠', 'HIGH', $title, $body, $context));
    }

    public function info(string $title, string $body, array $context = []): bool
    {
        return $this->sendMessage(
            $this->formatMessage('🟡', 'INFO', $title, $body, $context),
            null,
            ['silent' => true],
        );
    }

    private function formatMessage(string $emoji, string $tier, string $title, string $body, array $context): string
    {
        $msg = $emoji.' <b>'.htmlspecialchars($tier.' — '.$title, ENT_QUOTES).'</b>'."\n\n"
            .htmlspecialchars($body, ENT_QUOTES);

        if (! empty($context)) {
            $msg .= "\n\n".$this->formatContext($context);
        }

        $msg .= "\n\n<i>Time: ".now()->format('Y-m-d H:i:s T').'</i>';

        return $msg;
    }

    private function formatContext(array $context): string
    {
        $lines = [];
        foreach ($context as $key => $value) {
            $valStr = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $lines[] = '<i>'.htmlspecialchars((string) $key, ENT_QUOTES).': '
                .htmlspecialchars($valStr, ENT_QUOTES).'</i>';
        }

        return implode("\n", $lines);
    }
}
