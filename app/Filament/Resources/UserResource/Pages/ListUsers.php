<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Widgets\InterestByPlanOverview;
use App\Filament\Resources\UserResource\Widgets\UserMoneyOverview;
use App\Models\User;
use App\Services\AccruedEarningsService;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * Accrued interest per investor for THIS render — the «Текущ баланс»
     * rows and both footers («тази страница» / «всички») read one shared
     * memo, so a user's positions are loaded and walked at most once per
     * render no matter how many places show the figure. Not Livewire
     * properties on purpose: request-scoped memos must never be serialized
     * into the component payload (money figures hydrated from the browser
     * would be a lie).
     *
     * @var array<int, string> user id → accrued interest (only investors with an accruing position)
     */
    private array $accruedInterestByUser = [];

    /** @var array<int, true> user ids already evaluated — absent from the map above means 0 */
    private array $accruedInterestKnownIds = [];

    /**
     * «Текущ баланс» column: what this investor has earned to date across
     * their open positions — the same figure as their own «Текуща печалба»,
     * batched for the page (one query for every row instead of N×3).
     * Investors with no accruing position read 0.
     */
    public function accruedInterestFor(User $user): string
    {
        $this->ensureAccruedInterestFor($this->getTableRecords()->modelKeys());

        return $this->accruedInterestByUser[$user->id] ?? '0.00';
    }

    /**
     * Σ accrued interest over a set of investors — the column footer. The
     * page footer is the rows' own set (nothing new to compute); the «всички»
     * footer computes only the ids the page did not already cover.
     *
     * @param  array<int, int>  $userIds
     */
    public function accruedInterestTotalFor(array $userIds): string
    {
        $this->ensureAccruedInterestFor($userIds);

        $total = '0.00';
        foreach ($userIds as $userId) {
            $total = bcadd($total, $this->accruedInterestByUser[$userId] ?? '0.00', 2);
        }

        return $total;
    }

    /**
     * @param  array<int, int|string>  $userIds
     */
    private function ensureAccruedInterestFor(array $userIds): void
    {
        $missing = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if (! isset($this->accruedInterestKnownIds[$userId])) {
                $missing[] = $userId;
                $this->accruedInterestKnownIds[$userId] = true;
            }
        }

        if ($missing === []) {
            return;
        }

        $this->accruedInterestByUser += app(AccruedEarningsService::class)->accruedByUser($missing);
    }

    /**
     * The money totals sit ABOVE the table (boss 2026-08-11) — with a long
     * user list the summary row under the table falls below the fold.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            UserMoneyOverview::class,
            // Разбивката по погасителни планове стои под общите суми.
            InterestByPlanOverview::class,
        ];
    }

    /**
     * A ListRecords page passes NO data to its widgets by default, so the
     * totals card would ignore the filter bar and always show the whole
     * platform. Handing it the three inputs that change WHICH rows are
     * listed keeps the cards and the table describing the same set.
     *
     * Sort and pagination are deliberately NOT passed — they can't change a
     * SUM, and forwarding them would re-run the aggregate on every page
     * flip and every column sort.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'tableFilters' => $this->tableFilters,
            'tableSearch' => $this->tableSearch,
            'tableColumnSearches' => $this->tableColumnSearches,
        ];
    }
}
