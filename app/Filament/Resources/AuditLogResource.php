<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-eye';
    protected static ?string $navigationLabel = 'Одит лог';
    protected static string | UnitEnum | null $navigationGroup = 'Система';
    protected static ?int $navigationSort = 10;
    protected static ?string $pluralModelLabel = 'Одит логове';
    protected static ?string $modelLabel = 'Одит лог';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Human-readable description of what happened.
     */
    private static function describeAction(AuditLog $record): string
    {
        $model = class_basename($record->model_type);
        $id = $record->model_id;
        $user = $record->user?->name ?? 'Система';
        $new = $record->new_values ?? [];
        $old = $record->old_values ?? [];

        $email = $new['email'] ?? '?';
        $amount = $new['amount'] ?? '?';
        $type = $new['type'] ?? '?';
        $loanId = $new['loan_id'] ?? '?';
        $oldAvail = $old['available'] ?? '?';
        $newAvail = $new['available'] ?? '?';
        $oldStatus = $old['status'] ?? '?';
        $newStatus = $new['status'] ?? '?';
        $oldFunded = $old['funded_amount'] ?? '?';
        $newFunded = $new['funded_amount'] ?? '?';
        $kycStatus = $new['kyc_status'] ?? '?';

        return match (true) {
            $model === 'User' && $record->action === 'created'
                => "Регистрация на потребител: {$email}",
            $model === 'User' && $record->action === 'updated' && isset($new['kyc_status'])
                => "KYC статус променен на \"{$kycStatus}\" за потребител #{$id}",
            $model === 'User' && $record->action === 'updated' && isset($new['name'])
                => "Профил обновен за потребител #{$id}",

            $model === 'Wallet' && $record->action === 'updated' && isset($new['available'])
                => "Баланс променен: свободни {$oldAvail} → {$newAvail} €",
            $model === 'Wallet' && $record->action === 'created'
                => "Портфейл създаден",

            $model === 'Transaction' && $record->action === 'created'
                => self::describeTransaction($new),

            $model === 'Investment' && $record->action === 'created'
                => "Инвестиция: {$amount} € в кредит #{$loanId}",

            $model === 'Loan' && $record->action === 'created'
                => "Кредит създаден: {$amount} €, тип: {$type}",
            $model === 'Loan' && $record->action === 'updated' && isset($new['status'])
                => "Кредит #{$id}: статус {$oldStatus} → {$newStatus}",
            $model === 'Loan' && $record->action === 'updated' && isset($new['funded_amount'])
                => "Кредит #{$id}: финансирано {$oldFunded} → {$newFunded} €",

            $model === 'DepositRequest' && $record->action === 'created'
                => "Заявка за депозит: {$amount} €",
            $model === 'DepositRequest' && $record->action === 'updated' && isset($new['status'])
                => "Депозит #{$id}: {$oldStatus} → {$newStatus}",

            $model === 'WithdrawalRequest' && $record->action === 'created'
                => "Заявка за теглене: {$amount} €",
            $model === 'WithdrawalRequest' && $record->action === 'updated' && isset($new['status'])
                => "Теглене #{$id}: {$oldStatus} → {$newStatus}",

            default => ucfirst($record->action) . " {$model} #{$id}",
        };
    }

    private static function describeTransaction(array $new): string
    {
        $type = $new['type'] ?? '?';
        $amount = $new['amount'] ?? '?';
        $desc = $new['description'] ?? '';

        return match ($type) {
            'deposit' => "Депозит: +{$amount} € ({$desc})",
            'withdrawal' => "Теглене: -{$amount} € ({$desc})",
            'investment' => "Инвестиция: -{$amount} € ({$desc})",
            'repayment_principal' => "Погашение главница: +{$amount} € ({$desc})",
            'repayment_interest' => "Погашение лихва: +{$amount} € ({$desc})",
            'fee' => "Такса: -{$amount} € ({$desc})",
            default => "Транзакция: {$amount} € ({$type})",
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Дата и час')
                    ->dateTime('d.m.Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Потребител')
                    ->searchable()->default('Система'),
                Tables\Columns\BadgeColumn::make('action')->label('Действие')
                    ->colors(['success' => 'created', 'warning' => 'updated', 'danger' => 'deleted'])
                    ->formatStateUsing(fn (string $state) => match ($state) { 'created' => 'Създаване', 'updated' => 'Промяна', 'deleted' => 'Изтриване', default => $state }),
                Tables\Columns\TextColumn::make('model_type')->label('Обект')
                    ->formatStateUsing(fn (string $state) => match (class_basename($state)) {
                        'User' => 'Потребител', 'Wallet' => 'Портфейл', 'Transaction' => 'Транзакция',
                        'Investment' => 'Инвестиция', 'Loan' => 'Кредит', 'DepositRequest' => 'Депозит',
                        'WithdrawalRequest' => 'Теглене', default => class_basename($state),
                    }),
                Tables\Columns\TextColumn::make('id')->label('Описание')
                    ->formatStateUsing(fn ($state, $record) => self::describeAction($record))
                    ->wrap()->limit(80),
                Tables\Columns\TextColumn::make('ip_address')->label('IP'),
                Tables\Columns\TextColumn::make('model_id')->label('ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Действие')
                    ->options(['created' => 'Създаване', 'updated' => 'Промяна', 'deleted' => 'Изтриване']),
                Tables\Filters\SelectFilter::make('model_type')
                    ->label('Обект')
                    ->options(fn () => AuditLog::distinct()->pluck('model_type')
                        ->mapWithKeys(fn ($t) => [$t => match (class_basename($t)) {
                            'User' => 'Потребител', 'Wallet' => 'Портфейл', 'Transaction' => 'Транзакция',
                            'Investment' => 'Инвестиция', 'Loan' => 'Кредит', 'DepositRequest' => 'Депозит',
                            'WithdrawalRequest' => 'Теглене', default => class_basename($t),
                        }])->toArray()),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Потребител')
                    ->options(fn () => \App\Models\User::pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('От'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('До'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'], fn ($q) => $q->where('created_at', '>=', $data['from']))
                        ->when($data['to'], fn ($q) => $q->where('created_at', '<=', $data['to'] . ' 23:59:59'))),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist->schema([
            \Filament\Schemas\Components\Section::make('Информация за събитието')->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Дата и час')->dateTime('d.m.Y H:i:s'),
                Infolists\Components\TextEntry::make('user.name')->label('Потребител')->default('Система'),
                Infolists\Components\TextEntry::make('action')->label('Действие')->badge()
                    ->color(fn (string $state) => match ($state) { 'created' => 'success', 'updated' => 'warning', 'deleted' => 'danger', default => 'gray' }),
                Infolists\Components\TextEntry::make('model_type')->label('Обект')
                    ->formatStateUsing(fn (string $state) => class_basename($state)),
                Infolists\Components\TextEntry::make('model_id')->label('ID на запис'),
                Infolists\Components\TextEntry::make('ip_address')->label('IP адрес'),
                Infolists\Components\TextEntry::make('user_agent')->label('Браузър')->limit(100),
            ])->columns(3),
            \Filament\Schemas\Components\Section::make('Предишни стойности')->schema([
                Infolists\Components\ViewEntry::make('old_values')->label('Преди промяната')
                    ->view('filament.components.audit-values')
                    ->columnSpanFull(),
            ])->visible(fn ($record) => ! empty($record->old_values)),
            \Filament\Schemas\Components\Section::make('Нови стойности')->schema([
                Infolists\Components\ViewEntry::make('new_values')->label('След промяната')
                    ->view('filament.components.audit-values')
                    ->columnSpanFull(),
            ])->visible(fn ($record) => ! empty($record->new_values)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
