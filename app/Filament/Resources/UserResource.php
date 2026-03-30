<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Notifications\KycStatusNotification;
use BackedEnum;
use Filament\Infolists;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Потребители';
    protected static ?string $pluralModelLabel = 'Потребители';
    protected static ?string $modelLabel = 'Потребител';
    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Имейл')->searchable(),
                Tables\Columns\BadgeColumn::make('role')->label('Роля')->colors(['primary' => 'investor', 'danger' => 'admin']),
                Tables\Columns\BadgeColumn::make('kyc_status')->label('KYC')->colors(['warning' => 'pending', 'info' => 'submitted', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('wallet.available')->label('Баланс')->money('EUR'),
                Tables\Columns\TextColumn::make('created_at')->label('Регистрация')->date('d.m.Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->options(['investor' => 'Инвеститор', 'admin' => 'Админ']),
                Tables\Filters\SelectFilter::make('kyc_status')->options(['pending' => 'Очакващ', 'submitted' => 'Изпратен', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен']),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\Action::make('approve_kyc')->label('Одобри KYC')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (User $record) => $record->kyc_status === 'submitted')->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->forceFill(['kyc_status' => 'approved'])->save();
                        $record->notify(new KycStatusNotification('approved'));
                        Notification::make()->title('KYC одобрен')->success()->send();
                    }),
                \Filament\Actions\Action::make('reject_kyc')->label('Отхвърли KYC')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (User $record) => $record->kyc_status === 'submitted')->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->forceFill(['kyc_status' => 'rejected'])->save();
                        $record->notify(new KycStatusNotification('rejected'));
                        Notification::make()->title('KYC отхвърлен')->danger()->send();
                    }),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist->schema([
            \Filament\Schemas\Components\Section::make('Профил')->schema([
                Infolists\Components\TextEntry::make('name')->label('Име'),
                Infolists\Components\TextEntry::make('email')->label('Имейл'),
                Infolists\Components\TextEntry::make('role')->label('Роля')->badge(),
                Infolists\Components\TextEntry::make('kyc_status')->label('KYC')->badge(),
                Infolists\Components\TextEntry::make('created_at')->label('Регистрация')->date('d.m.Y'),
            ])->columns(3),
            \Filament\Schemas\Components\Section::make('Портфейл')->schema([
                Infolists\Components\TextEntry::make('wallet.available')->label('Свободни')->money('EUR'),
                Infolists\Components\TextEntry::make('wallet.invested')->label('Инвестирани')->money('EUR'),
                Infolists\Components\TextEntry::make('wallet.earned')->label('Спечелени')->money('EUR'),
            ])->columns(3),
            \Filament\Schemas\Components\Section::make('KYC документ')->schema([
                Infolists\Components\TextEntry::make('kyc_status')->label('Статус')->badge()
                    ->color(fn (string $state) => match ($state) { 'approved' => 'success', 'submitted' => 'info', 'rejected' => 'danger', default => 'warning' }),
                Infolists\Components\TextEntry::make('phone')->label('Телефон')->default('—'),
                Infolists\Components\ViewEntry::make('kyc_document_path')->label('Документ')
                    ->view('filament.components.kyc-image')
                    ->columnSpanFull(),
            ])->columns(2)->visible(fn ($record) => $record->kyc_document_path !== null),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'view' => Pages\ViewUser::route('/{record}')];
    }
}
