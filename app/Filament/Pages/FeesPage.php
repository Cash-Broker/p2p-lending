<?php

namespace App\Filament\Pages;

use App\Models\PlatformSetting;
use App\Models\Transaction;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * F4 Такси configuration page — admin-facing surface for the two
 * `platform_settings` rows that control withdrawal fees:
 *
 *   fees_withdrawal_enabled (bool)  — master toggle
 *   fees_withdrawal_amount  (float) — flat EUR fee
 *
 * Also surfaces:
 *   - Live preview: "При теглене X €: Такса Y €, Получавате X−Y €"
 *     that recomputes as the admin edits values (before save) —
 *     answers the "what will the next approved withdrawal actually
 *     charge?" question at a glance.
 *   - Running totals: all-time + this-month TYPE_FEE aggregate for
 *     quick reconciliation vs. admin's bank statement.
 *   - Recent 10 TYPE_FEE transactions for audit spot-check.
 *
 * No dedicated `fees` table (single-tenant simplicity per Q11). The
 * generic PlatformSettingResource can technically edit the same two
 * rows too, but this dedicated page gives cleaner admin UX:
 *   - Clean "Такси" navigation (not buried in Настройки).
 *   - Both fields + live preview visible together.
 *   - Future-proof: origination / service / late / early-repayment
 *     fees can be added as additional sections when those categories land.
 *
 * Audit: PlatformSetting uses the Auditable trait — every save writes
 * an audit_logs row with old/new value, admin, IP, UA.
 *
 * Defense in depth:
 *   - DB CHECK `chk_fees_withdrawal_amount_range` rejects out-of-range
 *     values at the database layer.
 *   - Filament numeric() + min/max on this form is the user-facing line.
 *
 * Access: admin-only via the existing `isAdmin()` role check.
 */
