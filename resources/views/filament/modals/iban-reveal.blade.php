{{--
    SEC-11 (audit 2026-09-01, owner 2026-09-03) — everything the operator needs
    for the bank transfer, shown ONLY after the admin re-entered their password
    and the reveal was written to audit_logs (WithdrawalRequestResource::revealIbanAction).

    Receives: $iban (string|null — null when the page-scoped reveal grant is gone),
    $request (WithdrawalRequest), $wireAmount (string, the NET amount from the
    ledger row), $beneficiary (string), $lastReveal (AuditLog|null). Pure presentation.
--}}

<div class="space-y-4 text-sm" x-data="{ copied: null }">
    @if ($iban === null)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-950/40">
            <p class="font-semibold text-warning-700 dark:text-warning-400">Няма валидно потвърждение</p>
            <p class="mt-2 text-warning-600 dark:text-warning-500">Затворете прозореца и използвайте «Покажи IBAN» отново — потвърждението важи 2 минути, докато тази страница е отворена.</p>
        </div>
    @else
        <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800">
            <dl class="grid grid-cols-3 gap-x-4 gap-y-2">
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Получател</dt>
                <dd class="col-span-2 font-medium text-gray-900 dark:text-gray-100">{{ $beneficiary }}</dd>

                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Сума за превод</dt>
                <dd class="col-span-2 flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                    <span>{{ number_format((float) $wireAmount, 2, ',', ' ') }} €</span>
                    <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($wireAmount)).then(() => { copied = 'amount'; setTimeout(() => copied = null, 2000) })">
                        <span x-show="copied !== 'amount'">Копирай</span>
                        <span x-show="copied === 'amount'" x-cloak>✓</span>
                    </x-filament::button>
                </dd>
                @if (bccomp((string) ($request->fee_quoted ?? '0'), '0', 2) > 0)
                    <dt></dt>
                    <dd class="col-span-2 text-xs text-gray-500 dark:text-gray-400">(заявени {{ $request->amount }} €, такса {{ $request->fee_quoted }} €)</dd>
                @endif

                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Основание</dt>
                <dd class="col-span-2 text-gray-900 dark:text-gray-100">Теглене #{{ $request->id }}</dd>
            </dl>

            <p class="mt-4 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">IBAN</p>
            <p class="mt-1 select-all font-mono text-lg tracking-wider text-gray-900 dark:text-gray-100">{{ $iban }}</p>

            <div class="mt-3 flex items-center gap-3">
                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-o-clipboard-document"
                    x-on:click="navigator.clipboard.writeText(@js($iban)).then(() => { copied = 'iban'; setTimeout(() => copied = null, 2000) })"
                >
                    <span x-show="copied !== 'iban'">Копирай IBAN</span>
                    <span x-show="copied === 'iban'" x-cloak>Копирано ✓</span>
                </x-filament::button>
                <span class="text-xs text-gray-500 dark:text-gray-400">Показването е записано в одитния дневник.</span>
            </div>
        </div>

        @if ($lastReveal)
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-3 text-warning-700 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-400">
                Показван преди това от {{ $lastReveal->user?->name ?? 'админ #'.$lastReveal->user_id }}
                на {{ $lastReveal->created_at->timezone('Europe/Sofia')->format('d.m.Y H:i') }} — проверете дали преводът не е вече нареден.
            </div>
        @endif

        @if ($request->status === 'approved')
            <p class="text-xs text-gray-500 dark:text-gray-400">След превода натиснете «Обработено» на реда на заявката.</p>
        @endif
    @endif
</div>
