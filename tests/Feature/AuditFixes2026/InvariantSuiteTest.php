<?php

namespace Tests\Feature\AuditFixes2026;

use App\Enums\PayoutType;
use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\Loans\BorrowerPlanService;
use App\Services\Loans\BuybackExecutionService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\Loans\LateDetectionService;
use App\Services\Loans\LoanStatusUpdaterService;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * Audit 2026-09-01, package A5 — invariants that had no test of their own:
 * admin double-clicks on money screens, cross-engine sequences, and two
 * architecture rules from CLAUDE.md («never float for money», «every wallet
 * move goes through WalletService») as executable checks.
 */
class InvariantSuiteTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createVerifiedInvestor(array $walletOverrides = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletOverrides) {
            $wallet->forceFill($walletOverrides)->save();
        }

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array{0: Loan, 1: array<int, User>} */
    private function offerLoan(PayoutType $type, string $rate, array $stakes = ['1000.00']): array
    {
        Notification::fake();
        $total = array_reduce($stakes, fn ($carry, $stake) => bcadd($carry, $stake, 2), '0.00');

        $loan = Loan::factory()->published()->create([
            'amount' => $total, 'investable_amount' => $total, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $loan->offers()->where('payout_type', $type)->update(['interest_rate' => $rate]);
        $offerId = $loan->offers()->where('payout_type', $type)->value('id');

        $investors = [];
        foreach ($stakes as $stake) {
            $user = $this->createVerifiedInvestor();
            app(WalletService::class)->credit($user->id, bcadd($stake, '100.00', 2), Transaction::TYPE_DEPOSIT, 'seed');
            app(InvestmentService::class)->invest($user, $loan->fresh(), $stake, (string) Str::uuid(), $offerId);
            $investors[] = $user;
        }

        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $investors];
    }

    private function forceLate(Loan $loan): void
    {
        $loan->fresh()->transitionTo(Loan::STATUS_LATE);
        $loan->forceFill(['became_late_at' => now()->subDays(70)])->save();
    }

    private function principalReturnedTo(User $user): string
    {
        return Transaction::where('user_id', $user->id)
            ->whereIn('type', [Transaction::TYPE_REPAYMENT_PRINCIPAL, Transaction::TYPE_BUYBACK_PRINCIPAL, Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL])
            ->get(['amount'])
            ->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->amount, 2), '0.00');
    }

    // ── Admin money screens survive a double click / a second admin ──

    public function test_withdrawal_actions_on_a_row_another_admin_handled_meanwhile_are_no_ops_and_money_moves_once(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin);
        $user = $this->createVerifiedInvestor(['available' => '500.00']);
        $service = app(WithdrawalService::class);
        $withdrawal = $service->createRequest($user->id, '100.00', $this->confirmedIban($user));

        // The confirmation modal is open on a PENDING row; another admin approves
        // first. Filament re-resolves the row on submit, the action is no longer
        // visible, nothing runs — no second debit, no success toast.
        $component = Livewire::test(ListWithdrawalRequests::class)->mountTableAction('approve', $withdrawal);
        $service->approve($withdrawal->id, $this->admin()->id);
        $component->callMountedTableAction()->assertNotNotified('Теглене одобрено');

        // The service itself refuses a second approval under the row lock.
        try {
            $service->approve($withdrawal->id, $admin->id);
            $this->fail('a second approval must not find a pending row');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // Reject on the now-approved row: the action is gone, nothing is released.
        Livewire::test(ListWithdrawalRequests::class)
            ->mountTableAction('reject', $withdrawal->fresh())
            ->callMountedTableAction()
            ->assertNotNotified('Теглене отхвърлено');
        $this->assertSame('approved', $withdrawal->fresh()->status);

        // Mark processed twice: the second click finds no `approved` row.
        Livewire::test(ListWithdrawalRequests::class)->callTableAction('mark_processed', $withdrawal->fresh())->assertNotified('Обработено');
        Livewire::test(ListWithdrawalRequests::class)
            ->mountTableAction('mark_processed', $withdrawal->fresh())
            ->callMountedTableAction()
            ->assertNotNotified('Обработено');

        $this->assertSame('processed', $withdrawal->fresh()->status);
        $this->assertSame(1, Transaction::where('user_id', $user->id)->where('type', Transaction::TYPE_WITHDRAWAL)->count(), 'the withdrawal debit happened exactly once');
        $wallet = $user->wallet->fresh();
        $this->assertSame('400.00', (string) $wallet->available);
        $this->assertSame('0.00', (string) $wallet->reserved);
    }

    // ── Cross-engine sequences ──

    public function test_partial_closure_followed_by_buyback_returns_exactly_the_invested_principal(): void
    {
        [$loan, [$a, $b]] = $this->offerLoan(PayoutType::Amortizing, '12.00', ['1000.00', '600.00']);
        $loan->originator->update(['buyback' => true, 'buyback_coverage' => 'principal_plus_interest']);

        Carbon::setTestNow(Carbon::now()->addDays(20));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '480.00'); // 30 % of 1 600
        Carbon::setTestNow();

        $this->forceLate($loan);
        app(BuybackExecutionService::class)->execute($loan->id, $this->admin()->id);

        $this->assertSame(Loan::STATUS_BOUGHT_BACK, $loan->fresh()->status);
        foreach ([[$a, '1000.00'], [$b, '600.00']] as [$investor, $stake]) {
            $this->assertSame($stake, $this->principalReturnedTo($investor), 'conservation: principal back == principal in, across two engines');
            $this->assertSame('0.00', (string) $investor->wallet->fresh()->invested);
            $this->assertSame('0.00', (string) $investor->wallet->fresh()->accrued);
        }
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_full_closure_from_default_status_settles_every_position(): void
    {
        [$loan, [$investor]] = $this->offerLoan(PayoutType::InterestOnly, '16.00');
        $loan->fresh()->transitionTo(Loan::STATUS_LATE);
        $loan->fresh()->transitionTo(Loan::STATUS_DEFAULT);

        Carbon::setTestNow(Carbon::now()->addDays(45));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id);
        Carbon::setTestNow();

        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertSame('1000.00', $this->principalReturnedTo($investor));
        $this->assertSame('0.00', (string) $investor->wallet->fresh()->invested);
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    // ── Characterization (group B, PAY-13): late detection looks at the borrower plan only ──

    public function test_late_detection_of_offer_loans_is_driven_by_the_borrower_tracker_not_investor_rows(): void
    {
        [$loan] = $this->offerLoan(PayoutType::Amortizing, '12.00');

        // Isolate the rule: drop the tracker generated at activation. Every
        // investor row is 90 days overdue and unpaid — the platform pays them on
        // schedule, so they can never carry lateness.
        $loan->amortizationSchedules()->borrowerTracker()->delete();
        InvestmentSchedule::where('loan_id', $loan->id)->update(['due_date' => now()->subDays(90)->toDateString()]);

        $this->assertCount(0, app(LateDetectionService::class)->detectNewlyLateSchedules(now(), [$loan->id]));
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);

        // PAY-13 (owner 2026-09-03 ✅): the admin-attested borrower tracker IS the
        // late source for offer loans — one row 30 days overdue makes the loan late.
        app(BorrowerPlanService::class)->generate($loan->fresh(), now()->subDays(30), null, null);

        $this->assertCount(1, app(LateDetectionService::class)->detectNewlyLateSchedules(now(), [$loan->id]));
        app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck([$loan->id]);
        $this->assertSame(Loan::STATUS_LATE, $loan->fresh()->status);
    }

    // ── Architecture rules from CLAUDE.md as executable checks ──

    /** @return array<int, string> repo-relative paths */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
        sort($files);

        return $files;
    }

    public function test_no_new_float_casts_reach_the_money_code(): void
    {
        // Known, display-only exceptions (number_format for JSON output, APR
        // pass-through, the what-if growth ratio). Shrinking this list is
        // welcome; growing it is a review conversation.
        $allowed = [
            'app/Services/APRCalculatorService.php',
            'app/Services/FeeQuote.php',
            'app/Services/FeeService.php',
            'app/Services/OfferProjectionService.php',
            'app/Http/Controllers/Api/DashboardController.php',
            'app/Http/Controllers/Api/PortfolioController.php',
            // Display-only number_format of aggregates in an ops report.
            'app/Console/Commands/Loans/ReportPayoutExposure.php',
        ];

        // app/Support (AccruedInterestLedger, DayCount, Money) and the money-moving
        // commands are money code too (review 2026-09-05).
        $offenders = [];
        foreach ([...$this->phpFilesUnder('app/Services'), ...$this->phpFilesUnder('app/Http'), ...$this->phpFilesUnder('app/Support'), ...$this->phpFilesUnder('app/Console/Commands')] as $path) {
            if (in_array($path, $allowed, true)) {
                continue;
            }
            foreach (file($path) as $n => $line) {
                if (str_contains($line, '(float)') && ! preg_match('~^\s*(//|\*)~', $line)) {
                    $offenders[] = "{$path}:".($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "Never float for money (CLAUDE.md). New (float) casts:\n".implode("\n", $offenders));
    }

    public function test_ledger_rows_and_wallet_buckets_are_written_only_by_wallet_service(): void
    {
        // Review 2026-09-05: the natural bypasses — property assignment, increment
        // by column name, the query builder, make()/unguarded()/withoutEvents() —
        // are caught too. Reads (Transaction::where/query) stay allowed.
        $ledgerWrite = '~Transaction::(create|insert|insertOrIgnore|upsert|forceCreate|updateOrCreate|firstOrCreate|make|unguarded|withoutEvents)\(|new Transaction\(|->transactions\(\)->(create|save|insert|make)\(|DB::table\([\'"]transactions[\'"]\)|DB::(insert|statement|unprepared)\([^;]*transactions~';
        $bucketWrite = '~(->update|->forceFill|->fill|->increment|->decrement|::create|->create)\(\s*\[?[^;]*[\'"](available|reserved|invested|accrued|earned)[\'"]\s*=>|->(available|reserved|invested|accrued|earned)\s*=[^=>]|->(increment|decrement)\(\s*[\'"](available|reserved|invested|accrued|earned)[\'"]|DB::table\([\'"]wallets[\'"]\)~s';

        $offenders = [];
        foreach ($this->phpFilesUnder('app') as $path) {
            if (str_ends_with($path, 'app/Services/WalletService.php')) {
                continue;
            }
            $source = file_get_contents($path);
            if (preg_match($ledgerWrite, $source)) {
                $offenders[] = "{$path} (transactions row written outside WalletService)";
            }
            if (preg_match($bucketWrite, $source)) {
                $offenders[] = "{$path} (wallet bucket written outside WalletService)";
            }
        }

        $this->assertSame([], $offenders, "Every wallet move goes through WalletService (CLAUDE.md):\n".implode("\n", $offenders));
    }
}
