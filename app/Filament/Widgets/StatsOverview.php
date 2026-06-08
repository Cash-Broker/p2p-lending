<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\UserResource;
use App\Models\DepositRequest;
use App\Models\Loan;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Инвеститори', User::where('role', 'investor')->count())
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Инвестирани средства', number_format(Wallet::sum('invested'), 2, '.', ',') . ' €')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Активни кредити', Loan::where('status', Loan::STATUS_ACTIVE)->count())
                ->icon('heroicon-o-document-text')
                ->color('primary'),

            Stat::make('Чакащи KYC', User::where('kyc_status', 'submitted')->count())
                ->description('Лични карти за одобрение')
                ->icon('heroicon-o-identification')
                ->color('warning')
                ->url(UserResource::getUrl('index', ['tableFilters' => ['kyc_status' => ['value' => 'submitted']]])),

            Stat::make('Чакащи депозити', DepositRequest::where('status', 'pending')->where('amount', '>', 0)->count())
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning'),

            Stat::make('Чакащи тегления', WithdrawalRequest::where('status', 'pending')->count())
                ->icon('heroicon-o-arrow-up-tray')
                ->color('danger'),
        ];
    }
}
