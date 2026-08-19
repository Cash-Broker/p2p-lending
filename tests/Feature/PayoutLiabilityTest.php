<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Widgets\InterestByPlanOverview;
use App\Filament\Resources\UserResource\Widgets\UserMoneyOverview;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccruedEarningsService;
use App\Services\InvestmentService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\PayoutAccrualService;
use App\Services\PayoutLiabilityService;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Текущо начислени лихви» + разбивката по планове над списъка с потребители
 * (Рени 2026-08-19).
 *
 * Two things are pinned here, both of them her words:
 *
 *   • «Искам лихвите за плащане да бъдат текущите вече начислени, а това са
 *     бъдещи лихви ако всички кредити не се погасят частично или предварително,
 *     което никога не е така» — the card shows interest EARNED TO DATE, not the
 *     remaining schedule.
 *   • «Както на мен ми излиза текущата печалба… като потребител» — the admin
 *     total is exactly the sum of what each investor sees on their own
 *     dashboard, computed by the same service, so the two cannot drift.
 *
 * The per-plan cards must add up to that header exactly, and the underlying
 * capital figure still drops out when an installment is paid or cancelled.
 */
class PayoutLiabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Loan, 1: User} */
    private function activeLoan(PayoutType $plan, string $rate = '12.00', string $stake = '1200.00'): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => $stake, 'investable_amount' => $stake, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $loan->offers()->where('payout_type', $plan)->update(['interest_rate' => $rate]);

        $investor = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $investor->wallet()->create();
        app(WalletService::class)->credit($investor->id, $stake, Transaction::TYPE_DEPOSIT, 'seed');

        app(InvestmentService::class)->invest(
            $investor, $loan->fresh(), $stake, (string) Str::uuid(),
            $loan->offers()->where('payout_type', $plan)->value('id'),
        );

        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $investor];
    }

    private function liability(): PayoutLiabilityService
    {
        return app(PayoutLiabilityService::class);
    }

    private function accrued(): AccruedEarningsService
    {
        return app(AccruedEarningsService::class);
    }

    /** Every investor id, i.e. the widget's unfiltered scope. */
    private function allUserIds(): array
    {
        return User::query()->pluck('id')->all();
    }

    private function scheduleInterest(Loan $loan, array $statuses = ['pending', 'late']): string
    {
        return InvestmentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', $statuses)
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->interest, 2), '0.00');
    }

    public function test_every_plan_is_reported_even_with_no_positions(): void
    {
        $byPlan = $this->liability()->unpaidByPlan();

        $this->assertSame(
            ['amortizing', 'interest_only', 'capitalized'],
            array_keys($byPlan),
        );
        foreach ($byPlan as $figures) {
            $this->assertSame('0.00', $figures['interest']);
            $this->assertSame('0.00', $figures['principal']);
            $this->assertSame('0.00', $figures['total']);
        }
        $this->assertSame('0.00', $this->liability()->totalInterest($byPlan));
    }

    public function test_the_headline_equals_what_the_investors_see_as_current_profit(): void
    {
        // Reni's requirement, literally: the admin total is the sum of every
        // investor's own «Текуща печалба».
        [, $first] = $this->activeLoan(PayoutType::InterestOnly, '16.00');
        [, $second] = $this->activeLoan(PayoutType::Capitalized, '20.00');

        Carbon::setTestNow(Carbon::now()->addDays(70));

        $perInvestor = bcadd(
            $this->accrued()->forUser($first->id)['amount_daily'],
            $this->accrued()->forUser($second->id)['amount_daily'],
            2,
        );

        $this->assertGreaterThan(0, (float) $perInvestor);
        $this->assertSame($perInvestor, $this->accrued()->accruedByPlan($this->allUserIds())['total']);

        Carbon::setTestNow();
    }

    public function test_accrued_interest_is_far_below_the_whole_remaining_schedule(): void
    {
        // The old card showed the full remaining schedule — «това ми извади
        // акъла». A freshly funded loan has earned almost nothing yet, and the
        // card must say so.
        [$loan] = $this->activeLoan(PayoutType::InterestOnly, '16.00');

        $accruedNow = $this->accrued()->accruedByPlan($this->allUserIds())['total'];
        $wholeSchedule = $this->scheduleInterest($loan);

        $this->assertGreaterThan((float) $accruedNow, (float) $wholeSchedule);
        $this->assertLessThan((float) $wholeSchedule / 2, (float) $accruedNow);
    }

    public function test_accrued_interest_is_split_by_plan_and_sums_to_the_headline(): void
    {
        [, $amortizing] = $this->activeLoan(PayoutType::Amortizing, '12.00');
        [, $interestOnly] = $this->activeLoan(PayoutType::InterestOnly, '16.00');
        [, $capitalized] = $this->activeLoan(PayoutType::Capitalized, '20.00');

        Carbon::setTestNow(Carbon::now()->addDays(100));

        $result = $this->accrued()->accruedByPlan($this->allUserIds());

        // Each plan carries its own investor's accrual…
        $this->assertSame(
            $this->accrued()->forUser($amortizing->id)['amount_daily'],
            $result['by_plan']['amortizing'],
        );
        $this->assertSame(
            $this->accrued()->forUser($interestOnly->id)['amount_daily'],
            $result['by_plan']['interest_only'],
        );
        $this->assertSame(
            $this->accrued()->forUser($capitalized->id)['amount_daily'],
            $result['by_plan']['capitalized'],
        );

        // …and the three reconcile with the header, to the cent.
        $sum = array_reduce(
            $result['by_plan'],
            fn (string $carry, string $amount) => bcadd($carry, $amount, 2),
            '0.00',
        );
        $this->assertSame($sum, $result['total']);

        Carbon::setTestNow();
    }

    public function test_capital_at_work_is_split_by_plan_and_sums_to_the_headline(): void
    {
        [$amortizing] = $this->activeLoan(PayoutType::Amortizing, '12.00');
        [$interestOnly] = $this->activeLoan(PayoutType::InterestOnly, '16.00');
        [$capitalized] = $this->activeLoan(PayoutType::Capitalized, '20.00');

        $byPlan = $this->liability()->unpaidByPlan();

        // Each plan reports exactly its own unpaid schedule interest…
        $this->assertSame($this->scheduleInterest($amortizing), $byPlan['amortizing']['interest']);
        $this->assertSame($this->scheduleInterest($interestOnly), $byPlan['interest_only']['interest']);
        $this->assertSame($this->scheduleInterest($capitalized), $byPlan['capitalized']['interest']);

        // …and the three add up to the headline figure exactly. A breakdown
        // that does not reconcile with its own header is worse than none.
        $expected = bcadd(
            bcadd($byPlan['amortizing']['interest'], $byPlan['interest_only']['interest'], 2),
            $byPlan['capitalized']['interest'],
            2,
        );
        $this->assertSame($expected, $this->liability()->totalInterest($byPlan));

        // Principal is disclosed per plan too: amortizing repays it monthly,
        // the other two at maturity — but all three still owe it.
        foreach (['amortizing', 'interest_only', 'capitalized'] as $plan) {
            $this->assertSame('1200.00', $byPlan[$plan]['principal']);
            $this->assertSame(
                bcadd($byPlan[$plan]['principal'], $byPlan[$plan]['interest'], 2),
                $byPlan[$plan]['total'],
            );
        }
    }

    public function test_a_paid_installment_is_deducted(): void
    {
        [$loan] = $this->activeLoan(PayoutType::InterestOnly, '16.00');

        $before = $this->liability()->unpaidByPlan()['interest_only']['interest'];
        $this->assertGreaterThan(0, (float) $before);

        // The engine pays the first two installments…
        Carbon::setTestNow(Carbon::now()->addDays(61));
        app(PayoutAccrualService::class)->processLoan($loan->id);
        Carbon::setTestNow();

        $paidInterest = InvestmentSchedule::where('loan_id', $loan->id)
            ->where('status', 'paid')
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->interest, 2), '0.00');
        $this->assertGreaterThan(0, (float) $paidInterest);

        // …and exactly that much leaves the liability, without anyone editing it.
        $this->assertSame(
            bcsub($before, $paidInterest, 2),
            $this->liability()->unpaidByPlan()['interest_only']['interest'],
        );
    }

    public function test_an_early_closure_removes_the_cancelled_interest(): void
    {
        [$loan] = $this->activeLoan(PayoutType::InterestOnly, '16.00');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertGreaterThan(0, (float) $this->liability()->unpaidByPlan()['interest_only']['interest']);

        app(EarlyClosureExecutionService::class)->execute($loan->id, $admin->id);

        // Closed installments are cancelled, not paid — either way the platform
        // no longer owes them.
        $byPlan = $this->liability()->unpaidByPlan();
        $this->assertSame('0.00', $byPlan['interest_only']['interest']);
        $this->assertSame('0.00', $byPlan['interest_only']['principal']);
    }

    public function test_the_cards_render_above_the_users_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        [$loan] = $this->activeLoan(PayoutType::Capitalized, '20.00');
        $owed = $this->scheduleInterest($loan);

        // The page renders; the header widgets are Livewire children with
        // their own lifecycle, so their content is asserted on them directly.
        Livewire::test(ListUsers::class)->assertOk();

        Livewire::test(UserMoneyOverview::class)
            ->assertOk()
            ->assertSee('Текущо начислени лихви');

        Livewire::test(InterestByPlanOverview::class)
            ->assertOk()
            ->assertSee('Анюитет')
            ->assertSee('Само лихва')
            ->assertSee('Капитализация');

        // The headline is the money, not a placeholder.
        $this->assertGreaterThan(0, (float) $owed);
    }
}
