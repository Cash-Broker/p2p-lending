<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Widgets\InterestByPlanOverview;
use App\Filament\Resources\UserResource\Widgets\UserMoneyOverview;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

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
