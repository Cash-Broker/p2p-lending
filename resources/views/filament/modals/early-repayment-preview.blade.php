{{--
    F3 — Early repayment execution modal preview.

    Rendered fresh at modal-open time (via ->modalContent() closure in
    LoanResource's execute_early_repayment row action). Receives either:
      - ($calc, $investorCount, $loan) populated + $error = null       → breakdown panel
      - ($error) populated + $calc = null                             → error panel

    Pure presentation. No business logic.
--}}

<div class="space-y-4 text-sm">
    @if ($error)
        {{-- Error state: calc could not be computed (e.g. no unpaid schedules,
             loan in inconsistent state). Submit button remains visible —
             the Action closure will catch the same exception and surface
             a danger toast, so admin cannot silently commit a bad call. --}}
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-950/40">
            <p class="font-semibold text-danger-700 dark:text-danger-400">
                Не може да се изпълни предсрочно погасяване
            </p>
            <p class="mt-2 text-danger-600 dark:text-danger-500">
                {{ $error }}
            </p>
        </div>
    @else
        <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Кредит</p>
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">#{{ $loan->id }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Първоначална сума</p>
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ number_format((float) $loan->amount, 2, '.', ',') }} €</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Срок</p>
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $loan->term_months }} мес.</p>
                </div>
            </div>
        </div>

        <table class="w-full">
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">Остатъчна главница</td>
                    <td class="py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ number_format((float) $calc->principal, 2, '.', ',') }} €
                    </td>
                </tr>
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">
                        Лихва (до следваща планирана вноска)
                    </td>
                    <td class="py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ number_format((float) $calc->interest, 2, '.', ',') }} €
                    </td>
                </tr>
                <tr class="bg-gray-100 dark:bg-gray-700">
                    <td class="py-2 px-2 font-bold text-gray-900 dark:text-gray-100">ОБЩО</td>
                    <td class="py-2 px-2 text-right text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ number_format((float) $calc->total, 2, '.', ',') }} €
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="text-gray-500 dark:text-gray-400">
            Ще бъде разпределено към
            <strong class="text-gray-900 dark:text-gray-100">{{ $investorCount }}</strong>
            {{ $investorCount === 1 ? 'инвеститор' : 'инвеститори' }} pro-rata спрямо инвестираните суми.
        </p>

        <div class="rounded-xl border border-warning-400 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950/30">
            <p class="text-warning-800 dark:text-warning-300">
                <strong>Внимание:</strong> След потвърждение разпределянето е <strong>неотменимо</strong>.
                Кредитът ще премине в статус <strong>„Изплатен"</strong>.
                Преди да продължите, потвърдете, че парите от кредитополучателя са постъпили в банковата сметка.
            </p>
        </div>
    @endif
</div>
