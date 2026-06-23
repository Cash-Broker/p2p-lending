<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AmortizationService;
use App\Services\RepaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The invariant the audit said was structurally uncatchable before the fix:
 *
 *   For EVERY investor, Σ(principal returned across all installments)
 *   == their invested EXACTLY, and wallet.invested == 0 at full repayment,
 *   with ZERO tolerance — across arbitrary investor counts, terms and splits.
 *
 * This is what guarantees the legacy pro-rata path no longer drifts or trips
 * the (now-hardened) WalletService underflow guard. If the distribution ever
 * over-returns, the guard throws and this test fails loudly.
 */
class PerInvestorConservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function investor(string $invested): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        $user->wallet->forceFill(['available' => '0.00', 'invested' => $invested, 'earned' => '0.00'])->save();

        return $user;
    }

    /** Split a funded amount across n investors (floor each, last gets remainder). */
    private function splitEvenly(string $funded, int $n): array
    {
        $each = bcdiv($funded, (string) $n, 2);
        $shares = array_fill(0, $n - 1, $each);
        $shares[] = bcsub($funded, bcmul($each, (string) ($n - 1), 2), 2);

        return $shares;
    }

    private function buildActiveLoan(string $funded, int $term, int $investorCount): array
    {
        $loan = Loan::factory()->active()->create([
            'amount' => $funded,
            'funded_amount' => $funded,
            'investable_amount' => $funded,
            'interest_rate' => '12.00',
            'interest_rate_annual' => '14.00',
            'term_months' => $term,
        ]);

        $investors = [];
        foreach ($this->splitEvenly($funded, $investorCount) as $share) {
            $u = $this->investor($share);
            Investment::factory()->create(['user_id' => $u->id, 'loan_id' => $loan->id, 'amount' => $share]);
            $investors[$u->id] = $share;
        }

        // Real annuity schedule whose principals sum to funded exactly.
        app(AmortizationService::class)->generateSchedule($loan);

        return [$loan, $investors];
    }

    public function test_per_investor_principal_conservation_across_shapes(): void
    {
        $service = app(RepaymentService::class);

        $cases = [
            // [funded, term, investorCount]
            ['1000.00', 1, 1],
            ['1000.00', 3, 2],
            ['1000.00', 12, 3],
            ['1000.00', 24, 7],
            ['1000.00', 12, 11],
            ['7777.77', 24, 3],
            ['543.21', 6, 5],
            ['10000.00', 36, 9],
        ];

        foreach ($cases as [$funded, $term, $n]) {
            [$loan, $investors] = $this->buildActiveLoan($funded, $term, $n);

            foreach ($loan->amortizationSchedules()->orderBy('due_date')->orderBy('id')->get() as $s) {
                $service->processRepayment($loan->id, $s->id);
            }

            $label = "funded={$funded} term={$term} investors={$n}";

            foreach ($investors as $uid => $invested) {
                $returned = Transaction::where('user_id', $uid)
                    ->where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)
                    ->where('reference', 'like', "loan:{$loan->id}:%")
                    ->sum('amount');

                $this->assertSame(
                    0,
                    bccomp($invested, number_format((float) $returned, 2, '.', ''), 2),
                    "[$label] investor #{$uid} returned {$returned}, expected exactly {$invested}"
                );

                $wallet = User::find($uid)->wallet->fresh();
                $this->assertEquals('0.00', (string) $wallet->invested,
                    "[$label] investor #{$uid} invested must be exactly 0 after full repayment");
            }

            // Global: Σ principal returned == funded, Σ interest == Σ schedule interest.
            $totalPrincipal = Transaction::where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)
                ->where('reference', 'like', "loan:{$loan->id}:%")->sum('amount');
            $this->assertSame(
                0,
                bccomp($funded, number_format((float) $totalPrincipal, 2, '.', ''), 2),
                "[$label] Σ principal returned must equal funded {$funded}"
            );

            $scheduleInterest = (string) $loan->amortizationSchedules()->sum('interest');
            $totalInterest = Transaction::where('type', Transaction::TYPE_REPAYMENT_INTEREST)
                ->where('reference', 'like', "loan:{$loan->id}:%")->sum('amount');
            $this->assertSame(
                0,
                bccomp(
                    number_format((float) $scheduleInterest, 2, '.', ''),
                    number_format((float) $totalInterest, 2, '.', ''),
                    2
                ),
                "[$label] Σ interest distributed must equal scheduled interest"
            );
        }
    }

    public function test_audit_repro_seven_equal_investors_sixty_installments(): void
    {
        // The audit's exact cumulative-drift repro: 7 equal investors, 7000
        // funded, 60 installments. Previously stranded ~1.98 EUR and tripped
        // the clamp. Now: every investor returned exactly 1000.00, invested 0.
        $service = app(RepaymentService::class);
        [$loan, $investors] = $this->buildActiveLoan('7000.00', 60, 7);

        foreach ($loan->amortizationSchedules()->orderBy('due_date')->orderBy('id')->get() as $s) {
            $service->processRepayment($loan->id, $s->id);
        }

        foreach ($investors as $uid => $invested) {
            $returned = Transaction::where('user_id', $uid)
                ->where('type', Transaction::TYPE_REPAYMENT_PRINCIPAL)
                ->where('reference', 'like', "loan:{$loan->id}:%")
                ->sum('amount');
            $this->assertSame('1000.00', number_format((float) $returned, 2, '.', ''),
                "investor #{$uid} must be returned exactly 1000.00");
            $this->assertEquals('0.00', (string) User::find($uid)->wallet->fresh()->invested);
        }
    }
}
