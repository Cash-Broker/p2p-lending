<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Originator;
use BackedEnum;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Кредити';
    protected static ?string $pluralModelLabel = 'Кредити';
    protected static ?string $modelLabel = 'Кредит';
    protected static ?int $navigationSort = 4;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            \Filament\Schemas\Components\Section::make('Основни данни')->schema([
                Forms\Components\Select::make('originator_id')->label('Оригинатор')->options(Originator::pluck('name', 'id'))->required()->searchable(),
                Forms\Components\Select::make('borrower_id')->label('Кредитополучател')
                    ->options(fn () => Borrower::all()->pluck('full_name', 'id'))
                    ->required()->searchable(),
                Forms\Components\Select::make('type')->label('Тип')->options(['consumer' => 'Потребителски', 'business' => 'Бизнес', 'mortgage' => 'Ипотечен', 'bridge' => 'Мостов'])->required(),
                Forms\Components\Select::make('status')->label('Статус')->options([
                    'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се',
                    'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял',
                    'default' => 'Просрочен', 'repaid' => 'Изплатен',
                ])->default('draft')->required(),
            ])->columns(2),
            \Filament\Schemas\Components\Section::make('Финансови параметри')->schema([
                Forms\Components\TextInput::make('amount')->label('Сума (€)')->numeric()->required()->minValue(100),
                Forms\Components\TextInput::make('interest_rate')->label('Доходност (%)')->numeric()->required()->step(0.01),
                Forms\Components\TextInput::make('interest_rate_annual')->label('Лихва кредитополучател (%)')->numeric()->required()->step(0.01),
                Forms\Components\TextInput::make('term_months')->label('Срок (месеци)')->numeric()->required()->minValue(1),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('originator.name')->label('Оригинатор'),
                Tables\Columns\TextColumn::make('type')->label('Тип'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('funded_amount')->label('Финансирано')->money('EUR'),
                Tables\Columns\TextColumn::make('interest_rate')->label('Доходност')->suffix('%'),
                Tables\Columns\TextColumn::make('term_months')->label('Срок')->suffix(' мес.'),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се', 'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял', 'default' => 'Просрочен', 'repaid' => 'Изплатен', default => $state })
                    ->colors(['secondary' => 'draft', 'primary' => 'published', 'info' => 'funding', 'success' => fn ($state) => in_array($state, ['funded', 'active']), 'warning' => 'late', 'danger' => 'default', 'gray' => 'repaid']),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се',
                    'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял',
                    'default' => 'Просрочен', 'repaid' => 'Изплатен',
                ]),
                Tables\Filters\SelectFilter::make('originator_id')->label('Оригинатор')->options(Originator::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('type')->options(['consumer' => 'Потребителски', 'business' => 'Бизнес', 'mortgage' => 'Ипотечен', 'bridge' => 'Мостов']),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('publish')->label('Публикувай')->icon('heroicon-o-globe-alt')->color('success')
                    ->visible(fn (Loan $r) => $r->status === Loan::STATUS_DRAFT)->requiresConfirmation()
                    ->action(function (Loan $r) { $r->forceFill(['status' => Loan::STATUS_PUBLISHED, 'published_at' => now()])->save(); Notification::make()->title('Публикуван')->success()->send(); }),
                \Filament\Actions\Action::make('unpublish')->label('Спри')->icon('heroicon-o-pause-circle')->color('warning')
                    ->visible(fn (Loan $r) => $r->status === Loan::STATUS_PUBLISHED && bccomp($r->funded_amount, '0', 2) <= 0)->requiresConfirmation()
                    ->action(function (Loan $r) { $r->forceFill(['status' => Loan::STATUS_DRAFT, 'published_at' => null])->save(); Notification::make()->title('Спрян')->warning()->send(); }),
                \Filament\Actions\Action::make('activate')->label('Активирай')->icon('heroicon-o-play')->color('success')
                    ->visible(fn (Loan $r) => $r->status === Loan::STATUS_FUNDED)->requiresConfirmation()
                    ->modalDescription('Кредитът ще стане активен и ще започнат погашения.')
                    ->action(function (Loan $r) { $r->forceFill(['status' => Loan::STATUS_ACTIVE])->save(); Notification::make()->title('Кредитът е активиран')->success()->send(); }),
            ]);
    }

    public static function getRelations(): array
    {
        return [LoanResource\RelationManagers\AmortizationSchedulesRelationManager::class, LoanResource\RelationManagers\InvestmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLoans::route('/'), 'create' => Pages\CreateLoan::route('/create'), 'edit' => Pages\EditLoan::route('/{record}/edit')];
    }
}
