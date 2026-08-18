<?php

namespace Tests\Feature;

use App\Filament\Resources\BorrowerResource\Pages\ListBorrowers;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Borrower;
use App\Models\Favorite;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Newest-first ordering across the list surfaces (Reni 2026-08-18, follow-up
 * to the deposit list).
 *
 * Two distinct defects are pinned here:
 *
 *  1. Admin lists with no `defaultSort` — Filament falls back to `id` ASCENDING,
 *     so the newest user / borrower sat on the LAST page.
 *  2. Paginated endpoints ordered by a NON-UNIQUE key. MySQL gives no defined
 *     row order inside a tie, so LIMIT/OFFSET could hand the same row to two
 *     pages and never show another one. Ties are the norm, not the exception:
 *     one repayment writes principal + interest in the same second, and every
 *     loan on the board shares its term with a dozen others.
 */
class ListOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    /** @return array<int, int> */
    private function ids(array $payload): array
    {
        return array_column($payload, 'id');
    }

    // ── Admin lists ──

    public function test_users_list_starts_with_the_newest_registration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $oldest = User::factory()->create(['created_at' => Carbon::parse('2026-01-10 09:00:00')]);
        $newest = User::factory()->create(['created_at' => Carbon::parse('2026-08-17 09:00:00')]);

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$newest, $oldest], inOrder: true);
    }

    public function test_borrowers_list_starts_with_the_newest_record(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $oldest = Borrower::factory()->create(['created_at' => Carbon::parse('2026-01-10 09:00:00')]);
        $newest = Borrower::factory()->create(['created_at' => Carbon::parse('2026-08-17 09:00:00')]);

        Livewire::test(ListBorrowers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$newest, $oldest], inOrder: true);
    }

    // ── Paginated investor lists ──

    public function test_transaction_pages_never_repeat_or_drop_a_row_when_timestamps_tie(): void
    {
        $investor = $this->investor();

        // A frozen clock reproduces what the repayment/payout engines do:
        // a batch of ledger rows sharing one created_at to the second.
        Carbon::setTestNow('2026-08-17 10:00:00');
        $transactions = Transaction::factory()->count(25)->create(['user_id' => $investor->id]);
        Carbon::setTestNow();

        $expected = $transactions->pluck('id')->sortDesc()->values()->all();

        $page1 = $this->actingAs($investor)->getJson('/api/transactions')->assertOk();
        $page2 = $this->actingAs($investor)->getJson('/api/transactions?page=2')->assertOk();

        $this->assertSame(array_slice($expected, 0, 20), $this->ids($page1->json('data')));
        $this->assertSame(array_slice($expected, 20), $this->ids($page2->json('data')));
    }

    public function test_marketplace_pages_stay_stable_when_the_sort_key_ties(): void
    {
        $investor = $this->investor();

        // 20 loans, identical term — «най-кратък срок» has nothing left to
        // order by, which is exactly when paging used to break.
        $loans = Loan::factory()->count(20)->published()->create([
            'originator_id' => Originator::factory()->create()->id,
            'borrower_id' => Borrower::factory()->create()->id,
            'term_months' => 12,
        ]);

        $expected = $loans->pluck('id')->sortDesc()->values()->all();

        $page1 = $this->actingAs($investor)->getJson('/api/loans?sort=shortest_term')->assertOk();
        $page2 = $this->actingAs($investor)->getJson('/api/loans?sort=shortest_term&page=2')->assertOk();

        $returned = array_merge($this->ids($page1->json('data')), $this->ids($page2->json('data')));

        $this->assertSame($expected, $returned);
        $this->assertCount(20, array_unique($returned));
    }

    public function test_favorites_return_the_last_favorited_loan_first(): void
    {
        $investor = $this->investor();
        $originator = Originator::factory()->create();
        $borrower = Borrower::factory()->create();

        $loans = collect(['first', 'second', 'third'])->map(fn () => Loan::factory()->published()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
        ]));

        // Favorited out of creation order — the list must follow the click,
        // not the loan ids.
        foreach ([[1, '2026-08-10 09:00:00'], [0, '2026-08-16 09:00:00'], [2, '2026-08-01 09:00:00']] as [$index, $at]) {
            Carbon::setTestNow($at);
            Favorite::create(['user_id' => $investor->id, 'loan_id' => $loans[$index]->id]);
        }
        Carbon::setTestNow();

        $response = $this->actingAs($investor)->getJson('/api/loans/favorites')->assertOk();

        $this->assertSame(
            [$loans[0]->id, $loans[1]->id, $loans[2]->id],
            $this->ids($response->json('data')),
        );
    }

    public function test_portfolio_orders_same_second_positions_deterministically(): void
    {
        $investor = $this->investor();
        $loan = Loan::factory()->active()->create();

        Carbon::setTestNow('2026-08-17 10:00:00');
        $first = Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id]);
        $second = Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id]);
        Carbon::setTestNow();

        $response = $this->actingAs($investor)->getJson('/api/portfolio')->assertOk();

        $this->assertSame([$second->id, $first->id], $this->ids($response->json('data')));
    }

    public function test_withdrawal_history_orders_same_second_requests_deterministically(): void
    {
        $investor = $this->investor();

        Carbon::setTestNow('2026-08-17 10:00:00');
        $first = WithdrawalRequest::factory()->create(['user_id' => $investor->id]);
        $second = WithdrawalRequest::factory()->create(['user_id' => $investor->id]);
        Carbon::setTestNow();

        $response = $this->actingAs($investor)->getJson('/api/withdrawal/history')->assertOk();

        $this->assertSame([$second->id, $first->id], $this->ids($response->json('data')));
    }

    public function test_loan_events_order_same_second_cron_writes_deterministically(): void
    {
        // The timeline lives behind the `investor` middleware + LoanPolicy —
        // only someone holding a position in the loan may read it.
        $investor = $this->investor();
        $loan = Loan::factory()->active()->create();
        Investment::factory()->create(['user_id' => $investor->id, 'loan_id' => $loan->id]);

        $occurredAt = Carbon::parse('2026-08-17 03:30:00');
        $first = LoanEvent::create([
            'loan_id' => $loan->id,
            'event_type' => LoanEvent::TYPE_WENT_LATE,
            'from_status' => Loan::STATUS_ACTIVE,
            'to_status' => Loan::STATUS_LATE,
            'triggered_by' => 'system',
            'occurred_at' => $occurredAt,
        ]);
        $second = LoanEvent::create([
            'loan_id' => $loan->id,
            'event_type' => LoanEvent::TYPE_BUYBACK_TRIGGERED,
            'triggered_by' => 'system',
            'occurred_at' => $occurredAt,
        ]);

        $response = $this->actingAs($investor)->getJson("/api/loans/{$loan->id}/events")->assertOk();

        $this->assertSame([$second->id, $first->id], $this->ids($response->json('data')));
    }
}
