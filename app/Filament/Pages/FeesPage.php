<?php

namespace App\Filament\Pages;

use App\Models\PlatformSetting;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * F4 Такси configuration page — admin-facing surface for the two
 * `platform_settings` rows that control withdrawal fees:
 *
 *   fees_withdrawal_enabled (bool)  — master toggle
 *   fees_withdrawal_amount  (float) — flat EUR fee
 *
 * No dedicated `fees` table (single-tenant simplicity per Q11). The
 * generic PlatformSettingResource can technically edit these rows too,
 * but a dedicated page gives cleaner admin UX:
 *   - Clean "Такси" navigation (not buried in Настройки).
 *   - Both fields visible together with purpose-built help text.
 *   - Future-proof: origination / service / late / early-repayment
 *     fees can be added to the same form when those categories land.
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
                        ->helperText('При активиране WithdrawalService::approve() създава допълнителна TYPE_FEE транзакция, удържана от available баланса на инвеститора.'),

                    Forms\Components\TextInput::make('fees_withdrawal_amount')
                        ->label('Размер на таксата (EUR)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->rules(['numeric', 'min:0', 'max:100'])
                        ->helperText('Плоска такса в EUR, 2 знака след десетичната точка. Диапазон 0–100. Препоръка: 2.50 €.'),
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
}
