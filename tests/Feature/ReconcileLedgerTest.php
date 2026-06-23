<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileLedger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards ledger:reconcile against the audit's HIGH finding: the command
 * ignored BUYBACK_* and EARLY_REPAYMENT_* transaction types, so the FIRST
 * legitimate buyback/early-repayment produced a guaranteed false FAILURE and
 * trained operators to ignore the only automated double-payout detector.
 */
class ReconcileLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWallet(): User
    {
        $user = User::factory()->kycApproved()->create();
        $user->wallet()->create();

        return $user;
    }

    /** Every Transaction::TYPES constant must have a reconciliation rule. */
    public function test_ledger_map_covers_every_transaction_type(): void
    {
        $map = (new ReflectionClass(ReconcileLedger::class))
            ->getConstant('LEDGER_MAP');

        $unmapped = array_diff(Transaction::TYPES, array_keys($map));

        $this->assertSame(
            [],
            array_values($unmapped),
            'Every TYPE_* constant must be mapped in ReconcileLedger::LEDGER_MAP, '
            . 'otherwise its money is silently dropped from reconciliation.'
        );
    }

    public function test_full_buyback_lifecycle_reconciles_to_zero(): void
    {
        $wallet = app(WalletService::class);
        $user = $this->userWithWallet();

        // deposit → invest → originator buyback (principal + interest)
        $wallet->credit($user->id, '1000.00', Transaction::TYPE_DEPOSIT, 'deposit');
        $wallet->invest($user->id, '1000.00', 'invest in loan #1', 'loan:1:investment:1');
        $wallet->buybackPrincipal($user->id, '1000.00', 'buyback principal loan #1', 'loan:1:investment:1');
        $wallet->buybackInterest($user->id, '60.00', 'buyback interest loan #1', 'loan:1:investment:1');

        $w = $user->wallet->fresh();
        $this->assertEquals('1060.00', $w->available);
        $this->assertEquals('0.00', $w->invested);
        $this->assertEquals('60.00', $w->earned);

        $this->assertSame(
            0,
            Artisan::call('ledger:reconcile'),
            'A correct buyback lifecycle must reconcile cleanly (exit 0).'
        );
    }

    public function test_full_early_repayment_lifecycle_reconciles_to_zero(): void
    {
        $wallet = app(WalletService::class);
        $user = $this->userWithWallet();

        $wallet->credit($user->id, '500.00', Transaction::TYPE_DEPOSIT, 'deposit');
        $wallet->invest($user->id, '500.00', 'invest in loan #2', 'loan:2:investment:9');
        $wallet->earlyRepayPrincipal($user->id, '500.00', 'early principal loan #2', 'loan:2:investment:9');
        $wallet->earlyRepayInterest($user->id, '25.00', 'early interest loan #2', 'loan:2:investment:9');

        $w = $user->wallet->fresh();
        $this->assertEquals('525.00', $w->available);
        $this->assertEquals('0.00', $w->invested);
        $this->assertEquals('25.00', $w->earned);

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_mixed_repayment_and_buyback_reconciles(): void
    {
        $wallet = app(WalletService::class);
        $user = $this->userWithWallet();

        $wallet->credit($user->id, '1000.00', Transaction::TYPE_DEPOSIT, 'deposit');
        $wallet->invest($user->id, '1000.00', 'invest', 'loan:3:investment:3');
        // Partial scheduled repayment, then originator buys back the rest.
        $wallet->repayPrincipal($user->id, '400.00', 'repay principal', 'loan:3:investment:3');
        $wallet->repayInterest($user->id, '30.00', 'repay interest', 'loan:3:investment:3');
        $wallet->buybackPrincipal($user->id, '600.00', 'buyback principal', 'loan:3:investment:3');
        $wallet->buybackInterest($user->id, '45.00', 'buyback interest', 'loan:3:investment:3');

        $w = $user->wallet->fresh();
        $this->assertEquals('1075.00', $w->available); // 1000 -1000 +400 +30 +600 +45
        $this->assertEquals('0.00', $w->invested);
        $this->assertEquals('75.00', $w->earned);

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_unmapped_transaction_type_fails_loudly(): void
    {
        $user = $this->userWithWallet();

        // A persisted type with no reconciliation rule must hard-fail the
        // command rather than be silently dropped from the sums.
        Transaction::create([
            'user_id' => $user->id,
            'type' => 'mystery_movement',
            'amount' => '5.00',
            'description' => 'unmapped type',
        ]);

        $this->assertSame(
            ReconcileLedger::FAILURE,
            Artisan::call('ledger:reconcile'),
            'An unmapped transaction type must fail reconciliation, not be ignored.'
        );
    }
}
