<?php

namespace Tests\Feature;

use App\Models\SavedIban;
use App\Models\User;
use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\SavedIbanConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * SEC-01 (owner 2026-09-03): a new payout IBAN is confirmed through a signed
 * e-mail link before it can receive a withdrawal.
 */
class SavedIbanConfirmationTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    private const IBAN = 'BG80BNBG96611020345678';

    private function investor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    /** @return array{0: SavedIban, 1: string} the row and the signed confirmation URL */
    private function addIban(User $user, string $iban = self::IBAN): array
    {
        Notification::fake();
        $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => $iban, 'label' => 'Основна'])->assertStatus(201);

        $url = null;
        Notification::assertSentTo($user, SavedIbanConfirmationNotification::class, function (SavedIbanConfirmationNotification $n) use (&$url) {
            $url = $n->confirmationUrl();

            return true;
        });

        return [SavedIban::where('user_id', $user->id)->latest('id')->firstOrFail(), $url];
    }

    public function test_a_new_iban_is_stored_unconfirmed_with_a_hashed_token_and_the_owner_gets_the_mail(): void
    {
        $user = $this->investor();
        Notification::fake();

        $response = $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => self::IBAN, 'label' => 'Основна']);

        $response->assertStatus(201)
            ->assertJsonPath('iban.confirmed', false)
            ->assertJsonPath('iban.withdrawable_now', false)
            ->assertJsonMissingPath('iban.confirmation_token_hash');

        $row = SavedIban::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($row->confirmed_at);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->confirmation_token_hash);
        $this->assertNotNull($row->confirmation_expires_at);

        Notification::assertSentTo($user, SavedIbanConfirmationNotification::class, function ($n, array $channels) {
            return in_array('mail', $channels, true) && in_array('database', $channels, true) && ! in_array(QueuedWebPushChannel::class, $channels, true);
        });
        $this->assertStringNotContainsString('confirmation_token_hash', json_encode($response->json()));
    }

    public function test_the_signed_link_confirms_the_iban_once_and_redirects_to_the_profile(): void
    {
        $user = $this->investor();
        [$row, $url] = $this->addIban($user);
        $this->assertStringContainsString('/ibans/confirm/', $url);
        $this->assertStringContainsString('signature=', $url);

        // GET is the read-only landing page: a mail-link prefetch must not confirm.
        $this->get($url)->assertOk()->assertSee('Потвърди IBAN')->assertSee($row->maskedIban());
        $this->assertNull($row->fresh()->confirmed_at, 'a GET changes nothing');

        $this->post($url)->assertRedirect(config('app.url').'/profile?iban=confirmed');

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertNull($fresh->confirmation_token_hash);
        $this->assertTrue($fresh->isConfirmed());

        // Second click: idempotent, nothing rewritten.
        $confirmedAt = $fresh->confirmed_at;
        $this->post($url)->assertRedirect(config('app.url').'/profile?iban=already');
        $this->get($url)->assertRedirect(config('app.url').'/profile?iban=already');
        $this->assertEquals($confirmedAt, $row->fresh()->confirmed_at);

        // The audit trail has the update but never the hash.
        $this->assertDatabaseHas('audit_logs', ['model_type' => SavedIban::class, 'model_id' => $row->id, 'action' => 'updated']);
        $this->assertStringNotContainsString('confirmation_token_hash":"', (string) DB::table('audit_logs')->where('model_type', SavedIban::class)->orderByDesc('id')->value('new_values'));
    }

    public function test_a_wrong_token_and_a_tampered_signature_are_refused(): void
    {
        $user = $this->investor();
        [$row, $url] = $this->addIban($user);

        $wrongToken = URL::temporarySignedRoute('ibans.confirm', now()->addMinutes(30), ['iban' => $row->id, 'token' => str_repeat('x', 64)]);
        $this->get($wrongToken)->assertRedirect(config('app.url').'/profile?iban=invalid');

        $this->get($url.'tampered')->assertStatus(403);

        $this->assertNull($row->fresh()->confirmed_at);
    }

    public function test_an_expired_link_is_refused_and_a_resend_rotates_the_token(): void
    {
        $user = $this->investor();
        [$row, $oldUrl] = $this->addIban($user);
        DB::table('saved_ibans')->where('id', $row->id)->update(['confirmation_expires_at' => now()->subMinute()]);

        $this->get($oldUrl)->assertRedirect(config('app.url').'/profile?iban=expired');

        Notification::fake();
        $this->actingAs($user)->postJson("/api/profile/ibans/{$row->id}/resend-confirmation")
            ->assertOk()
            ->assertJsonPath('iban.confirmed', false);
        $newUrl = null;
        Notification::assertSentTo($user, SavedIbanConfirmationNotification::class, function (SavedIbanConfirmationNotification $n) use (&$newUrl) {
            $newUrl = $n->confirmationUrl();

            return true;
        });

        $this->assertNotSame($oldUrl, $newUrl);
        $this->get($oldUrl)->assertRedirect(config('app.url').'/profile?iban=invalid');
        $this->post($oldUrl)->assertRedirect(config('app.url').'/profile?iban=invalid');
        $this->post($newUrl)->assertRedirect(config('app.url').'/profile?iban=confirmed');
        $this->assertTrue($row->fresh()->isConfirmed());
    }

    public function test_resend_is_owner_only_and_refused_once_confirmed(): void
    {
        $user = $this->investor();
        $other = $this->investor();
        $row = $this->unconfirmedIban($user);

        $this->actingAs($other)->postJson("/api/profile/ibans/{$row->id}/resend-confirmation")->assertStatus(403);

        $confirmed = $this->confirmedIban($user, 'BG18RZBB91550123456789');
        $this->actingAs($user)->postJson("/api/profile/ibans/{$confirmed->id}/resend-confirmation")
            ->assertStatus(422)
            ->assertJsonValidationErrors('iban');
    }

    public function test_the_same_iban_twice_for_one_investor_is_refused(): void
    {
        $user = $this->investor();
        $this->addIban($user);

        $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => 'bg80 bnbg 9661 1020 3456 78'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('iban');

        // Another investor may of course save the same account.
        $other = $this->investor();
        $this->actingAs($other)->postJson('/api/profile/ibans', ['iban' => self::IBAN])->assertStatus(201);
    }

    public function test_rows_from_before_the_feature_count_as_confirmed_since_creation(): void
    {
        $user = $this->investor();
        $legacy = $user->savedIbans()->create(['iban' => self::IBAN, 'label' => 'стар']);

        $this->assertTrue($legacy->isLegacy());
        $this->assertTrue($legacy->isConfirmed());

        // Created just now → still inside the 24 h cooling-off; two days old → open.
        $this->actingAs($user)->getJson('/api/profile/ibans')
            ->assertJsonPath('data.0.legacy', true)
            ->assertJsonPath('data.0.confirmed', true)
            ->assertJsonPath('data.0.withdrawable_now', false);

        DB::table('saved_ibans')->where('id', $legacy->id)->update(['created_at' => now()->subDays(2)]);
        $this->actingAs($user)->getJson('/api/profile/ibans')
            ->assertJsonPath('data.0.withdrawable_now', true)
            ->assertJsonMissingPath('data.0.confirmation_token_hash');
    }

    public function test_adding_ibans_is_rate_limited_to_five_per_hour(): void
    {
        // Review 2026-09-05: every add is a confirmation e-mail, and delete + re-add
        // sidestepped the per-row resend limit — the add endpoint carries its own cap.
        $user = $this->investor();
        Notification::fake();

        $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => self::IBAN])->assertStatus(201);
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => self::IBAN])->assertStatus(422); // duplicate — still counts
        }
        $this->actingAs($user)->postJson('/api/profile/ibans', ['iban' => self::IBAN])->assertStatus(429);
        Notification::assertSentToTimes($user, SavedIbanConfirmationNotification::class, 1);
    }
}
