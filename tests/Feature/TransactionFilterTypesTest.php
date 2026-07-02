<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (audit 2026-07-02): the type filter allow-list covered only 6 of
 * the ledger's types, so filtering by buyback_* / early_repayment_* /
 * interest_* — rows the unfiltered list happily returns — was 422-rejected.
 */
class TransactionFilterTypesTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_every_ledger_type_is_accepted_by_the_filter(): void
    {
        $user = $this->investor();

        foreach (Transaction::TYPES as $type) {
            $this->actingAs($user)
                ->getJson('/api/transactions?type[]='.$type)
                ->assertOk();
        }
    }

    public function test_filtering_by_buyback_interest_returns_matching_rows(): void
    {
        $user = $this->investor();
        Transaction::factory()->create(['user_id' => $user->id, 'type' => Transaction::TYPE_BUYBACK_INTEREST, 'amount' => 10]);
        Transaction::factory()->create(['user_id' => $user->id, 'type' => Transaction::TYPE_DEPOSIT, 'amount' => 10]);

        $response = $this->actingAs($user)->getJson('/api/transactions?type[]=buyback_interest');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('buyback_interest', $rows[0]['type']);
    }

    public function test_unknown_type_is_still_rejected(): void
    {
        $this->actingAs($this->investor())
            ->getJson('/api/transactions?type[]=not_a_type')
            ->assertStatus(422);
    }
}
