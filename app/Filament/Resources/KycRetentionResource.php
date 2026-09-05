<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KycRetentionResource\Pages;
use App\Models\ConsentRecord;
use App\Models\KycRetention;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * SEC-16 «KYC архив» — read-only register of the identity documents and consent
 * records kept after an account closure (ЗМИП clock). Every view is audited.
 */
class KycRetentionResource extends Resource
{
    protected static ?string $model = KycRetention::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static ?string $pluralModelLabel = 'KYC архив';

    protected static ?string $modelLabel = 'KYC архив';

    protected static ?int $navigationSort = 90;

    private const DISPLAY_TIMEZONE = 'Europe/Sofia';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $due = KycRetention::due(today())->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Архиви с изтекъл срок, чакащи нощното заличаване';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user_id')->label('Изтрит потребител #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('account_type')->label('Тип')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'legal_entity' ? 'Юр. лице' : 'Физ. лице'),
                Tables\Columns\TextColumn::make('kyc_status_at_deletion')->label('KYC при закриване')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', 'submitted' => 'Подаден', 'in_review' => 'В преглед', 'pending' => 'Без документи', default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Закрит на')->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)->sortable(),
                Tables\Columns\TextColumn::make('retained_until')->label('Пази се до')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->state(fn (KycRetention $r) => $r->isPurged() ? 'purged' : ($r->isDue() ? 'due' : 'active'))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'purged' => 'Заличен', 'due' => 'За заличаване', default => 'Активен'
                    })
                    ->color(fn (string $state) => match ($state) {
                        'purged' => 'gray', 'due' => 'warning', default => 'success'
                    }),
                Tables\Columns\TextColumn::make('purged_at')->label('Заличен на')->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Статус')
                    ->options(['active' => 'Активен', 'due' => 'За заличаване', 'purged' => 'Заличен'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('purged_at')->whereDate('retained_until', '>', today()->toDateString()),
                        'due' => $query->due(today()),
                        'purged' => $query->whereNotNull('purged_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Преглед'),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        $purged = fn (KycRetention $r) => $r->isPurged();
        $snapshot = fn (KycRetention $r, string $key) => $r->isPurged() ? '— заличено —' : ($r->subject_snapshot[$key] ?? '—');

        return $infolist->schema([
            Section::make('Субект')->schema([
                Infolists\Components\TextEntry::make('user_id')->label('Потребител #')
                    ->url(fn (KycRetention $r) => UserResource::getUrl('view', ['record' => $r->user_id])),
                Infolists\Components\TextEntry::make('subject_name')->label('Име при закриване')->state(fn (KycRetention $r) => $snapshot($r, 'name')),
                Infolists\Components\TextEntry::make('subject_email')->label('Имейл при закриване')->state(fn (KycRetention $r) => $snapshot($r, 'email')),
                Infolists\Components\TextEntry::make('subject_phone')->label('Телефон при закриване')->state(fn (KycRetention $r) => $snapshot($r, 'phone')),
                Infolists\Components\TextEntry::make('account_type')->label('Тип')
                    ->formatStateUsing(fn (string $state) => $state === 'legal_entity' ? 'Юридическо лице' : 'Физическо лице'),
                Infolists\Components\TextEntry::make('kyc_status_at_deletion')->label('KYC при закриване'),
            ])->columns(3),

            Section::make('Срок')->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Закрит на')->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE),
                Infolists\Components\TextEntry::make('retention_years')->label('Години')->suffix(' г.'),
                Infolists\Components\TextEntry::make('retained_until')->label('Пази се до')->date('d.m.Y'),
                Infolists\Components\TextEntry::make('purged_at')->label('Заличен на')->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)->placeholder('—'),
            ])->columns(4),

            Section::make('Документи')->schema([
                Infolists\Components\ViewEntry::make('kyc_document_front_path')->label('Лицева страна')
                    ->view('filament.components.kyc-retained-image', ['kind' => KycRetention::KIND_FRONT]),
                Infolists\Components\ViewEntry::make('kyc_document_back_path')->label('Гръб')
                    ->view('filament.components.kyc-retained-image', ['kind' => KycRetention::KIND_BACK]),
                Infolists\Components\ViewEntry::make('kyc_selfie_path')->label('Селфи')
                    ->view('filament.components.kyc-retained-image', ['kind' => KycRetention::KIND_SELFIE]),
            ])->columns(3)->hidden($purged),

            Section::make('Съгласия')->schema([
                Infolists\Components\TextEntry::make('consents')->label('')
                    ->listWithLineBreaks()
                    ->state(fn (KycRetention $r) => $r->isPurged() || empty($r->consent_snapshot)
                        ? [$r->isPurged() ? 'Заличено' : 'Няма записани съгласия']
                        : collect($r->consent_snapshot)->map(fn (array $c) => sprintf(
                            '%s %s — %s · IP %s',
                            self::consentLabel($c['type'] ?? '?'),
                            $c['version'] ?? '',
                            isset($c['accepted_at']) ? Carbon::parse($c['accepted_at'])->timezone(self::DISPLAY_TIMEZONE)->format('d.m.Y H:i') : '—',
                            $c['ip_address'] ?? '—',
                        ))->all())
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function consentLabel(string $type): string
    {
        return match ($type) {
            ConsentRecord::TYPE_TERMS => 'Общи условия',
            ConsentRecord::TYPE_PRIVACY => 'Поверителност',
            ConsentRecord::TYPE_RISK => 'Рискове',
            ConsentRecord::TYPE_BIOMETRIC => 'Биометрични данни (KYC)',
            default => $type,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKycRetentions::route('/'),
            'view' => Pages\ViewKycRetention::route('/{record}'),
        ];
    }
}
