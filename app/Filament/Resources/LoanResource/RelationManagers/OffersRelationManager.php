<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Enums\PayoutType;
use App\Models\LoanOffer;
use App\Services\OfferProjectionService;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Admin management of a loan's three investor offers (rate + availability).
 *
 * Implemented as a RelationManager — NOT a Repeater on the loan form — so it
 * saves through its own Livewire lifecycle and is completely untouched by
 * EditLoan::sanitizeSaveData / the loan's post-draft field freezing. That's
 * exactly why offers stay editable on already-published loans.
 *
 * Payout type is fixed (the three structures are seeded once); the boss edits
 * only the rate and the on/off toggle. Edits are gated to the statuses where
 * offers may still change (LoanOffer::EDITABLE_STATUSES); the model enforces
 * the same lock at the data layer.
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Оферти към инвеститорите';

    protected function offersEditable(): bool
    {
        // Client decision 2026-08-10: offers (like every loan field) stay
        // editable in EVERY status. Committed investors keep their
        // snapshotted rate/payout regardless of live-offer edits.
        return true;
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            // Read-only: which of the three structures this offer is.
            Forms\Components\Placeholder::make('payout_type_label')
                ->label('Тип изплащане')
                ->content(fn (?LoanOffer $record) => $record
                    ? $record->label().' — '.$record->payout_type->description()
                    : '—'),
            Forms\Components\TextInput::make('interest_rate')
                ->label('Годишна доходност (%)')
                ->helperText('Свободно число — задавате го за този кредит.')
                ->numeric()->required()->step(0.01)->minValue(0.01)->maxValue(999.99)
                ->rules([
                    'numeric', 'min:0.01', 'max:999.99',
                    // PAY-40 (audit 2026-09-01): the rate side of the same check
                    // the loan form runs on the term — see LoanResource::assertTermAmortizes.
                    fn (?LoanOffer $record, RelationManager $livewire): Closure => function (string $attribute, $value, Closure $fail) use ($record, $livewire) {
                        if ($record?->payout_type !== PayoutType::Amortizing || ! is_numeric($value)) {
                            return;
                        }

                        $term = (int) $livewire->getOwnerRecord()->term_months;

                        try {
                            app(OfferProjectionService::class)->schedule('50.00', (string) $value, $term, PayoutType::Amortizing);
                        } catch (InvalidArgumentException) {
                            $fail("При доходност {$value}% и срок {$term} мес. минималната инвестиция от 50 € не може да бъде амортизирана (месечната вноска не покрива лихвата). Намалете срока на кредита или сменете доходността.");
                        }
                    },
                ]),
            Forms\Components\Toggle::make('is_enabled')
                ->label('Активна оферта')
                ->helperText('Когато е изключена, офертата не се предлага на инвеститорите.')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('payout_type')
                    ->label('Оферта')
                    ->formatStateUsing(fn (LoanOffer $record) => $record->label()),
                Tables\Columns\TextColumn::make('payout_type_desc')
                    ->label('Как се изплаща')
                    ->getStateUsing(fn (LoanOffer $record) => $record->payout_type->description())
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('interest_rate')->label('Доходност')->suffix(' %')->sortable(),
                Tables\Columns\IconColumn::make('is_enabled')->label('Активна')->boolean(),
            ])
            ->defaultSort('position')
            ->actions([
                EditAction::make()->label('Редактирай')
                    ->visible(fn () => $this->offersEditable()),
            ]);
    }
}
