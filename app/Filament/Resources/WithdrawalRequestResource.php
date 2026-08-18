<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use BackedEnum;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Тегления';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
    protected static ?string $pluralModelLabel = 'Тегления';
    protected static ?string $modelLabel = 'Теглене';
    protected static ?int $navigationSort = 3;

    /**
     * Wall-clock timezone for the dates in this list — prod's app tz is UTC,
     * the admin thinks in Sofia time. Same idiom as DepositRequestResource.
     */
    private const DISPLAY_TIMEZONE = 'Europe/Sofia';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR'),
                Tables\Columns\TextColumn::make('iban')->label('IBAN')->formatStateUsing(fn (WithdrawalRequest $r) => $r->maskedIban()),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'pending' => 'Чакащо', 'approved' => 'Одобрено', 'rejected' => 'Отхвърлено', 'processed' => 'Обработено', default => $state })
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected', 'info' => 'processed']),
                Tables\Columns\TextColumn::make('created_at')->label('Дата')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->sortable(),
                // Кога парите реално напускат сметката. Стъпва при «Одобри»
                // (там е debitReserved) и се пре-стъпва при «Обработено», щом
                // преводът е пуснат — чакащите заявки нямат такъв момент и
                // показват «—». Подредбата умишлено остава по датата на
                // ЗАЯВКАТА (Рени 2026-08-18): списъкът е работна опашка.
                Tables\Columns\TextColumn::make('processed_at')->label('Изплатен на')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Чакащо', 'approved' => 'Одобрено', 'rejected' => 'Отхвърлено', 'processed' => 'Обработено'])])
            ->actions([
                \Filament\Actions\Action::make('approve')->label('Одобри')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (WithdrawalRequest $r) => $r->status === 'pending')->requiresConfirmation()
                    ->action(function (WithdrawalRequest $r) { app(WithdrawalService::class)->approve($r->id, auth()->id()); Notification::make()->title('Теглене одобрено')->success()->send(); }),
                \Filament\Actions\Action::make('reject')->label('Отхвърли')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (WithdrawalRequest $r) => $r->status === 'pending')
                    ->form([Forms\Components\Textarea::make('admin_note')->label('Причина')->required()])
                    ->action(function (WithdrawalRequest $r, array $data) { app(WithdrawalService::class)->reject($r->id, auth()->id(), $data['admin_note']); Notification::make()->title('Теглене отхвърлено')->danger()->send(); }),
                \Filament\Actions\Action::make('mark_processed')->label('Обработено')->icon('heroicon-o-check')
                    ->visible(fn (WithdrawalRequest $r) => $r->status === 'approved')->requiresConfirmation()
                    ->action(function (WithdrawalRequest $r) {
                        \DB::transaction(function () use ($r) {
                            $r = WithdrawalRequest::where('id', $r->id)->where('status', 'approved')->lockForUpdate()->firstOrFail();
                            $r->update(['status' => 'processed', 'processed_at' => now()]);
                        });
                        Notification::make()->title('Обработено')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWithdrawalRequests::route('/')];
    }
}
