<?php

namespace Tests\Feature;

use App\Models\BeneficialOwner;
use App\Models\LegalEntityProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalEntityRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(\Illuminate\Cache\RateLimiter::class)->clear('127.0.0.1');
    }

    /**
     * Minimal payload matching the simplified onboarding form: company name,
     * EIK, contact-person first/last name, email, phone, password, terms.
     *
     * EIK 201035515 is a real-world valid mod-11 checksum — don't replace
     * with random digits or ValidEik will reject the request.
     */
    private function validLegalEntityPayload(array $overrides = []): array
    {
        return array_merge([
            'account_type'           => 'legal_entity',
            'first_name'             => 'Иван',
            'last_name'              => 'Иванов',
            'email'                  => 'rep@vamaasset.bg',
            'phone'                  => '+359 88 123 4567',
            'password'               => 'Password123!',
            'password_confirmation'  => 'Password123!',
            'terms_accepted'         => true,
            'legal_name'             => 'ВАМА АСЕТ',
            'eik'                    => '201035515',
        ], $overrides);
    }

    public function test_legal_entity_registration_creates_user_and_profile_atomically(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload());

        $response->assertStatus(201);

        $user = User::where('email', 'rep@vamaasset.bg')->first();
        $this->assertNotNull($user);
        $this->assertEquals('legal_entity', $user->account_type);
        $this->assertTrue($user->isLegalEntity());
        $this->assertNotNull($user->wallet, 'Wallet must be created in the same transaction');

        // first_name + last_name concatenated into the user.name column by
        // RegisterRequest::prepareForValidation() — downstream code keeps
        // using $user->name without branching on account type.
        $this->assertEquals('Иван Иванов', $user->name);
        $this->assertEquals('+359 88 123 4567', $user->phone);

        $profile = $user->legalEntityProfile;
        $this->assertNotNull($profile);
        $this->assertEquals('ВАМА АСЕТ', $profile->legal_name);
        $this->assertEquals('201035515', $profile->eik); // decrypted via cast

        // AML data is captured in a deferred KYC workflow — at registration
        // these are NULL by design (the relax-columns migration allows it).
        $this->assertNull($profile->legal_form);
        $this->assertNull($profile->address_city);
        $this->assertNull($profile->source_of_funds);

        // No UBO records at registration; populated later in the deferred flow.
        $this->assertCount(0, $profile->beneficialOwners);
        $this->assertEquals(0, BeneficialOwner::count());

        // The 3 consent records are still recorded (legal-entity flow doesn't
        // bypass the universal consent-ledger requirement).
        $this->assertCount(3, $user->consentRecords);
    }

    public function test_legal_entity_registration_rejects_invalid_eik(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'eik' => '123456789', // wrong checksum
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['eik']);
        $this->assertDatabaseMissing('users', ['email' => 'rep@vamaasset.bg']);
    }

    public function test_legal_entity_registration_requires_first_and_last_name(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'first_name' => '',
            'last_name'  => '',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_legal_entity_registration_requires_phone(): void
    {
        $payload = $this->validLegalEntityPayload();
        unset($payload['phone']);

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_legal_entity_registration_requires_legal_name_and_eik(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'legal_name' => '',
            'eik'        => '',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['legal_name', 'eik']);
    }

    public function test_individual_registration_still_works_unchanged(): void
    {
        // Sanity: the single-name-field individual path is unaffected by the
        // legal-entity simplification.
        $response = $this->postJson('/api/register', [
            'account_type'          => 'individual',
            'name'                  => 'John Doe',
            'email'                 => 'john@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted'        => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('individual', $user->account_type);
        $this->assertTrue($user->isIndividual());
        $this->assertNull($user->legalEntityProfile);
        $this->assertEquals(0, LegalEntityProfile::count());
    }

    public function test_account_type_is_required(): void
    {
        // Backward-compat probe: a request without account_type returns 422
        // rather than silently defaulting. The frontend always sends it; this
        // test pins the requirement so we notice if we ever relax it.
        $response = $this->postJson('/api/register', [
            'name'                  => 'Legacy Client',
            'email'                 => 'legacy@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted'        => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['account_type']);
    }
}
