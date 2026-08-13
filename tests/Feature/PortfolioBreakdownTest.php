<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Boss requirement: the investor must ALWAYS be able to see each installment's
 * breakdown (вноска / лихва / главница). The portfolio surfaces the per-
 * investment schedule for offer-based positions.
 */
class PortfolioBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_position_exposes_per_installment_breakdown(): void
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');

        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);
        // Пълното финансиране вече активира само (2026-08-13) — това остава
        // само за случаите, в които кредитът е докаран до `funded` ръчно.
        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        $response = $this->actingAs($user)->getJson('/api/portfolio');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'payout_label', 'schedule' => [['due_date', 'principal', 'interest', 'total', 'status']]],
                ],
            ]);

        $schedule = collect($response->json('data'))->first()['schedule'];
        $this->assertCount(12, $schedule, 'amortizing plan → 12 installment rows visible');

        // Every row shows principal + interest summing to its total.
        foreach ($schedule as $row) {
            $this->assertSame(
                bcadd($row['principal'], $row['interest'], 2),
                $row['total'],
                'each row must show принципал + лихва = вноска',
            );
        }

        // Σ principal across the breakdown == the invested stake.
        $sumPrincipal = array_reduce($schedule, fn ($c, $r) => bcadd($c, $r['principal'], 2), '0.00');
        $this->assertSame('1000.00', $sumPrincipal);
    }
}
