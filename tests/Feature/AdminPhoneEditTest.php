<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Редактирай телефон» (2026-08-25, ships with the mandatory-phone rule):
 * the only investor-side write path for the now-mandatory phone is the
 * self-service PUT /api/profile, so support corrections (an investor stuck
 * at the blocking modal, a phoned-in change) need an admin tool. The action
 * applies the same ValidPhone rule as the API.
 */
class AdminPhoneEditTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_sets_phone_for_investor(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['email_verified_at' => now(), 'phone' => null]);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('edit_phone', data: ['phone' => '+359 88 700 8899'])
            ->assertHasNoActionErrors();

        $this->assertSame('+359 88 700 8899', $user->fresh()->phone);
    }

    public function test_admin_phone_edit_applies_the_same_format_rule_as_the_api(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['email_verified_at' => now(), 'phone' => '+359888123456']);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('edit_phone', data: ['phone' => 'вътрешен 12'])
            ->assertHasActionErrors(['phone']);

        // The stored number must survive a rejected edit.
        $this->assertSame('+359888123456', $user->fresh()->phone);
    }

    public function test_admin_phone_edit_requires_a_value(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['email_verified_at' => now(), 'phone' => '+359888123456']);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('edit_phone', data: ['phone' => ''])
            ->assertHasActionErrors(['phone']);

        $this->assertSame('+359888123456', $user->fresh()->phone);
    }
}
