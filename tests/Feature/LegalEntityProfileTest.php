<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalEntityProfileTest extends TestCase
{
    use RefreshDatabase;

    private function legalEntityUser(array $profile = []): User
    {
        $user = User::factory()->create([
            'account_type' => User::TYPE_LEGAL_ENTITY,
            'email_verified_at' => now(),
        ]);
        $user->wallet()->create();
        $user->legalEntityProfile()->create(array_merge([
            'legal_name' => 'ВАМА АСЕТ ЕООД',
            'eik' => '201035515',
        ], $profile));

        return $user;
    }

    private function individualUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    // ── Reading company data ──

    public function test_profile_exposes_company_data_for_legal_entity(): void
    {
        $user = $this->legalEntityUser(['legal_form' => 'EOOD', 'address_city' => 'София']);

        $response = $this->actingAs($user)->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('is_legal_entity', true)
            ->assertJsonPath('legal_entity_profile.legal_name', 'ВАМА АСЕТ ЕООД')
            ->assertJsonPath('legal_entity_profile.eik', '201035515') // decrypted via cast
            ->assertJsonPath('legal_entity_profile.legal_form', 'EOOD')
            ->assertJsonPath('legal_entity_profile.address_city', 'София');
    }

    public function test_individual_profile_has_no_company_data(): void
    {
        $user = $this->individualUser();

        $response = $this->actingAs($user)->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('is_legal_entity', false)
            ->assertJsonMissingPath('legal_entity_profile');
    }

    // ── Updating company data ──

    public function test_legal_entity_can_update_editable_company_fields(): void
    {
        $user = $this->legalEntityUser();

        $response = $this->actingAs($user)->putJson('/api/profile/company', [
            'legal_form' => 'OOD',
            'address_country' => 'BG',
            'address_city' => 'Пловдив',
            'address_postcode' => '4000',
            'address_street' => 'ул. Главна 1',
            'company_email' => 'office@vama.bg',
            'company_phone' => '+359888112233',
        ]);

        // PUT response wraps the user under a `user` key (unlike GET /profile).
        $response->assertOk()
            ->assertJsonPath('user.legal_entity_profile.address_city', 'Пловдив');

        $profile = $user->legalEntityProfile()->first();
        $this->assertSame('OOD', $profile->legal_form);
        $this->assertSame('Пловдив', $profile->address_city);
        $this->assertSame('office@vama.bg', $profile->company_email);
    }

    public function test_company_update_cannot_change_identity_fields(): void
    {
        $user = $this->legalEntityUser();

        // Attempt to overwrite the locked identity along with a legit field.
        $this->actingAs($user)->putJson('/api/profile/company', [
            'legal_name' => 'ПОДМЕНЕНА ФИРМА',
            'eik' => '831641791',
            'address_city' => 'Варна',
        ])->assertOk();

        $profile = $user->legalEntityProfile()->first();
        // Identity untouched, editable field applied.
        $this->assertSame('ВАМА АСЕТ ЕООД', $profile->legal_name);
        $this->assertSame('201035515', $profile->eik);
        $this->assertSame('Варна', $profile->address_city);
    }

    public function test_individual_cannot_update_company_profile(): void
    {
        $user = $this->individualUser();

        $this->actingAs($user)->putJson('/api/profile/company', [
            'address_city' => 'София',
        ])->assertStatus(403);
    }

    public function test_company_update_rejects_invalid_legal_form(): void
    {
        $user = $this->legalEntityUser();

        $this->actingAs($user)->putJson('/api/profile/company', [
            'legal_form' => 'NOT_A_FORM',
        ])->assertStatus(422)->assertJsonValidationErrors('legal_form');
    }

    public function test_company_update_normalises_bare_eik_into_vat_number(): void
    {
        $user = $this->legalEntityUser();

        // User types only the EIK; the request auto-prefixes "BG" and ValidVat
        // accepts it (checksum delegated to ValidEik).
        $this->actingAs($user)->putJson('/api/profile/company', [
            'vat_number' => '201035515',
        ])->assertOk();

        $this->assertSame('BG201035515', $user->legalEntityProfile()->first()->vat_number);
    }

    public function test_company_update_rejects_invalid_vat_number(): void
    {
        $user = $this->legalEntityUser();

        $this->actingAs($user)->putJson('/api/profile/company', [
            'vat_number' => 'BG123456789', // wrong checksum
        ])->assertStatus(422)->assertJsonValidationErrors('vat_number');
    }
}
