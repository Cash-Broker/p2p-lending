<?php

namespace Tests\Feature;

use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Тегления» register carries BOTH dates (Reni 2026-08-18): the request
 * date it is sorted by — the list is a work queue, pending on top — and
 * «Изплатен на», the moment the money actually left the wallet, which until
 * now lived only inside the record.
 */
class WithdrawalAdminListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_paid_out_withdrawals_show_the_payout_time_in_sofia_time(): void
    {
        if (config('app.timezone') !== 'UTC') {
            $this->markTestSkipped('Expected offset is pinned to a UTC app timezone.');
        }

        WithdrawalRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'processed',
            'created_at' => Carbon::parse('2026-08-16 07:00:00', 'UTC'),
            'processed_at' => Carbon::parse('2026-08-17 12:20:00', 'UTC'),
        ]);

        // 12:20 UTC = 15:20 in Sofia (EEST).
        Livewire::test(ListWithdrawalRequests::class)
            ->assertOk()
            ->assertSee('17.08.2026 15:20');
    }

    public function test_pending_withdrawals_show_no_payout_time(): void
    {
        WithdrawalRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'processed_at' => null,
        ]);

        Livewire::test(ListWithdrawalRequests::class)
            ->assertOk()
            ->assertSee('Изплатен на')
            ->assertSee('—');
    }

    public function test_newest_request_stays_on_top(): void
    {
        $older = WithdrawalRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'created_at' => Carbon::parse('2026-08-10 09:00:00', 'UTC'),
        ]);
        $newer = WithdrawalRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'created_at' => Carbon::parse('2026-08-17 09:00:00', 'UTC'),
        ]);

        Livewire::test(ListWithdrawalRequests::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }
}
