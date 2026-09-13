<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\Widgets\UserMoneyOverview;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccruedEarningsService;
use App\Services\InvestmentService;
use App\Services\PayoutAccrualService;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Текущ баланс» per investor on the admin users list + profile (Reni
 * 2026-09-13: «Искам да виждам на всеки инвеститор освен това което е
 * инвестирал, текущия му баланс с начислените лихви»).
 *
 * Pinned here:
 *   • the row = invested + the interest that investor has earned to date —
 *     the SAME figure they see as «Текуща печалба», from the same service;
 *   • the column total = Σ invested + the «Текущо начислени лихви» card, for
 *     the same filtered set (the cards and the column must never disagree);
 *   • one batched accrual query per page, not one per row.
 */
class UserListCurrentBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An approved investor with one active offer position. The loan is
     * funded in full by that investor so activation (and the schedule) is
     * immediate; the invest moment is the accrual anchor.
     *
     * @return array{0: Loan, 1: User}
     */
    private function investorWithPosition(PayoutType $plan, string $rate = '12.00', string $stake = '1000.00', array $attributes = []): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => $stake, 'investable_amount' => $stake, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $loan->offers()->where('payout_type', $plan)->update(['interest_rate' => $rate]);

        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now(), ...$attributes]);
        $investor->wallet()->create();
        app(WalletService::class)->credit($investor->id, $stake, Transaction::TYPE_DEPOSIT, 'seed');

        app(InvestmentService::class)->invest(
            $investor, $loan->fresh(), $stake, (string) Str::uuid(),
            $loan->offers()->where('payout_type', $plan)->value('id'),
        );

        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $investor->fresh()];
    }

    private function idleInvestor(string $invested = '0.00', string $available = '0.00'): User
    {
        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $investor->wallet()->create()->forceFill(['invested' => $invested, 'available' => $available])->save();

        return $investor;
    }

    private function accrued(): AccruedEarningsService
    {
        return app(AccruedEarningsService::class);
    }

    // ── Service: the per-investor batch ──

    public function test_accrued_by_user_is_each_investors_own_counter_and_sums_to_the_card(): void
    {
        [, $interestOnly] = $this->investorWithPosition(PayoutType::InterestOnly, '16.00');
        [, $capitalized] = $this->investorWithPosition(PayoutType::Capitalized, '20.00');
        $idle = $this->idleInvestor('0.00', '500.00');

        Carbon::setTestNow(Carbon::now()->addDays(70));

        $ids = [$interestOnly->id, $capitalized->id, $idle->id];
        $byUser = $this->accrued()->accruedByUser($ids);

        // Each row is exactly what that investor sees on their dashboard…
        $this->assertSame($this->accrued()->forUser($interestOnly->id)['amount_daily'], $byUser[$interestOnly->id]);
        $this->assertSame($this->accrued()->forUser($capitalized->id)['amount_daily'], $byUser[$capitalized->id]);
        $this->assertGreaterThan(0, (float) $byUser[$interestOnly->id]);
        $this->assertGreaterThan(0, (float) $byUser[$capitalized->id]);

        // …an investor with nothing at work has no row (callers default to 0)…
        $this->assertArrayNotHasKey($idle->id, $byUser);

        // …and the rows add up to the header card to the cent.
        $sum = array_reduce($byUser, fn (string $carry, string $amount) => bcadd($carry, $amount, 2), '0.00');
        $this->assertSame($this->accrued()->accruedByPlan($ids)['total'], $sum);
    }

    public function test_accrued_by_user_only_reports_the_requested_investors(): void
    {
        [, $first] = $this->investorWithPosition(PayoutType::InterestOnly, '16.00');
        [, $second] = $this->investorWithPosition(PayoutType::Amortizing, '12.00');

        Carbon::setTestNow(Carbon::now()->addDays(20));

        $this->assertSame([$first->id], array_keys($this->accrued()->accruedByUser([$first->id])));
        $this->assertSame(
            [$second->id],
            array_keys($this->accrued()->accruedByUser(User::query()->whereKey($second->id)->select('id'))),
        );
        $this->assertSame([], $this->accrued()->accruedByUser([]));
    }

    public function test_for_user_still_agrees_with_the_batch_after_the_shared_refactor(): void
    {
        // The offer branch of forUser() now runs through the same helper the
        // batch uses — a regression here would silently split the investor's
        // ticker from the admin's figures.
        [, $investor] = $this->investorWithPosition(PayoutType::InterestOnly, '12.00');

        // 1000 @ 12% → 10.00/month; 15 of 30 days → 5.00.
        $asOf = now()->addDays(15);

        $this->assertSame('5.00', $this->accrued()->forUser($investor->id, $asOf)['amount_daily']);
        $this->assertSame('5.00', $this->accrued()->accruedByUser([$investor->id], $asOf)[$investor->id]);
    }

    // ── The users list column ──

    public function test_users_list_shows_current_balance_as_invested_plus_accrued_interest(): void
    {
        [, $investor] = $this->investorWithPosition(PayoutType::InterestOnly, '12.00');

        // 1000 invested @ 12% interest-only → 10.00/month; 15 of 30 days → 5.00.
        Carbon::setTestNow(Carbon::now()->addDays(15));

        $page = Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanRenderTableColumn('invested_with_accrued_interest')
            ->assertTableColumnStateSet('invested_with_accrued_interest', '1005.00', record: $investor)
            ->assertSee(Number::currency(1005, 'EUR', 'bg'))
            // The interest slice is spelled out under the figure.
            ->assertSee('вкл. '.Number::currency(5, 'EUR', 'bg').' лихви');

        // The description reads the same batch the state does.
        $this->assertSame(
            'вкл. '.Number::currency(5, 'EUR', 'bg').' лихви',
            UserResource::accruedInterestNote($investor, $page->instance()),
        );
    }

    public function test_current_balance_equals_invested_while_nothing_has_accrued_yet(): void
    {
        // Day zero: nothing earned yet, so the balance IS the invested amount
        // and the interest line stays quiet — «+ 0,00 €» on every row is noise.
        [, $investor] = $this->investorWithPosition(PayoutType::InterestOnly, '12.00');
        $idle = $this->idleInvestor('250.00', '100.00');
        $walletless = User::factory()->create(['email_verified_at' => now()]);

        $page = Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertTableColumnStateSet('invested_with_accrued_interest', '1000.00', record: $investor)
            ->assertTableColumnStateSet('invested_with_accrued_interest', '250.00', record: $idle)
            ->assertTableColumnStateSet('invested_with_accrued_interest', null, record: $walletless)
            ->assertDontSee('вкл. ');

        $this->assertNull(UserResource::accruedInterestNote($investor, $page->instance()));
        $this->assertNull(UserResource::accruedInterestNote($walletless, $page->instance()));
    }

    public function test_current_balance_total_is_the_invested_total_plus_the_accrued_card(): void
    {
        [, $interestOnly] = $this->investorWithPosition(PayoutType::InterestOnly, '16.00', '1000.00', ['account_type' => User::TYPE_INDIVIDUAL]);
        [, $capitalized] = $this->investorWithPosition(PayoutType::Capitalized, '20.00', '1200.00', ['account_type' => User::TYPE_LEGAL_ENTITY]);
        $this->idleInvestor('300.00', '0.00');

        Carbon::setTestNow(Carbon::now()->addDays(70));

        $everyone = User::query()->pluck('id')->all();
        $expectedAll = bcadd('2500.00', $this->accrued()->accruedByPlan($everyone)['total'], 2);

        // Two accruing investors on ONE page: each row carries its own
        // figure (a memo mis-scoped to the first row would pass a one-investor
        // test), and the footer is their sum plus the idle capital.
        $interestOnlyAccrued = $this->accrued()->forUser($interestOnly->id)['amount_daily'];
        $capitalizedAccrued = $this->accrued()->forUser($capitalized->id)['amount_daily'];
        $this->assertNotSame($interestOnlyAccrued, $capitalizedAccrued);

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertTableColumnStateSet('invested_with_accrued_interest', bcadd('1000.00', $interestOnlyAccrued, 2), record: $interestOnly)
            ->assertTableColumnStateSet('invested_with_accrued_interest', bcadd('1200.00', $capitalizedAccrued, 2), record: $capitalized)
            ->assertTableColumnSummarySet('invested_with_accrued_interest', 'total', $expectedAll);
        $this->assertSame($expectedAll, bcadd(bcadd(bcadd('1000.00', $interestOnlyAccrued, 2), bcadd('1200.00', $capitalizedAccrued, 2), 2), '300.00', 2));

        // …and the same total sits in the header card, where the boss wants
        // totals (the footer falls below the fold on a long list).
        Livewire::test(UserMoneyOverview::class)
            ->assertOk()
            ->assertSee('Текущ баланс общо')
            ->assertSee(Number::currency((float) $expectedAll, 'EUR', 'bg'));

        // The total follows the filter, exactly like the cards above it.
        $expectedLegal = bcadd('1200.00', $this->accrued()->accruedByPlan([$capitalized->id])['total'], 2);
        $this->assertGreaterThan(1200, (float) $expectedLegal);

        Livewire::test(ListUsers::class)
            ->filterTable('account_type', User::TYPE_LEGAL_ENTITY)
            ->assertOk()
            ->assertCanSeeTableRecords([$capitalized])
            ->assertCanNotSeeTableRecords([$interestOnly])
            ->assertTableColumnSummarySet('invested_with_accrued_interest', 'total', $expectedLegal);
    }

    /**
     * The double-count trap: a capitalized position whose monthly milestones
     * the payout engine has already parked in the wallet's `accrued` bucket.
     * That bucket is a SLICE of the accrued-interest figure, so the balance
     * is invested + accrual — never invested + accrual + bucket.
     */
    public function test_the_accrued_bucket_is_not_added_on_top_of_the_accrual(): void
    {
        [$loan, $investor] = $this->investorWithPosition(PayoutType::Capitalized, '20.00');

        Carbon::setTestNow(Carbon::now()->addDays(45));
        app(PayoutAccrualService::class)->processLoan($loan->id);

        $bucket = (string) $investor->wallet->fresh()->accrued;
        $this->assertGreaterThan(0, (float) $bucket);

        $accrual = $this->accrued()->accruedByUser([$investor->id])[$investor->id];
        $this->assertGreaterThanOrEqual((float) $bucket, (float) $accrual);

        $expected = bcadd('1000.00', $accrual, 2);
        $this->assertSame($expected, UserResource::investedWithAccruedInterestFor($investor->fresh()));
        $this->assertNotSame(bcadd($expected, $bucket, 2), UserResource::investedWithAccruedInterestFor($investor->fresh()));

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertTableColumnStateSet('invested_with_accrued_interest', $expected, record: $investor)
            ->assertTableColumnSummarySet('invested_with_accrued_interest', 'total', $expected);
    }

    public function test_accrued_by_user_accumulates_across_an_investors_positions(): void
    {
        // One investor, two loans, two plans — the per-user figure is the SUM
        // (mirrors AccruedEarningsTest::test_portfolio_sums_across_loans_and_plans).
        Notification::fake();
        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();
        app(WalletService::class)->credit($investor->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');

        foreach ([[PayoutType::InterestOnly, '16.00'], [PayoutType::Amortizing, '12.00']] as [$plan, $rate]) {
            $loan = Loan::factory()->published()->create([
                'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
                'interest_rate' => '12.00', 'term_months' => 12,
            ]);
            $loan->offers()->where('payout_type', $plan)->update(['interest_rate' => $rate]);
            app(InvestmentService::class)->invest(
                $investor, $loan->fresh(), '1000.00', (string) Str::uuid(),
                $loan->offers()->where('payout_type', $plan)->value('id'),
            );
            if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
                $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
            }
        }

        // 15 of 30 days: interest-only 13.33 → 6.66, amortizing 10.00 → 5.00.
        $asOf = now()->addDays(15);

        $this->assertSame('11.66', $this->accrued()->accruedByUser([$investor->id], $asOf)[$investor->id]);
        $this->assertSame('11.66', $this->accrued()->forUser($investor->id, $asOf)['amount_daily']);
    }

    public function test_accrued_by_user_follows_the_payout_engine_scope(): void
    {
        // Late loans keep paying «по график», so they keep accruing; default is
        // where the engine (and the open write-off decision) stops.
        [$loan, $investor] = $this->investorWithPosition(PayoutType::InterestOnly, '16.00');

        Carbon::setTestNow(Carbon::now()->addDays(45));

        $loan->transitionTo(Loan::STATUS_LATE);
        $late = $this->accrued()->accruedByUser([$investor->id]);
        $this->assertSame($this->accrued()->forUser($investor->id)['amount_daily'], $late[$investor->id]);
        $this->assertGreaterThan(0, (float) $late[$investor->id]);

        $loan->fresh()->transitionTo(Loan::STATUS_DEFAULT);
        $this->assertArrayNotHasKey($investor->id, $this->accrued()->accruedByUser([$investor->id]));
        $this->assertSame('0.00', $this->accrued()->forUser($investor->id)['amount_daily']);
        $this->assertSame('1000.00', UserResource::investedWithAccruedInterestFor($investor->fresh()));
    }

    /**
     * Production shape: more users than one page. Filament then renders TWO
     * footers — «this page» (filtered + sorted + LIMIT/OFFSET, wrapped as a
     * derived table) and «all» — and the custom summarizer must survive both
     * as an IN (...) subquery on MySQL.
     */
    public function test_current_balance_totals_on_a_multi_page_list(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->investorWithPosition(PayoutType::InterestOnly, '12.00');
        }

        // 1000 @ 12% → 5.00 after 15 days, on every one of the 12 positions.
        Carbon::setTestNow(Carbon::now()->addDays(15));

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertTableColumnSummarySet('invested_with_accrued_interest', 'total', '10050.00', isCurrentPaginationPageOnly: true)
            ->assertTableColumnSummarySet('invested_with_accrued_interest', 'total', '12060.00')
            ->assertTableColumnSummarySet('wallet.invested', 'total', '12000.00');
    }

    /**
     * Rows + page footer + «всички» footer read ONE shared memo: a single-page
     * list walks every position exactly once per render (the same number of
     * accrual queries as one direct accruedByUser() call), however many rows
     * it has; a multi-page list adds one more pass for the users off the page.
     */
    public function test_the_page_accrues_once_per_render_not_once_per_row(): void
    {
        $this->investorWithPosition(PayoutType::InterestOnly, '16.00');

        Carbon::setTestNow(Carbon::now()->addDays(20));

        $accrualQueries = fn (): int => collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'from `investments`'))
            ->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->accrued()->accruedByUser(User::query()->pluck('id')->all());
        $onePass = $accrualQueries();
        DB::disableQueryLog();

        $countRender = function () use ($accrualQueries): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListUsers::class)->assertOk();
            $queries = $accrualQueries();
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertGreaterThan(0, $onePass);
        $this->assertSame($onePass, $countRender());

        $this->investorWithPosition(PayoutType::Amortizing, '12.00');
        $this->investorWithPosition(PayoutType::Capitalized, '20.00');

        // Three investors, three positions, still one page — still one pass.
        $this->assertSame($onePass, $countRender());

        // Past one page (default 10 rows): the rows and the page footer share
        // one pass, the «всички» footer computes only the remaining users.
        for ($i = 0; $i < 9; $i++) {
            $this->investorWithPosition(PayoutType::InterestOnly, '12.00');
        }

        $this->assertSame(2 * $onePass, $countRender());
    }

    // ── The profile ──

    public function test_profile_shows_accrued_interest_and_current_balance(): void
    {
        [, $investor] = $this->investorWithPosition(PayoutType::InterestOnly, '12.00');

        Carbon::setTestNow(Carbon::now()->addDays(15));

        Livewire::test(ViewUser::class, ['record' => $investor->getKey()])
            ->assertOk()
            ->assertSee('Начислени лихви')
            ->assertSee('Текущ баланс')
            ->assertSee(Number::currency(5, 'EUR', 'bg'))
            ->assertSee(Number::currency(1005, 'EUR', 'bg'));

        // Off the list page the helper computes for the one user — same scope.
        $this->assertSame('5.00', UserResource::accruedInterestFor($investor));
        $this->assertSame('1005.00', UserResource::investedWithAccruedInterestFor($investor));
    }

    /**
     * Both profile entries are built from ONE accrual pass (one `now()`), so
     * «Инвестирани + Начислени лихви = Текущ баланс» holds even across midnight.
     */
    public function test_the_profile_accrues_once_for_both_entries(): void
    {
        [, $investor] = $this->investorWithPosition(PayoutType::InterestOnly, '12.00');

        Carbon::setTestNow(Carbon::now()->addDays(15));

        $accrualQueries = fn (): int => collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'from `investments`'))
            ->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->accrued()->accruedByUser([$investor->id]);
        $onePass = $accrualQueries();

        DB::flushQueryLog();
        Livewire::test(ViewUser::class, ['record' => $investor->getKey()])
            ->assertOk()
            ->assertSee(Number::currency(5, 'EUR', 'bg'))
            ->assertSee(Number::currency(1005, 'EUR', 'bg'));
        $profileRender = $accrualQueries();
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $onePass);
        $this->assertSame($onePass, $profileRender);
    }

    public function test_profile_helpers_are_null_safe_for_a_user_without_a_wallet(): void
    {
        $walletless = User::factory()->create(['email_verified_at' => now()]);

        $this->assertNull(UserResource::investedWithAccruedInterestFor($walletless));
        $this->assertNull(UserResource::accruedInterestNote($walletless));

        Livewire::test(ViewUser::class, ['record' => $walletless->getKey()])->assertOk();
    }
}
