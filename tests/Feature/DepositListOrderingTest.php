<?php

namespace Tests\Feature;

use App\Filament\Resources\DepositRequestResource\Pages\ListDepositRequests;
use App\Models\DepositRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deposits are listed by the moment they were APPROVED, not by the moment the
 * DEP code was issued (Reni 2026-08-18: «одобрените депозити да излизат по час
 * и дата когато са били одобрени, сега са много разбъркани»).
 *
 * The two dates drift apart by design: a code is minted the first time the
 * investor opens the deposit page and stays valid until an admin consumes it
 * (client decision 2026-07-17, no expiry). So a June code credited in August
 * used to sit at its June position with a June date next to an August credit.
 */
class DepositListOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * A deposit row with hand-placed timestamps. `created_at` is not fillable
     * and `confirmed_at` is only ever stamped by DepositService::approve, so
     * both are force-filled after creation.
     */
    private function deposit(
        string $createdAt,
        ?string $confirmedAt,
        string $status = 'approved',
        ?User $user = null,
    ): DepositRequest {
        $deposit = DepositRequest::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'amount' => '1000.00',
            'status' => $status,
        ]);

        $deposit->forceFill([
            'created_at' => Carbon::parse($createdAt, 'UTC'),
            'confirmed_at' => $confirmedAt ? Carbon::parse($confirmedAt, 'UTC') : null,
        ])->save();

        return $deposit;
    }

    // ── Admin list ──

    public function test_admin_list_orders_approved_deposits_by_approval_time_not_by_code_issuance(): void
    {
        $this->actingAs($this->admin());

        // Old code, credited yesterday — must come FIRST.
        $oldCodeRecentlyApproved = $this->deposit('2026-06-23 14:03:00', '2026-08-17 09:10:00');
        // Fresh code, credited last week — must come SECOND.
        $newCodeApprovedEarlier = $this->deposit('2026-08-13 12:30:00', '2026-08-13 15:54:00');

        Livewire::test(ListDepositRequests::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$oldCodeRecentlyApproved, $newCodeApprovedEarlier], inOrder: true);
    }

    public function test_admin_list_shows_the_approval_date_not_the_code_issuance_date(): void
    {
        $this->actingAs($this->admin());

        $this->deposit('2026-06-23 14:03:00', '2026-08-13 15:54:00');

        Livewire::test(ListDepositRequests::class)
            ->assertSee('13.08.2026')
            ->assertDontSee('23.06.2026');
    }

    public function test_admin_list_renders_the_approval_hour_in_sofia_time(): void
    {
        if (config('app.timezone') !== 'UTC') {
            $this->markTestSkipped('Expected offset is pinned to a UTC app timezone.');
        }

        $this->actingAs($this->admin());

        // 15:54 UTC on 13.08 is 18:54 in Sofia (EEST, UTC+3) — the hour the
        // admin actually approved the wire.
        $this->deposit('2026-08-13 12:30:00', '2026-08-13 15:54:00');

        Livewire::test(ListDepositRequests::class)
            ->assertSee('13.08.2026 18:54')
            ->assertDontSee('13.08.2026 15:54');
    }

    public function test_clicking_the_date_column_flips_the_order_by_approval_time(): void
    {
        $this->actingAs($this->admin());

        $oldCodeRecentlyApproved = $this->deposit('2026-06-23 14:03:00', '2026-08-17 09:10:00');
        $newCodeApprovedEarlier = $this->deposit('2026-08-13 12:30:00', '2026-08-13 15:54:00');

        Livewire::test(ListDepositRequests::class)
            ->sortTable('confirmed_at', 'asc')
            ->assertCanSeeTableRecords([$newCodeApprovedEarlier, $oldCodeRecentlyApproved], inOrder: true);
    }

    public function test_rows_without_an_approval_timestamp_keep_their_request_date(): void
    {
        $this->actingAs($this->admin());

        // Rejected deposits are never confirmed — they fall back to the date
        // the investor asked for the code, and sort on that.
        $rejected = $this->deposit('2026-08-16 08:00:00', null, 'rejected');
        $approvedEarlier = $this->deposit('2026-08-01 08:00:00', '2026-08-10 08:00:00');

        Livewire::test(ListDepositRequests::class)
            ->assertCanSeeTableRecords([$rejected, $approvedEarlier], inOrder: true)
            ->assertSee('16.08.2026');
    }

    // ── Investor history ──

    public function test_investor_history_returns_deposits_newest_approval_first(): void
    {
        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();

        $oldCodeRecentlyApproved = $this->deposit('2026-06-23 14:03:00', '2026-08-17 09:10:00', user: $investor);
        $newCodeApprovedEarlier = $this->deposit('2026-08-13 12:30:00', '2026-08-13 15:54:00', user: $investor);

        $response = $this->actingAs($investor)->getJson('/api/deposit/history');

        $response->assertOk();
        $this->assertSame(
            [$oldCodeRecentlyApproved->id, $newCodeApprovedEarlier->id],
            array_column($response->json('data'), 'id'),
        );
    }
}
