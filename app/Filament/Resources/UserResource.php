<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Notifications\KycStatusNotification;
use BackedEnum;
use Illuminate\Support\Facades\DB;
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

    // Always-visible reminder in the sidebar: how many users are waiting for
    // KYC approval (submitted or already being reviewed). Returns null (no
    // badge) when there's nothing to action.
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::whereIn('kyc_status', ['submitted', 'in_review'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Чакащи KYC верификации';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Имейл')->searchable(),
                Tables\Columns\BadgeColumn::make('account_type')->label('Тип акаунт')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'individual' => 'Физическо', 'legal_entity' => 'Юридическо', default => $state })
                    ->colors(['gray' => 'individual', 'success' => 'legal_entity']),
                Tables\Columns\TextColumn::make('legalEntityProfile.legal_name')->label('Фирма')
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('role')->label('Роля')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'investor' => 'Инвеститор', 'admin' => 'Админ', default => $state })
                    ->colors(['primary' => 'investor', 'danger' => 'admin']),
                Tables\Columns\BadgeColumn::make('kyc_status')->label('KYC')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state })
                    ->colors(['warning' => 'pending', 'info' => 'submitted', 'primary' => 'in_review', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('wallet.available')->label('Баланс')->money('EUR'),
                Tables\Columns\TextColumn::make('created_at')->label('Регистрация')->date('d.m.Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('account_type')->label('Тип акаунт')
                    ->options(['individual' => 'Физическо лице', 'legal_entity' => 'Юридическо лице']),
                Tables\Filters\SelectFilter::make('role')->options(['investor' => 'Инвеститор', 'admin' => 'Админ']),
                Tables\Filters\SelectFilter::make('kyc_status')->options(['pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен']),
            ])
            // The table stays clean: only "Преглед", at the default right end.
            // All KYC status actions live in the ViewUser page header — the
            // reviewer decides while looking at the documents.
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ]);
    }

    /**
     * KYC review actions for the ViewUser page header — the reviewer
     * approves/rejects while looking at the documents.
     */
    public static function kycStatusActions(): array
    {
        return [
            \Filament\Actions\Action::make('review_kyc')->label('В преглед')->icon('heroicon-o-eye')->color('primary')
                ->visible(fn (User $record) => $record->kyc_status === 'submitted')
                ->action(fn (User $record) => static::transitionKycStatus(
                    $record, from: ['submitted'], to: 'in_review',
                    notifyUserWith: null, successTitle: 'Профилът е маркиран „в преглед“', successColor: 'info',
                )),
            \Filament\Actions\Action::make('approve_kyc')->label('Одобри KYC')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (User $record) => in_array($record->kyc_status, ['submitted', 'in_review'], true))
                ->requiresConfirmation()
                ->action(fn (User $record) => static::transitionKycStatus(
                    $record, from: ['submitted', 'in_review'], to: 'approved',
                    notifyUserWith: 'approved', successTitle: 'KYC одобрен', successColor: 'success',
                )),
            \Filament\Actions\Action::make('reject_kyc')->label('Отхвърли KYC')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (User $record) => in_array($record->kyc_status, ['submitted', 'in_review'], true))
                ->requiresConfirmation()
                ->action(fn (User $record) => static::transitionKycStatus(
                    $record, from: ['submitted', 'in_review'], to: 'rejected',
                    notifyUserWith: 'rejected', successTitle: 'KYC отхвърлен', successColor: 'danger',
                )),
        ];
    }

    protected static function transitionKycStatus(
        User $record,
        array $from,
        string $to,
        ?string $notifyUserWith,
        string $successTitle,
        string $successColor,
    ): void {
        $transitioned = DB::transaction(function () use ($record, $from, $to, $notifyUserWith) {
            $user = User::where('id', $record->id)->lockForUpdate()->firstOrFail();
            if (! in_array($user->kyc_status, $from, true)) {
                return false;
            }
            $user->forceFill(['kyc_status' => $to])->save();
            if ($notifyUserWith !== null) {
                $user->notify(new KycStatusNotification($notifyUserWith));
            }

            return true;
        });

        // Refresh the bound instance so the row / view page re-renders with
        // the new status immediately.
        $record->refresh();

        if (! $transitioned) {
            Notification::make()->title('KYC статусът вече е променен')->warning()->send();

            return;
        }

        $notification = Notification::make()->title($successTitle);
        match ($successColor) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };
        $notification->send();
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist->schema([
            \Filament\Schemas\Components\Section::make('Профил')->schema([
                Infolists\Components\TextEntry::make('name')->label('Име'),
                Infolists\Components\TextEntry::make('email')->label('Имейл'),
                Infolists\Components\TextEntry::make('phone')->label('Телефон')->default('—'),
                Infolists\Components\TextEntry::make('account_type')->label('Тип акаунт')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'individual' => 'Физическо', 'legal_entity' => 'Юридическо', default => $state })
                    ->color(fn (string $state) => match ($state) { 'legal_entity' => 'success', default => 'gray' }),
                Infolists\Components\TextEntry::make('role')->label('Роля')->badge(),
                Infolists\Components\TextEntry::make('kyc_status')->label('KYC')->badge(),
                Infolists\Components\TextEntry::make('created_at')->label('Регистрация')->date('d.m.Y'),
            ])->columns(3),

            \Filament\Schemas\Components\Section::make('Фирмени данни')->schema([
                Infolists\Components\TextEntry::make('legalEntityProfile.legal_name')->label('Име на фирмата'),
                Infolists\Components\TextEntry::make('legalEntityProfile.eik')->label('ЕИК')->copyable()->fontFamily('mono'),
            ])->columns(2)->visible(fn ($record) => $record->isLegalEntity() && $record->legalEntityProfile),

            \Filament\Schemas\Components\Section::make('Портфейл')->schema([
                Infolists\Components\TextEntry::make('wallet.available')->label('Свободни')->money('EUR'),
                Infolists\Components\TextEntry::make('wallet.invested')->label('Инвестирани')->money('EUR'),
                Infolists\Components\TextEntry::make('wallet.earned')->label('Спечелени')->money('EUR'),
            ])->columns(3),
            \Filament\Schemas\Components\Section::make('KYC документ')->schema([
                Infolists\Components\TextEntry::make('kyc_status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state })
                    ->color(fn (string $state) => match ($state) { 'approved' => 'success', 'submitted' => 'info', 'in_review' => 'primary', 'rejected' => 'danger', default => 'warning' }),
                Infolists\Components\ViewEntry::make('kyc_selfie_path')->label('Селфи за верификация')
                    ->view('filament.components.kyc-image')
                    ->columnSpanFull(),
                Infolists\Components\ViewEntry::make('kyc_document_front_path')->label('Лицева страна (отпред)')
                    ->view('filament.components.kyc-image')
                    ->columnSpanFull(),
                Infolists\Components\ViewEntry::make('kyc_document_back_path')->label('Гръб (отзад)')
                    ->view('filament.components.kyc-image')
                    ->columnSpanFull(),
            ])->columns(2)->visible(fn ($record) => $record->kyc_document_front_path !== null || $record->kyc_selfie_path !== null),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            UserResource\RelationManagers\ConsentRecordsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'view' => Pages\ViewUser::route('/{record}')];
    }
}
