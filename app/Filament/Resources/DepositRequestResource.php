<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepositRequestResource\Pages;
use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DepositService;
use BackedEnum;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class DepositRequestResource extends Resource
{
    protected static ?string $model = DepositRequest::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Депозити';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
    protected static ?string $pluralModelLabel = 'Депозити';
    protected static ?string $modelLabel = 'Депозит';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Инвеститор')
                ->options(User::where('role', 'investor')->pluck('name', 'id'))
                ->searchable()->required(),
            Forms\Components\TextInput::make('amount')->label('Сума (€)')
                ->numeric()->required()->minValue(1)->step(0.01),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR'),
                Tables\Columns\TextColumn::make('reference_code')->label('Reference')->copyable(),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'pending' => 'Чакащ', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state })
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('admin_note')->label('Бележка')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Дата')->date('d.m.Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->where('amount', '>', 0))
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(['pending' => 'Чакащ', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен']),
            ])
            ->headerActions([
                // "Захрани сметка" — creates deposit AND immediately approves it
                \Filament\Actions\Action::make('credit_account')
                    ->label('Захрани сметка')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('user_id')->label('Инвеститор')
                            ->options(User::where('role', 'investor')->pluck('name', 'id'))
                            ->searchable()->required(),
                        Forms\Components\TextInput::make('amount')->label('Сума (€)')
                            ->numeric()->required()->minValue(1)->step(0.01),
                        Forms\Components\TextInput::make('note')->label('Бележка (reference от банков превод)')
                            ->placeholder('напр. банков превод от 30.03.2026'),
                    ])
                    ->action(function (array $data) {
                        $service = app(DepositService::class);
                        $deposit = $service->createRequest((int) $data['user_id'], number_format((float) $data['amount'], 2, '.', ''));
                        if ($data['note']) {
                            $deposit->update(['admin_note' => $data['note']]);
                        }
                        $service->approve($deposit->id, auth()->id());

                        $user = User::find($data['user_id']);
                        Notification::make()
                            ->title("Сметката на {$user->name} е захранена с {$data['amount']} €")
                            ->success()->send();
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('approve')->label('Одобри')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (DepositRequest $r) => $r->status === 'pending')->requiresConfirmation()
                    ->action(function (DepositRequest $r) {
                        app(DepositService::class)->approve($r->id, auth()->id());
                        Notification::make()->title('Депозит одобрен')->success()->send();
                    }),
                \Filament\Actions\Action::make('reject')->label('Отхвърли')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (DepositRequest $r) => $r->status === 'pending')
                    ->form([Forms\Components\Textarea::make('admin_note')->label('Причина')->required()])
                    ->action(function (DepositRequest $r, array $data) {
                        app(DepositService::class)->reject($r->id, auth()->id(), $data['admin_note']);
                        Notification::make()->title('Депозит отхвърлен')->danger()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepositRequests::route('/'),
        ];
    }
}
