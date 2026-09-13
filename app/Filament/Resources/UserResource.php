<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\KycRetention;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\BonusCreditedNotification;
use App\Notifications\BonusGrantedAdminNotification;
use App\Notifications\KycStatusNotification;
use App\Rules\ValidPhone;
use App\Services\AccountDeletionService;
use App\Services\AccruedEarningsService;
use App\Services\BonusService;
use App\Services\TelegramService;
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
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
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
                // «Текущ баланс» (Reni 2026-09-13: «на всеки инвеститор освен
                // това което е инвестирал, текущия му баланс с начислените
                // лихви») = invested + interest earned to date on the open
                // positions — the investor's own «Текуща печалба», so the
                // column adds up to the «Текущо начислени лихви» card above.
                // The interest slice is spelled out underneath. NOT sortable:
                // the accrual is schedule math, not a column.
                Tables\Columns\TextColumn::make('invested_with_accrued_interest')->label('Текущ баланс')
                    ->state(fn (User $record, $livewire): ?string => static::investedWithAccruedInterestFor($record, $livewire))
                    ->money('EUR')
                    ->placeholder('—')
                    ->description(fn (User $record, $livewire): ?string => static::accruedInterestNote($record, $livewire))
                    ->tooltip('Инвестирано + лихвите, начислени до днес по график (при инвеститора — «Текуща печалба», по дни)')
                    ->color('success')
                    // Same recipe as the rows: Σ invested + the accrued-interest
                    // figure the header card shows — for the same filtered set.
                    ->summarize(Summarizer::make('total')->label('Общо')
                        ->using(fn (QueryBuilder $query, $livewire): string => static::investedWithAccruedInterestTotal($query, $livewire))
                        ->money('EUR')),
                Tables\Columns\TextColumn::make('wallet.available')->label('Свободни')
                    ->money('EUR')
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(Sum::make('total')->label('Общо')->money('EUR')),
                Tables\Columns\TextColumn::make('created_at')->label('Регистрация')->date('d.m.Y')->sortable(),
            ])
            // Без изричен defaultSort Filament подрежда по `id` ВЪЗХОДЯЩО —
            // най-новата регистрация оставаше на последната страница.
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('account_type')->label('Тип акаунт')
                    ->options(['individual' => 'Физическо лице', 'legal_entity' => 'Юридическо лице']),
                Tables\Filters\SelectFilter::make('role')->options(['investor' => 'Инвеститор', 'admin' => 'Админ']),
                Tables\Filters\SelectFilter::make('kyc_status')->options(['pending' => 'Очакващ', 'submitted' => 'Изпратен', 'in_review' => 'В преглед', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен']),
                // SEC-22: no new column (the list must fit one screen) — a filter instead.
                Tables\Filters\TernaryFilter::make('deletion_pending')->label('Заявено закриване')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('deletion_requested_at')->whereNull('deletion_finalized_at'),
                        false: fn (Builder $q) => $q->whereNull('deletion_requested_at'),
                    ),
            ])
            // The table stays clean: only "Преглед", at the default right end.
            // All KYC status actions live in the ViewUser page header — the
            // reviewer decides while looking at the documents.
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Interest this investor has earned to date on their open positions —
     * the figure they see as «Текуща печалба». Both pages hand us a per-render
     * memo (the list batches its rows; the profile computes its one record
     * ONCE — two entries built from two `now()` instants could straddle
     * midnight and stop adding up); anywhere else it is computed for the one
     * user through the SAME service scope, so no screen can disagree.
     */
    public static function accruedInterestFor(User $record, mixed $livewire = null): string
    {
        if ($livewire instanceof ListUsers || $livewire instanceof ViewUser) {
            return $livewire->accruedInterestFor($record);
        }

        return app(AccruedEarningsService::class)->accruedByUser([$record->id])[$record->id] ?? '0.00';
    }

    /**
     * «Текущ баланс» (the admin label) = invested + accrued interest to date.
     * Deliberately NOT named current_balance: `Wallet::currentBalance()` /
     * the API key `current_balance` are «Текущо салдо» = invested + the
     * `accrued` BUCKET only — a smaller figure (the bucket holds just the
     * capitalized plans' monthly milestones), so the two must not share an
     * identifier. Null (→ «—») for a user without a wallet, exactly like the
     * «Инвестирано» column beside it. The `accrued` bucket is NOT added on
     * top: it is a slice of the same interest already parked in the balance —
     * adding it would count those euros twice.
     */
    public static function investedWithAccruedInterestFor(User $record, mixed $livewire = null): ?string
    {
        if ($record->wallet === null) {
            return null;
        }

        return bcadd((string) $record->wallet->invested, static::accruedInterestFor($record, $livewire), 2);
    }

    /**
     * The line under the balance: how much of it is interest. Quiet when
     * nothing has accrued yet — a «вкл. 0,00 €» on every fresh row is noise.
     * Kept short on purpose («вкл.», not «от тях … начислени»): the wording
     * sets the column's width, and the list has to fit one screen (boss
     * 2026-08-11); the tooltip carries the full explanation.
     */
    public static function accruedInterestNote(User $record, mixed $livewire = null): ?string
    {
        if ($record->wallet === null) {
            return null;
        }

        $accrued = static::accruedInterestFor($record, $livewire);

        // Same locale fallback as ->money('EUR') on the figure above it, so
        // one cell never mixes two number formats.
        return bccomp($accrued, '0', 2) > 0
            ? 'вкл. '.Number::currency((float) $accrued, 'EUR', config('app.locale')).' лихви'
            : null;
    }

    /**
     * Column total for the table's current filter/search: Σ invested over
     * those users + the same accrued-interest total the «Текущо начислени
     * лихви» card shows for them. Filament hands the filtered table query
     * wrapped as a derived table aliased `users` (for the page footer with
     * its LIMIT/OFFSET inside); the wallet SUM takes it as an IN (...)
     * subquery, the accrual reads the page's shared memo so a footer never
     * re-walks positions the rows (or the other footer) already covered.
     */
    public static function investedWithAccruedInterestTotal(QueryBuilder $query, mixed $livewire = null): string
    {
        $userIds = $query->select('users.id');

        $invested = (string) Wallet::whereIn('user_id', $userIds)->sum('invested');

        $accrued = $livewire instanceof ListUsers
            ? $livewire->accruedInterestTotalFor($userIds->pluck('users.id')->map(fn ($id) => (int) $id)->all())
            : app(AccruedEarningsService::class)->accruedByPlan($userIds)['total'];

        return bcadd(bcadd($invested ?: '0', '0', 2), $accrued, 2);
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
    /**
     * Support tool (2026-08-25, same release as the mandatory-phone rule):
     * the ONLY investor-side write path for the now-mandatory phone is the
     * self-service PUT /api/profile — an investor phoning in a correction,
     * or stuck at the blocking modal with a format ValidPhone refuses, has
     * no other recourse. The action applies the SAME ValidPhone rule as the
     * API, so the admin cannot store a value the investor couldn't.
     */
    public static function phoneAction(): Action
    {
        return Action::make('edit_phone')
            ->label('Редактирай телефон')
            ->icon('heroicon-o-phone')
            ->color('gray')
            ->visible(fn (User $record) => $record->isInvestor())
            ->modalHeading('Редактирай телефон')
            ->form([
                TextInput::make('phone')
                    ->label('Телефон')
                    ->required()
                    ->maxLength(32)
                    ->rules([new ValidPhone])
                    ->default(fn (User $record) => $record->phone),
            ])
            ->action(function (User $record, array $data): void {
                try {
                    $record->update(['phone' => trim((string) $data['phone'])]);
                    Notification::make()->title('Телефонът е записан.')->success()->send();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Грешка при запис на телефона.')->danger()->send();
                }
            });
    }

    /**
     * SEC-22: an admin may withdraw an investor's pending closure request (with a
     * reason the investor receives by mail). There is deliberately NO «закрий сега»
     * — the waiting period is the control.
     */
    public static function cancelDeletionAction(): Action
    {
        return Action::make('cancel_deletion')
            ->label('Отмени закриването')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (User $record) => $record->hasOpenDeletionRequest())
            ->requiresConfirmation()
            ->modalHeading('Отмяна на заявеното закриване')
            ->form([
                Textarea::make('reason')->label('Причина')->required()->maxLength(255),
            ])
            ->action(function (User $record, array $data): void {
                try {
                    $cancelled = app(AccountDeletionService::class)->cancel($record, 'admin', trim((string) $data['reason']));
                    $record->refresh(); // the service wrote its own locked instance — the page keeps this one
                    if ($cancelled) {
                        Notification::make()->title('Закриването е отменено. Инвеститорът получи имейл.')->success()->send();
                    } else {
                        Notification::make()->title('Няма отворена заявка за закриване')->body('Инвеститорът (или системата) вече я е отменил — нищо не е променено.')->warning()->send();
                    }
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Грешка при отмяна.')->danger()->send();
                }
            });
    }

    /** SEC-16: the compliance archive of a closed account, when one exists. */
    public static function kycArchiveAction(): Action
    {
        return Action::make('kyc_archive')
            ->label('KYC архив')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->visible(fn (User $record) => KycRetention::where('user_id', $record->id)->exists())
            ->url(fn (User $record) => KycRetentionResource::getUrl('view', ['record' => KycRetention::where('user_id', $record->id)->value('id')]));
    }

    public static function bonusAction(): Action
    {
        return Action::make('grant_bonus')
            ->label('Начисли бонус')
            ->icon('heroicon-o-gift')
            ->color('success')
            ->visible(fn (User $record) => $record->isInvestor())
            ->modalHeading('Начисли бонус')
            ->modalDescription('Бонусът се записва като „Бонус“ (не като депозит — без банков превод зад нея) и стои ЗАКЛЮЧЕН, докато потребителят не инвестира сумата по-долу и не получи 3 погашения по нея.')
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
                TextInput::make('base_amount')
                    ->label('Сума, върху която се начислява (€)')
                    ->helperText('Например: бонус 50 € за инвестиция от 5000 € → тук се пише 5000. Бонусът се освобождава, когато инвеститорът има инвестиции за толкова и по тях са минали 3 погашения (при „Капитализация“ — на падежа).')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->rules(['decimal:0,2'])
                    // Filament helper, not a raw 'gte:amount' rule: inside an
                    // action the field lives at mountedActions.0.data.*, and a
                    // relative field name never resolves there.
                    ->gte('amount')
                    ->validationMessages(['gte' => 'Базата не може да е по-малка от самия бонус.']),
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
            ->action(fn (User $record, array $data) => static::grantBonus(
                $record, $data['amount'], $data['base_amount'], $data['reason'],
            ));
    }

    /**
     * The shared bonus-grant guts — called from the ViewUser header action
     * AND from the «Депозити» header action (where the user is identified
     * by their DEP code). Validation, replay guard, the wallet move,
     * investor + other-admin notifications, and BG toasts for every outcome.
     */
    public static function grantBonus(User $record, string $rawAmount, string $rawBaseAmount, string $rawReason): void
    {
        try {
            $amount = Money::normalizePositive($rawAmount);
            // The investment the bonus is calculated on (Reni 2026-08-18) —
            // the bonus stays locked until the investor has invested at least
            // this much and served the installments on it.
            $baseAmount = Money::normalizePositive($rawBaseAmount);
            $reason = trim($rawReason);

            if (bccomp($baseAmount, $amount, 2) < 0) {
                Notification::make()->title('Базата не може да е по-малка от бонуса')->danger()->send();

                return;
            }

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
                ->where('type', Transaction::TYPE_BONUS_LOCKED)
                ->where('amount', $amount)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->exists();
            if ($recentDuplicate) {
                Notification::make()
                    ->title('Идентичен бонус вече е начислен')
                    ->body('Бонус от '.$amount.' € за '.e($record->name).' е записан преди по-малко от 2 минути. Ако второто начисляване е нарочно, опитайте отново след 2 минути.')
                    ->warning()
                    ->send();

                return;
            }

            $grant = app(BonusService::class)->grantAdminBonus(
                $record,
                $amount,
                $baseAmount,
                $reason,
                (int) auth()->id(),
                // Per-grant unique reference — a duplicated row must be
                // distinguishable from two intended grants afterwards.
                'bonus:admin:'.auth()->id().':'.Str::uuid(),
            );

            // Money first, notifications after — failure logs, never rolls back.
            try {
                $record->notify(new BonusCreditedNotification(
                    $amount, $reason, $baseAmount, $grant->required_installments,
                ));
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
                ['Основание' => $reason, 'Отключва се при' => $baseAmount.' € инвестиции'],
            );

            Notification::make()
                ->title("Бонус {$amount} € е начислен")
                // e(): Filament renders notification bodies as SANITIZED HTML,
                // not as escaped text, so an investor-chosen name could smuggle
                // a live link into the admin panel (same guard as the
                // withdrawal bell in WithdrawalController).
                ->body('Бонусът стои заключен, докато '.e($record->name)." инвестира {$baseAmount} € и получи "
                    ."{$grant->required_installments} погашения по тях. Освобождава се автоматично.")
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
        $transitioned = DB::transaction(function () use ($record, $from, $to) {
            $user = User::where('id', $record->id)->lockForUpdate()->firstOrFail();
            if (! in_array($user->kyc_status, $from, true)) {
                return false;
            }
            $user->forceFill(['kyc_status' => $to])->save();

            return true;
        });

        // Refresh the bound instance so the row / view page re-renders with
        // the new status immediately.
        $record->refresh();

        if (! $transitioned) {
            Notification::make()->title('KYC статусът вече е променен')->warning()->send();

            return;
        }

        // Decision first, notification after commit. The mail used to be sent
        // INSIDE the transaction while holding the users row lock — an SMTP
        // failure rolled the approval back after the push/bell had already
        // gone out (the exact pattern CLAUDE.md documents from 2026-08-17),
        // and every SMTP round-trip held the lock (audit 2026-09-01, PAY-34).
        if ($notifyUserWith !== null) {
            try {
                $record->notify(new KycStatusNotification($notifyUserWith));
            } catch (\Throwable $e) {
                Log::warning('Failed to send KYC status notification', [
                    'user_id' => $record->id, 'status' => $to, 'error' => $e->getMessage(),
                ]);
            }
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
                // SEC-22: the open closure request, if any.
                Infolists\Components\TextEntry::make('deletion_state')->label('Закриване')->badge()
                    ->state(fn (User $r) => match ($r->deletionState()) {
                        'awaiting_confirmation' => 'Чака потвърждение по имейл',
                        'scheduled' => 'Планирано за '.$r->deletion_scheduled_for->copy()->timezone('Europe/Sofia')->format('d.m.Y'),
                        'finalized' => 'Закрит на '.$r->deletion_finalized_at->copy()->timezone('Europe/Sofia')->format('d.m.Y'),
                        default => null,
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'Планирано') ? 'danger' : 'warning')
                    ->visible(fn (User $r) => $r->deletionState() !== null),
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

            // First row reads as the sum it is: Инвестирани + Начислени лихви
            // = Текущ баланс (the list's column, split out here — Reni
            // 2026-09-13). Second row: cash on hand + interest paid out so far.
            Section::make('Портфейл')->schema([
                Infolists\Components\TextEntry::make('wallet.invested')->label('Инвестирани')->money('EUR'),
                Infolists\Components\TextEntry::make('accrued_interest')->label('Начислени лихви')
                    ->state(fn (User $record, $livewire): ?string => $record->wallet !== null ? static::accruedInterestFor($record, $livewire) : null)
                    ->money('EUR')
                    ->placeholder('—')
                    ->helperText('«Текуща печалба» при инвеститора — още неизплатени.'),
                Infolists\Components\TextEntry::make('invested_with_accrued_interest')->label('Текущ баланс')
                    ->state(fn (User $record, $livewire): ?string => static::investedWithAccruedInterestFor($record, $livewire))
                    ->money('EUR')
                    ->placeholder('—')
                    ->helperText('Инвестирани + начислени лихви.'),
                Infolists\Components\TextEntry::make('wallet.available')->label('Свободни')->money('EUR'),
                // `earned` = interest already PAID OUT (lifetime counter). The
                // investor's dashboard calls it «Изплатени» (Reni 2026-08-13);
                // next to «Начислени лихви» the old «Спечелени» read as the
                // same thing, so the admin label now matches the investor's.
                Infolists\Components\TextEntry::make('wallet.earned')->label('Изплатени лихви')->money('EUR'),
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
        return ['index' => ListUsers::route('/'), 'view' => ViewUser::route('/{record}')];
    }
}
