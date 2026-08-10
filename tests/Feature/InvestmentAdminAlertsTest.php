<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\User;
use App\Notifications\InvestmentMadeAdminNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin event alerts on every NEW investment (boss 2026-08-10): queued
 * email to all admins + panel bell + Telegram record, fired AFTER the
 * money committed, and exactly once per investment (idempotent replays
 * must not re-alert).
 */
class InvestmentAdminAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    public function test_all_admins_are_alerted_on_a_new_investment(): void
    {
        Notification::fake();
        $adminA = User::factory()->admin()->create(['email_verified_at' => now()]);
        $adminB = User::factory()->admin()->create(['email_verified_at' => now()]);
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();

        $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => 300,
            'loan_offer_id' => $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id'),
        ], ['X-Idempotency-Key' => 'alert-'.uniqid()])->assertStatus(201);

        Notification::assertSentTo($adminA, InvestmentMadeAdminNotification::class);
        Notification::assertSentTo($adminB, InvestmentMadeAdminNotification::class);
        // The investor gets no admin alert.
        Notification::assertNotSentTo($user, InvestmentMadeAdminNotification::class);
    }

    public function test_idempotent_replay_does_not_realert(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $key = 'alert-idem-key';
        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
                'amount' => 100,
                'loan_offer_id' => $offerId,
            ], ['X-Idempotency-Key' => $key])->assertStatus(201);
        }

        Notification::assertSentToTimes($admin, InvestmentMadeAdminNotification::class, 1);
    }
}
