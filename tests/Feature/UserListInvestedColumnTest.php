<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\Widgets\UserMoneyOverview;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The money columns on the admin users list.
 *
 * «Инвестирано» (boss 2026-08-10): the invested wallet bucket per user,
 * shown BEFORE the free balance so the admin sees who has how much money
 * deployed in the platform.
 *
 * Totals + «Тип акаунт» (boss 2026-08-11): «трябва от някъде да мога да
 * виждам в платформата сумарно инвестирани и свободни, да не се налага да
 * ги събирам» — a live Sum under each money column — and «махни от
 * началния екран на потребителите физ лице, това да излиза като кликна на
 * него» — the account type is off the list and on the profile instead, so
 * the table fits one screen.
 */
class UserListInvestedColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    private function investorWithWallet(string $invested, string $available, array $attributes = []): User
    {
        $investor = User::factory()->kycApproved()->create([
            'email_verified_at' => now(),
            ...$attributes,
        ]);
        $investor->wallet()->create()->forceFill([
            'invested' => $invested,
            'available' => $available,
        ])->save();

        return $investor;
    }

    public function test_users_list_renders_the_invested_column(): void
    {
        $investor = $this->investorWithWallet('1234.56', '100.00');

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanRenderTableColumn('wallet.invested')
            ->assertCanRenderTableColumn('wallet.available')
            ->assertCanSeeTableRecords([$investor]);
    }

    /**
     * The point of the feature: the admin reads one number per bucket
     * instead of adding the column up by hand.
     */
    public function test_users_list_totals_invested_and_free_money_across_all_users(): void
    {
        $this->investorWithWallet('1000.00', '250.00');
        $this->investorWithWallet('500.50', '99.50');
        // A wallet-less user must not break the aggregate.
        User::factory()->create(['email_verified_at' => now()]);

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertTableColumnSummarySet('wallet.invested', 'total', 1500.50)
            ->assertTableColumnSummarySet('wallet.available', 'total', 349.50);
    }

    /**
     * The totals are scoped by whatever the admin is looking at — the
     * «Тип акаунт» filter survived the column removal and still slices
     * both the rows and their sums.
     */
    public function test_totals_follow_the_active_account_type_filter(): void
    {
        $this->investorWithWallet('1000.00', '250.00', ['account_type' => User::TYPE_INDIVIDUAL]);
        $this->investorWithWallet('500.50', '99.50', ['account_type' => User::TYPE_LEGAL_ENTITY]);

        Livewire::test(ListUsers::class)
            ->filterTable('account_type', User::TYPE_LEGAL_ENTITY)
            ->assertOk()
            ->assertTableColumnSummarySet('wallet.invested', 'total', 500.50)
            ->assertTableColumnSummarySet('wallet.available', 'total', 99.50);
    }

    public function test_account_type_is_off_the_list_and_on_the_profile(): void
    {
        $company = $this->investorWithWallet('100.00', '0.00', [
            'account_type' => User::TYPE_LEGAL_ENTITY,
        ]);

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertTableColumnDoesNotExist('account_type');

        Livewire::test(ViewUser::class, ['record' => $company->getKey()])
            ->assertOk()
            ->assertSee('Юридическо лице');
    }

    /**
     * The same two totals, pinned above the table so a long user list can't
     * push them below the fold — and still tied to the table's own query.
     */
    public function test_header_widget_totals_follow_the_table_filter(): void
    {
        $this->investorWithWallet('1000.00', '250.00', ['account_type' => User::TYPE_INDIVIDUAL]);
        $this->investorWithWallet('500.50', '99.50', ['account_type' => User::TYPE_LEGAL_ENTITY]);

        Livewire::test(UserMoneyOverview::class)
            ->assertOk()
            ->assertSee(Number::currency(1500.50, 'EUR', 'bg'))
            ->assertSee(Number::currency(349.50, 'EUR', 'bg'));

        Livewire::test(UserMoneyOverview::class, [
            'tableFilters' => ['account_type' => ['value' => User::TYPE_LEGAL_ENTITY]],
        ])
            ->assertOk()
            ->assertSee(Number::currency(500.50, 'EUR', 'bg'))
            ->assertSee(Number::currency(99.50, 'EUR', 'bg'))
            ->assertDontSee(Number::currency(1500.50, 'EUR', 'bg'));
    }

    /**
     * `reserved` (a withdrawal on its way out) and `accrued` (interest
     * promised, not yet released) sit in no column, so the cards disclose
     * them underneath instead of silently leaving the money out.
     *
     * Since 2026-08-19 the accrued note lives under «Текущо начислени лихви» —
     * it is the slice of that interest already parked in investors' balances
     * (capitalized plans), which is where it means something.
     */
    public function test_widget_discloses_money_parked_outside_the_two_columns(): void
    {
        $investor = $this->investorWithWallet('1000.00', '250.00');
        $investor->wallet->forceFill(['reserved' => '75.00', 'accrued' => '12.34'])->save();

        Livewire::test(UserMoneyOverview::class)
            ->assertOk()
            // The headline figures still equal the columns they sit above.
            ->assertSee(Number::currency(1000, 'EUR', 'bg'))
            ->assertSee(Number::currency(250, 'EUR', 'bg'))
            ->assertSee('+ '.Number::currency(12.34, 'EUR', 'bg').' от тях вече в балансите')
            ->assertSee('+ '.Number::currency(75, 'EUR', 'bg').' в процес на теглене');
    }

    public function test_widget_stays_quiet_when_nothing_is_parked(): void
    {
        $this->investorWithWallet('1000.00', '250.00');

        Livewire::test(UserMoneyOverview::class)
            ->assertOk()
            ->assertDontSee('в процес на теглене')
            ->assertDontSee('вече в балансите')
            ->assertSee('По текущия филтър и търсене');
    }

    public function test_users_list_page_renders_the_totals_widget(): void
    {
        $this->investorWithWallet('1000.00', '250.00');

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertSeeLivewire(UserMoneyOverview::class);
    }

    /**
     * A ListRecords page hands its widgets NOTHING by default, which would
     * leave the cards showing platform totals while the table below is
     * filtered — silently wrong, and invisible in a widget-only test.
     */
    public function test_page_forwards_its_filter_state_to_the_totals_widget(): void
    {
        $page = Livewire::test(ListUsers::class)
            ->set('tableSearch', 'Технокапитал')
            ->set('tableFilters', ['account_type' => ['value' => User::TYPE_LEGAL_ENTITY]]);

        $data = $page->instance()->getWidgetData();

        $this->assertSame('Технокапитал', $data['tableSearch']);
        $this->assertSame(User::TYPE_LEGAL_ENTITY, $data['tableFilters']['account_type']['value']);
        $this->assertArrayHasKey('tableColumnSearches', $data);
    }

    /**
     * The profile is now where the admin goes for the account type, so the
     * page must not print raw column values at it («investor», «approved»).
     */
    public function test_profile_shows_role_and_kyc_in_bulgarian(): void
    {
        $investor = $this->investorWithWallet('100.00', '0.00');

        Livewire::test(ViewUser::class, ['record' => $investor->getKey()])
            ->assertOk()
            ->assertSee('Инвеститор')
            ->assertSee('Одобрен')
            ->assertDontSee('investor')
            ->assertDontSee('approved');
    }
}