class FeesPage extends Page
{
    use \Filament\Schemas\Concerns\InteractsWithSchemas;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationLabel = 'Такси';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
    protected static ?int $navigationSort = 6;
    protected static ?string $title = 'Конфигурация на такси';
    protected string $view = 'filament.pages.fees';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'fees_withdrawal_enabled' => (bool) PlatformSetting::get('fees_withdrawal_enabled', false),
            'fees_withdrawal_amount'  => (string) PlatformSetting::get('fees_withdrawal_amount', '2.50'),
            'preview_amount'          => '100.00',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Такса при теглене')
                ->description('Плоска такса при одобряване на искане за теглене. Когато таксата е изключена, инвеститорът получава цялата заявена сума без удръжки.')
                ->schema([
                    Forms\Components\Toggle::make('fees_withdrawal_enabled')
                        ->label('Активна такса при теглене')
                        ->helperText('При активиране WithdrawalService::approve() създава допълнителна TYPE_FEE транзакция, удържана от reserved баланса на инвеститора (заедно с нетния TYPE_WITHDRAWAL).')
                        ->live(),

                    Forms\Components\TextInput::make('fees_withdrawal_amount')
                        ->label('Размер на таксата (EUR)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->rules(['numeric', 'min:0', 'max:100'])
                        ->helperText('Плоска такса в EUR, 2 знака след десетичната точка. Диапазон 0–100. Препоръка: 2.50 €.')
                        ->live(debounce: 400),
                ])
                ->columns(2),

            Section::make('Преглед')
                ->description('Симулация на ефекта върху следващо одобрение на теглене с текущите (неsave-нати) настройки.')
                ->schema([
                    Forms\Components\TextInput::make('preview_amount')
                        ->label('Тестова сума (EUR)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->default(100)
                        ->live(debounce: 400)
                        ->helperText('Промяната на сумата тук НЕ се запазва — само проверка.'),

                    Forms\Components\Placeholder::make('preview_breakdown')
                        ->label('Резултат')
                        ->content(fn (Forms\Get $get) => $this->renderPreview(
                            (bool) $get('fees_withdrawal_enabled'),
                            (string) ($get('fees_withdrawal_amount') ?? '0'),
                            (string) ($get('preview_amount') ?? '0'),
                        )),
                ])
                ->columns(2),

            Section::make()->schema([
                Forms\Components\Placeholder::make('warning')
                    ->label('')
                    ->content(new HtmlString(<<<'HTML'
<div class="space-y-3 text-sm text-gray-700">
    <p class="font-semibold text-gray-900">⚠️ Преди да активирате такса при теглене:</p>
    <ol class="list-decimal list-inside space-y-1 pl-1">
        <li>Потвърдете, че клиентката е готова за платена услуга.</li>
        <li>Обновете FAQ (<code class="text-xs">resources/js/components/landing/FaqSection.vue</code>) — от „безплатно" към конкретната стойност (напр. „2.50 € такса при теглене").</li>
        <li>Обновете ChatbotWidget (<code class="text-xs">resources/js/components/ChatbotWidget.vue</code>) съответно.</li>
        <li>Обмислете уведомяване на съществуващи инвеститори преди първото одобрение с такса.</li>
    </ol>
    <p class="text-xs text-gray-500 italic">Активирането е live веднага след запазване. Съществуващи pending заявки не се засягат — таксата се изчислява в момента на одобрението, не при създаване на заявката.</p>
</div>
HTML
                    )),
            ]),
        ])->statePath('data');
    }

    /**
     * Compute the live preview HTML from form state (not DB state) so the
     * admin sees what the current pending edits WILL do, even before save.
     */
    protected function renderPreview(bool $enabled, string $feeAmount, string $grossAmount): HtmlString
    {
        $grossStr = number_format(max(0.0, (float) $grossAmount), 2, '.', '');

        // Treat "enabled but fee = 0" as effectively disabled for the
        // preview (matches FeeService::getQuote which returns applies=false
        // when the amount is zero).
        $effectivelyDisabled = !$enabled || bccomp($feeAmount, '0', 2) <= 0;

        if ($effectivelyDisabled) {
            $disabledLabel = $enabled
                ? 'Таксата е на 0.00 € — не се удържа.'
                : 'Таксите са изключени.';
            return new HtmlString(<<<HTML
<div class="space-y-1 text-sm">
    <p>При теглене на <strong>{$grossStr} €</strong>:</p>
    <p>Такса: <strong class="text-gray-700">0.00 €</strong></p>
    <p>Получавате: <strong class="text-green-600">{$grossStr} €</strong></p>
    <p class="text-xs text-gray-500 italic mt-2">{$disabledLabel}</p>
</div>
HTML
            );
        }

        $feeStr = number_format((float) $feeAmount, 2, '.', '');
        $net = bcsub($grossStr, $feeStr, 2);

        if (bccomp($net, '0', 2) <= 0) {
            return new HtmlString(<<<HTML
<div class="space-y-1 text-sm">
    <p>При теглене на <strong>{$grossStr} €</strong>:</p>
    <p>Такса: <strong class="text-gray-900">{$feeStr} €</strong></p>
    <p class="text-red-600 font-medium">⚠️ Тестовата сума е по-малка от таксата — такова теглене ще бъде отхвърлено при одобрение.</p>
</div>
HTML
            );
        }

        return new HtmlString(<<<HTML
<div class="space-y-1 text-sm">
    <p>При теглене на <strong>{$grossStr} €</strong>:</p>
    <p>Такса: <strong class="text-gray-900">{$feeStr} €</strong></p>
    <p>Получавате: <strong class="text-green-600">{$net} €</strong></p>
</div>
HTML
        );
    }

    public function save(): void
    {
        $state = $this->form->getState();

        PlatformSetting::set('fees_withdrawal_enabled', (bool) $state['fees_withdrawal_enabled']);
        PlatformSetting::set('fees_withdrawal_amount', $state['fees_withdrawal_amount']);

        Notification::make()
            ->title('Настройките на такси са запазени')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Actions\Action::make('save')
                ->label('Запази')
                ->submit('save')
                ->color('primary'),
        ];
    }

    /**
     * View-side data — stats + recent fee transactions for the audit
     * summary below the form. Runs once per page render (not per form
     * interaction) since stats don't change with live form edits.
     */
    protected function getViewData(): array
    {
        $feeQuery = Transaction::query()->where('type', Transaction::TYPE_FEE);
        $monthStart = Carbon::now()->startOfMonth();

        return [
            'totalFeesAllTime' => (string) $feeQuery->clone()->sum('amount'),
            'feeCountAllTime'  => $feeQuery->clone()->count(),
            'totalFeesMonth'   => (string) $feeQuery->clone()->where('created_at', '>=', $monthStart)->sum('amount'),
            'feeCountMonth'    => $feeQuery->clone()->where('created_at', '>=', $monthStart)->count(),
            'recentFees'       => $feeQuery->clone()
                ->with('user:id,name')
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ];
    }
}
