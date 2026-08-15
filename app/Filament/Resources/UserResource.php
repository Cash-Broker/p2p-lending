<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BonusCreditedNotification;
use App\Notifications\BonusGrantedAdminNotification;
use App\Notifications\KycStatusNotification;
use App\Services\TelegramService;
use App\Services\WalletService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

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
            // «Инвестирано»/«Свободни» + their totals read the wallet — load it
            // with the page instead of one query per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('wallet'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Имейл')->searchable(),
                // «Тип акаунт» (физическо/юридическо) is deliberately NOT a
                // column (boss 2026-08-11: «махни от началния екран на
                // потребителите физ лице / това да излиза като кликна на
                // него») — the list has to fit one screen without stretching.
                // It lives in the profile infolist, one click away. The FILTER
                // below stays: slicing by type costs the table no width.
                Tables\Columns\TextColumn::make('legalEntityProfile.legal_name')->label('Фирма')
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('role')->label('Роля')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'investor' => 'Инвеститор', 'admin' => 'Админ', default => $state
                    })
                    ->colors(['primary' => 'investor', 'danger' => 'admin']),
                Tables\Columns\BadgeColumn::make('kyc_status')->label('KYC')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state
                    })
                    ->colors(['warning' => 'pending', 'info' => 'submitted', 'primary' => 'in_review', 'success' => 'approved', 'danger' => 'rejected']),
                // «Кой с какви пари е в платформата» (boss 2026-08-10): the
                // invested bucket = principal currently deployed into loans.
                // Clicking through opens the USER'S OWN profile → «Инвестиции»
                // tab (only their rows + a total) — boss rejected the global
                // register as the destination («излизат и други хора»).
                Tables\Columns\TextColumn::make('wallet.invested')->label('Инвестирано')
                    ->money('EUR')
                    ->placeholder('—')
                    ->tooltip('Кликни за разбивка по кредити')
                    ->url(fn (User $record): ?string => $record->wallet !== null && bccomp((string) $record->wallet->invested, '0', 2) > 0
                        ? static::getUrl('view', ['record' => $record])
                        : null)
                    ->color('info')
                    ->sortable()
                    // «сумарно инвестирани и свободни, да не се налага да ги
                    // събирам» (boss 2026-08-11): live totals under the two
                    // money columns. They follow the current filter/search, so
                    // the same row answers «колко има платформата общо» and
                    // «колко има тази група» without any extra screen.
                    ->summarize(Sum::make('total')->label('Общо')->money('EUR')),
                Tables\Columns\TextColumn::make('wallet.available')->label('Свободни')
                    ->money('EUR')
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(Sum::make('total')->label('Общо')->money('EUR')),
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
                ViewAction::make(),
            ]);
    }

    /**
     * KYC review actions for the ViewUser page header — the reviewer
     * approves/rejects while looking at the documents.
     */
    public static function kycStatusActions(): array
    {
        return [
            Action::make('review_kyc')->label('В преглед')->icon('heroicon-o-eye')->color('primary')
                ->visible(fn (User $record) => $record->kyc_status === 'submitted')
                ->action(fn (User $record) => static::transitionKycStatus(
                    $record, from: ['submitted'], to: 'in_review',
                    notifyUserWith: null, successTitle: 'Профилът е маркиран „в преглед“', successColor: 'info',
                )),
            Action::make('approve_kyc')->label('Одобри KYC')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (User $record) => in_array($record->kyc_status, ['submitted', 'in_review'], true))
                ->requiresConfirmation()
                ->action(fn (User $record) => static::transitionKycStatus(
                    $record, from: ['submitted', 'in_review'], to: 'approved',
                    notifyUserWith: 'approved', successTitle: 'KYC одобрен', successColor: 'success',
                )),
            Action::make('reject_kyc')->label('Отхвърли KYC')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (User $record) => in_array($record->kyc_status, ['submitted', 'in_review'], true))
                ->requiresConfirmation()
                ->action(fn (User $record) => static::transitionKycStatus(
                    $record, from: ['submitted', 'in_review'], to: 'rejected',
                    notifyUserWith: 'rejected', successTitle: 'KYC отхвърлен', successColor: 'danger',
                )),
        ];
    }

    /**
     * «Начисли бонус» (boss 2026-08-09) — admin grants a promotional
     * credit (e.g. 100-200 € for bringing in a client) straight into the
     * investor's available balance, WITHOUT a deposit code: bonuses have
     * no bank wire behind them, so they must not enter the deposit flow.
     * The money lands as a TYPE_BONUS ledger row (investor sees «Бонус»
     * in transactions) + a mail/bell notification.
     */
    public static function bonusAction(): Action
    {
        return Action::make('grant_bonus')
            ->label('Начисли бонус')
            ->icon('heroicon-o-gift')
            ->color('success')
            ->visible(fn (User $record) => $record->isInvestor())
            ->modalHeading('Начисли бонус')
            ->modalDescription('Сумата се добавя директно към свободния баланс на потребителя и се записва като „Бонус“ (не като депозит — без банков превод зад нея).')
            ->form([
                TextInput::make('amount')
                    ->label('Сума (€)')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    // Fat-finger guard, not business policy — raise if the
                    // client ever wants bigger bonuses.
                    ->maxValue(10000)
                    ->rules(['decimal:0,2']),
                Textarea::make('reason')
                    ->label('Основание')
                    ->placeholder('напр. Бонус за препоръчан клиент')
                    ->required()
                    // transactions.description is VARCHAR(255) and the ledger
                    // row is written as «Бонус: {reason}» — 7-char prefix, so
                    // 248 is the longest reason that fits without a strict-mode
                    // 1406 overflow inside the money transaction.
                    ->maxLength(248),
            ])
            ->requiresConfirmation()
            ->action(fn (User $record, array $data) => static::grantBonus($record, $data['amount'], $data['reason']));
    }

    /**
     * The shared bonus-grant guts — called from the ViewUser header action
     * AND from the «Депозити» header action (where the user is identified
     * by their DEP code). Validation, replay guard, the wallet move,
     * investor + other-admin notifications, and BG toasts for every outcome.
     */
    public static function grantBonus(User $record, string $rawAmount, string $rawReason): void
    {
        try {
            $amount = Money::normalizePositive($rawAmount);
            $reason = trim($rawReason);

            if ($record->wallet === null) {
                Notification::make()->title('Потребителят няма портфейл')->danger()->send();

                return;
            }

            // Replay guard: a lost Livewire response tempts the admin
            // to resubmit, and nothing else distinguishes an accidental
            // second grant from an intended one (bonuses have no
            // backing entity to anchor idempotency on). An identical
            // (user, amount) bonus in the last 2 minutes is treated as
            // a duplicate; a deliberate repeat just waits them out.
            $recentDuplicate = Transaction::where('user_id', $record->id)
                ->where('type', Transaction::TYPE_BONUS)
                ->where('amount', $amount)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->exists();
            if ($recentDuplicate) {
                Notification::make()
                    ->title('Идентичен бонус вече е начислен')
                    ->body("Бонус от {$amount} € за {$record->name} е записан преди по-малко от 2 минути. Ако второто начисляване е нарочно, опитайте отново след 2 минути.")
                    ->warning()
                    ->send();

                return;
            }

            app(WalletService::class)->bonus(
                $record->id,
                $amount,
                'Бонус: '.$reason,
                // Per-grant unique reference — a duplicated row must be
                // distinguishable from two intended grants afterwards.
                'bonus:admin:'.auth()->id().':'.Str::uuid(),
            );

            // Money first, notifications after — failure logs, never rolls back.
            try {
                $record->notify(new BonusCreditedNotification($amount, $reason));
            } catch (\Throwable $e) {
                Log::warning('Failed to send bonus notification', [
                    'user_id' => $record->id, 'error' => $e->getMessage(),
                ]);
            }

            // Internal control: announce the grant to the OTHER admins
            // (bonus is the only money-in a single admin can mint alone).
            $grantedAt = now()->format('d.m.Y H:i');
            User::where('role', 'admin')->where('id', '!=', auth()->id())->get()
                ->each(function (User $admin) use ($record, $amount, $reason, $grantedAt) {
                    try {
                        $admin->notify(new BonusGrantedAdminNotification(
                            grantedByName: auth()->user()->name,
                            investorName: $record->name,
                            amount: $amount,
                            reason: $reason,
                            grantedAt: $grantedAt,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning('Failed to send bonus admin alert', [
                            'admin_id' => $admin->id, 'error' => $e->getMessage(),
                        ]);
                    }
                });

            // Telegram record (🟡 info, silent) — the bonus is money minted
            // by a single admin, so it must land in the shared channel too,
            // not only in the other admins' inboxes. TelegramService is a
            // no-op when unconfigured and never throws.
            app(TelegramService::class)->info(
                'Начислен бонус',
                auth()->user()->name." начисли бонус {$amount} € на {$record->name}.",
                ['Основание' => $reason],
            );

            Notification::make()
                ->title("Бонус {$amount} € е начислен")
                ->body("Потребителят {$record->name} получи бонуса в свободния си баланс.")
                ->success()
                ->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Невалидна сума')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            Log::error('Bonus grant failed', [
                'user_id' => $record->id, 'error' => $e->getMessage(),
            ]);
            Notification::make()->title('Грешка при начисляване на бонуса')->danger()->send();
        }
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
            Section::make('Профил')->schema([
                Infolists\Components\TextEntry::make('name')->label('Име'),
                Infolists\Components\TextEntry::make('email')->label('Имейл'),
                Infolists\Components\TextEntry::make('phone')->label('Телефон')->default('—'),
                // The list no longer carries this badge (boss 2026-08-11) — the
                // profile is now the ONLY place it shows, so spell it out in
                // full instead of the abbreviated table wording.
                Infolists\Components\TextEntry::make('account_type')->label('Тип акаунт')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'individual' => 'Физическо лице', 'legal_entity' => 'Юридическо лице', default => $state
                    })
                    ->color(fn (string $state) => match ($state) {
                        'legal_entity' => 'success', default => 'gray'
                    }),
                // Both entries used to print the raw column value («investor»,
                // «approved») — the only English left on a screen the admin
                // now opens for the account type. Same wording as the list.
                Infolists\Components\TextEntry::make('role')->label('Роля')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'investor' => 'Инвеститор', 'admin' => 'Админ', default => $state
                    })
                    ->color(fn (string $state) => match ($state) {
                        'admin' => 'danger', default => 'primary'
                    }),
                Infolists\Components\TextEntry::make('kyc_status')->label('KYC')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state
                    })
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'submitted' => 'info', 'in_review' => 'primary', 'rejected' => 'danger', default => 'warning'
                    }),
                Infolists\Components\TextEntry::make('created_at')->label('Регистрация')->date('d.m.Y'),
            ])->columns(3),

            Section::make('Фирмени данни')->schema([
                Infolists\Components\TextEntry::make('legalEntityProfile.legal_name')->label('Име на фирмата'),
                Infolists\Components\TextEntry::make('legalEntityProfile.eik')->label('ЕИК')->copyable()->fontFamily('mono'),
            ])->columns(2)->visible(fn ($record) => $record->isLegalEntity() && $record->legalEntityProfile),

            Section::make('Портфейл')->schema([
                Infolists\Components\TextEntry::make('wallet.available')->label('Свободни')->money('EUR'),
                Infolists\Components\TextEntry::make('wallet.invested')->label('Инвестирани')->money('EUR'),
                Infolists\Components\TextEntry::make('wallet.earned')->label('Спечелени')->money('EUR'),
            ])->columns(3),

            // Visit analytics (2026-08-15): «влизал ли е, колко често» —
            // entries = отделни влизания (30+ мин пауза), не рефреши.
            Section::make('Активност')->schema([
                Infolists\Components\TextEntry::make('dashboard_seen_at')
                    ->label('Последно в платформата')
                    ->state(fn ($record) => $record->dashboard_seen_at
                        ? $record->dashboard_seen_at->timezone('Europe/Sofia')->format('d.m.Y H:i').' ч.'
                        : 'никога'),
                Infolists\Components\TextEntry::make('visits_7d')
                    ->label('Влизания (7 дни)')
                    ->state(fn ($record) => $record->visitDays()
                        ->where('visit_date', '>=', now()->timezone('Europe/Sofia')->subDays(6)->toDateString())
                        ->sum('entries')),
                Infolists\Components\TextEntry::make('visits_30d')
                    ->label('Влизания (30 дни)')
                    ->state(fn ($record) => $record->visitDays()
                        ->where('visit_date', '>=', now()->timezone('Europe/Sofia')->subDays(29)->toDateString())
                        ->sum('entries')),
            ])->columns(3)->visible(fn ($record) => $record->isInvestor()),
            Section::make('KYC документ')->schema([
                Infolists\Components\TextEntry::make('kyc_status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state
                    })
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'submitted' => 'info', 'in_review' => 'primary', 'rejected' => 'danger', default => 'warning'
                    }),
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
            UserResource\RelationManagers\InvestmentsRelationManager::class,
            UserResource\RelationManagers\ConsentRecordsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'view' => Pages\ViewUser::route('/{record}')];
    }
}
