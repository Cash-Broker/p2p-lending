<?php

namespace Tests\Feature\Commands;

use App\Models\DepositRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * telegram:digest counting rules.
 *
 * Regression (2026-07-02, reported from prod): the digest counted EVERY
 * pending deposit_request — including "empty" issued codes (amount NULL)
 * nobody has wired against — so a pre-launch platform with zero real
 * deposits reported "Депозити чакащи потвърждение: 4". The admin UI
 * (DepositRequestResource, StatsOverview) has always hidden those rows;
 * the digest must apply the same rule.
 *
 * Also pins the "Нови регистрации (24ч)" window: a strict sliding 24 hours
 * back from the send moment.
 */
class TelegramDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '42',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** The HTML-escaped text of the last message sent to the Telegram API. */
    private function sentText(): string
    {
        $text = '';
        Http::assertSent(function ($request) use (&$text) {
            $text = $request['text'] ?? '';

            return str_contains($request->url(), 'api.telegram.org');
        });

        return $text;
    }

    public function test_unfunded_deposit_codes_are_not_counted_as_pending_deposits(): void
    {
        $user = User::factory()->create();
        // Four issued codes, no wire ever arrived — the prod scenario.
        DepositRequest::factory()->count(4)->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        Artisan::call('telegram:digest');

        $text = $this->sentText();
        $this->assertStringContainsString('Депозити чакащи потвърждение: 0', $text);
    }

    public function test_funded_pending_deposit_is_counted(): void
    {
        $user = User::factory()->create();
        DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => '250.00',
            'status' => 'pending',
        ]);
        DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        Artisan::call('telegram:digest');

        $this->assertStringContainsString('Депозити чакащи потвърждение: 1', $this->sentText());
    }

    public function test_unfunded_codes_alone_do_not_trigger_needs_attention_title(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(3)]);
        DepositRequest::factory()->count(4)->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        Artisan::call('telegram:digest');

        $this->assertStringContainsString('всичко е чисто', $this->sentText());
    }

    public function test_new_registrations_counts_the_last_24_hours(): void
    {
        User::factory()->create(['created_at' => now()->subHours(2)]);
        User::factory()->create(['created_at' => now()->subHours(23)]);
        User::factory()->create(['created_at' => now()->subHours(25)]); // outside the window

        Artisan::call('telegram:digest');

        $this->assertStringContainsString('Нови регистрации (24ч): 2', $this->sentText());
    }

    public function test_digest_skips_silently_when_telegram_not_configured(): void
    {
        config(['services.telegram.bot_token' => null]);

        Artisan::call('telegram:digest');

        Http::assertNothingSent();
    }
}
