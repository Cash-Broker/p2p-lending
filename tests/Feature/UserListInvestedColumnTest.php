<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Инвестирано» column on the admin users list (boss 2026-08-10): the
 * invested wallet bucket per user, shown BEFORE the free balance so the
 * admin sees who has how much money deployed in the platform.
 */
class UserListInvestedColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_list_renders_the_invested_column(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));

        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $investor->wallet()->create()->forceFill(['invested' => '1234.56', 'available' => '100.00'])->save();

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanRenderTableColumn('wallet.invested')
            ->assertCanRenderTableColumn('wallet.available')
            ->assertCanSeeTableRecords([$investor]);
    }
}
