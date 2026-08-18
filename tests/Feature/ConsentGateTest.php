<?php

namespace Tests\Feature;

use App\Models\ConsentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentGateTest extends TestCase
{
    use RefreshDatabase;

    /** A user whose document consents are all at the given version. */
    private function userWithConsentVersion(string $version): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        foreach (array_keys(ConsentRecord::currentDocumentVersions()) as $type) {
            $user->consentRecords()->create([
                'type' => $type,
                'version' => $version,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'accepted_at' => now()->subDay(),
            ]);
        }

        return $user;
    }

    public function test_terms_v11_users_must_re_accept_after_the_v12_bump(): void
    {
        // v1.2 (2026-08-18) added the bonus-release conditions and the early
        // repayment clause — everyone who accepted v1.1 has to see them.
        // Everything else stays current, so the bump is what is under test.
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        foreach (ConsentRecord::currentDocumentVersions() as $type => $version) {
            $user->consentRecords()->create([
                'type' => $type,
                'version' => $type === ConsentRecord::TYPE_TERMS ? 'v1.1' : $version,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'accepted_at' => now()->subDay(),
            ]);
        }

        $pending = $this->actingAs($user)->getJson('/api/consents/pending')
            ->assertOk()
            ->json('pending');

        $this->assertContains(ConsentRecord::TYPE_TERMS, collect($pending)->pluck('type')->all());
        $this->assertSame('v1.2', ConsentRecord::CURRENT_TERMS_VERSION);

        // …and a financial action is refused until they do.
        $this->actingAs($user)->postJson('/api/withdrawal', ['amount' => '50.00'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'consent_required');

        $this->actingAs($user)->postJson('/api/consents/accept', [
            'types' => [ConsentRecord::TYPE_TERMS],
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/consents/pending')
            ->assertOk()->assertJsonPath('pending', []);
    }

    public function test_pending_lists_only_stale_documents(): void
    {
        // Terms + Privacy are now v1.1; Risk is unchanged at v1.0.
        $user = $this->userWithConsentVersion('v1.0');

        $response = $this->actingAs($user)->getJson('/api/consents/pending');

        $response->assertOk();
        $types = collect($response->json('pending'))->pluck('type')->sort()->values()->all();
        $this->assertEquals([
            ConsentRecord::TYPE_PRIVACY,
            ConsentRecord::TYPE_TERMS,
        ], $types);
    }

    public function test_user_with_current_consent_has_no_pending(): void
    {
        $user = $this->userWithConsentVersion('v1.0');

        $this->actingAs($user)->postJson('/api/consents/accept', [
            'types' => [ConsentRecord::TYPE_TERMS, ConsentRecord::TYPE_PRIVACY],
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/consents/pending')
            ->assertOk()->assertJsonPath('pending', []);
    }

    public function test_user_without_any_records_is_not_gated(): void
    {
        // Fail-open: a user with NO consent records at all (only possible for
        // test fixtures / pre-consent-system accounts) is not a re-consent case.
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->actingAs($user)->getJson('/api/consents/pending')
            ->assertOk()->assertJsonPath('pending', []);
    }

    public function test_gate_blocks_kyc_submit_for_stale_consent(): void
    {
        $user = $this->userWithConsentVersion('v1.0');

        $this->actingAs($user)->postJson('/api/profile/kyc', [])
            ->assertStatus(403)
            ->assertJsonPath('error', 'consent_required');
    }

    public function test_gate_blocks_financial_action_for_stale_consent(): void
    {
        // Consent runs before the KYC check, so a stale-consent user is stopped
        // here regardless of KYC status — no wallet/loan setup needed.
        $user = $this->userWithConsentVersion('v1.0');

        $this->actingAs($user)->postJson('/api/withdrawal', [])
            ->assertStatus(403)
            ->assertJsonPath('error', 'consent_required');
    }

    public function test_accept_records_consent_and_unblocks(): void
    {
        $user = $this->userWithConsentVersion('v1.0');

        $this->actingAs($user)->postJson('/api/consents/accept', [
            'types' => [ConsentRecord::TYPE_TERMS, ConsentRecord::TYPE_PRIVACY],
        ])->assertOk();

        $this->assertDatabaseHas('consent_records', [
            'user_id' => $user->id,
            'type' => ConsentRecord::TYPE_TERMS,
            'version' => ConsentRecord::CURRENT_TERMS_VERSION,
        ]);

        // Gate now passes — KYC submit reaches validation (422 for missing files)
        // instead of the consent 403.
        $this->actingAs($user)->postJson('/api/profile/kyc', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_front');
    }

    public function test_accept_requires_all_outstanding_documents(): void
    {
        $user = $this->userWithConsentVersion('v1.0');

        // Accepting only Terms while Privacy is also outstanding — rejected.
        $this->actingAs($user)->postJson('/api/consents/accept', [
            'types' => [ConsentRecord::TYPE_TERMS],
        ])->assertStatus(422)->assertJsonValidationErrors('types');
    }
}
