<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\ConsentRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only audit display of every consent the user gave at registration:
 * which document, which version, when, from which IP and browser.
 *
 * This is a legal-evidence ledger — the records are immutable in the DB and
 * we don't expose Create / Edit / Delete here either, to keep the admin UI
 * honest about what can and can't be changed. If a user disputes "I never
 * agreed", this page is what the operator shows.
 */
class ConsentRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'consentRecords';
    protected static ?string $title = 'Съгласия с правни документи';

    // Same reason as the investments tab: without these, Filament's generated
    // copy falls back to the model class name in English.
    protected static ?string $modelLabel = 'съгласие';

    protected static ?string $pluralModelLabel = 'съгласия';

    public function form(Schema $form): Schema
    {
        // Required by the parent contract but unused — there is no Create
        // action on this relation manager.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Документ')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        ConsentRecord::TYPE_TERMS   => 'Общи условия',
                        ConsentRecord::TYPE_PRIVACY => 'Поверителност',
                        ConsentRecord::TYPE_RISK    => 'Декларация за риска',
                        default => $state,
                    })
                    ->colors([
                        'primary' => ConsentRecord::TYPE_TERMS,
                        'success' => ConsentRecord::TYPE_PRIVACY,
                        'warning' => ConsentRecord::TYPE_RISK,
                    ]),

                Tables\Columns\TextColumn::make('version')->label('Версия'),

                Tables\Columns\TextColumn::make('accepted_at')
                    ->label('Прието на')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('user_agent')
                    ->label('Browser / OS')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->user_agent),
            ])
            ->defaultSort('accepted_at', 'desc')
            // Read-only: registration is the only writer; admins cannot
            // backdate, edit, or delete records (the table is part of the
            // legal evidence chain).
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
