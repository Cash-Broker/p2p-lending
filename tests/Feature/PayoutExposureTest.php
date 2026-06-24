<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\PlatformMetric;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PayoutExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_exposure_command_runs_and_records_metrics(): void
    {
        // A wallet carrying locked accrued profit = the platform's promise.
        $user = User::factory()->create();
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '100.00', Transaction::TYPE_DEPOSIT, 'seed');
        app(WalletService::class)->accrueInterest($user->id, '12.34', 'accrual', 'loan:1:investment:1:capitalized');

        // A late loan in automatic mode = an at-risk auto payout.
        $originator = Originator::factory()->create();
        $borrower = Borrower::factory()->create();
        Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => Loan::STATUS_LATE,
            'payout_mode' => Loan::PAYOUT_MODE_AUTOMATIC,
        ]);

        $this->assertSame(0, Artisan::call('payouts:exposure', ['--record' => true]));

        $this->assertSame('12.34', PlatformMetric::read('payout_exposure_locked_promise'));
        $this->assertSame('1', PlatformMetric::read('payout_exposure_at_risk_loans'));
    }
}
