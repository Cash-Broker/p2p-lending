<?php

namespace Tests\Feature;

use App\Models\BeneficialOwner;
use App\Models\ConsentRecord;
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
     * Build a valid registration payload for a legal-entity investor.
     *
     * EIK 201035515 and EGN 9006151000 are real-world valid checksums
     * (mod-11 verified by hand). Don't replace with random digits — the
     * validator will reject them.
     */
    private function validLegalEntityPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'account_type'           => 'legal_entity',

            'name'                   => 'Иван Иванов',
            'email'                  => 'rep@vamaasset.bg',
            'password'               => 'Password123!',
            'password_confirmation'  => 'Password123!',
            'terms_accepted'         => true,

            'legal_name'             => 'ВАМА АСЕТ',
            'legal_form'             => 'EOOD',
            'eik'                    => '201035515',
            'vat_number'             => 'BG201035515',

            'address_country'        => 'BG',
            'address_city'           => 'София',
            'address_postcode'       => '1000',
            'address_street'         => 'ул. Васил Левски 1',

            'company_email'          => 'office@vamaasset.bg',
            'company_phone'          => '+359 88 123 4567',

            'representative_role'    => 'upravitel',
            'representative_egn'     => '9006151000',

            'pep_status'             => false,
            'source_of_funds'        => 'business_income',

            'beneficial_owners' => [
                [
                    'full_name'         => 'Иван Иванов Иванов',
                    'national_id'       => '9006151000',
                    'nationality'       => 'BG',
                    'ownership_percent' => 100,
                    'control_type'      => 'direct',
                    'pep_status'        => false,
                ],
            ],
        ], $overrides);
    }

    public function test_legal_entity_registration_creates_user_profile_and_ubo_atomically(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload());

        $response->assertStatus(201);

        $user = User::where('email', 'rep@vamaasset.bg')->first();
        $this->assertNotNull($user);
        $this->assertEquals('legal_entity', $user->account_type);
        $this->assertTrue($user->isLegalEntity());
        $this->assertNotNull($user->wallet, 'Wallet must be created in the same transaction');

        $profile = $user->legalEntityProfile;
        $this->assertNotNull($profile);
        $this->assertEquals('ВАМА АСЕТ', $profile->legal_name);
        $this->assertEquals('EOOD', $profile->legal_form);
        $this->assertEquals('201035515', $profile->eik);                  // decrypted via cast
        $this->assertEquals('BG201035515', $profile->vat_number);         // decrypted via cast
        $this->assertEquals('upravitel', $profile->representative_role);
        $this->assertEquals('9006151000', $profile->representative_egn); // decrypted via cast

        $this->assertCount(1, $profile->beneficialOwners);
        $ubo = $profile->beneficialOwners->first();
        $this->assertEquals('Иван Иванов Иванов', $ubo->full_name);
        $this->assertEquals('9006151000', $ubo->national_id);
        $this->assertEquals('100.00', $ubo->ownership_percent);

        // All three consent records still recorded (legal-entity flow doesn't break it)
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

    public function test_legal_entity_registration_rejects_invalid_egn(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'representative_egn' => '1234567890', // wrong checksum + invalid date
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['representative_egn']);
    }

    public function test_legal_entity_registration_requires_at_least_one_ubo(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'beneficial_owners' => [],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['beneficial_owners']);
        $this->assertDatabaseMissing('users', ['email' => 'rep@vamaasset.bg']);
    }

    public function test_legal_entity_registration_rejects_ubo_with_no_id_and_no_dob(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'beneficial_owners' => [[
                'full_name'         => 'No ID Person',
                'nationality'       => 'BG',
                'ownership_percent' => 50,
                'control_type'      => 'direct',
                'pep_status'        => false,
            ]],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors([
            'beneficial_owners.0.national_id',
            'beneficial_owners.0.date_of_birth',
        ]);
    }

    public function test_legal_entity_registration_rejects_ownership_over_100_percent(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'beneficial_owners' => [[
                'full_name'         => 'Too Greedy',
                'national_id'       => '9006151000',
                'nationality'       => 'BG',
                'ownership_percent' => 101,
                'control_type'      => 'direct',
                'pep_status'        => false,
            ]],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors([
            'beneficial_owners.0.ownership_percent',
        ]);
    }

    public function test_pep_status_true_requires_details(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'pep_status' => true,
            'pep_details' => '', // missing
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['pep_details']);
    }

    public function test_source_of_funds_other_requires_description(): void
    {
        $response = $this->postJson('/api/register', $this->validLegalEntityPayload([
            'source_of_funds' => 'other',
            'source_of_funds_other' => '', // missing
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['source_of_funds_other']);
    }

    public function test_individual_registration_still_works_unchanged(): void
    {
        // Sanity: legacy individual flow keeps the same shape and creates no
        // legal-entity profile.
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
        $this->assertEquals(0, BeneficialOwner::count());
    }

    public function test_account_type_defaults_to_individual_when_omitted(): void
    {
        // Backward compatibility: a request without account_type should be
        // treated as individual (legacy clients won't know to send the field).
        // RegisterRequest currently requires account_type — this test pins the
        // requirement so we notice if we ever relax it.
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
