{{--
    Предсрочно приключване (Рени 2026-08-18) — преглед преди изпълнение.

    Рендерира се свежо при отваряне на модала (LoanResource::earlyClosureAction /
    partialClosureAction), точно както при buyback: числата се движат с всеки
    изминал ден, затова никога не се показва кеширана оферта.

    Получава: ($quote, $loan, $asOf) при успех, или ($error) при невъзможност.
    Чиста презентация — без бизнес логика.
--}}

<div class="space-y-4 text-sm">
    @if ($error)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-950/40">
            <p class="font-semibold text-danger-700 dark:text-danger-400">
                Не може да се изпълни предсрочно приключване
            </p>
            <p class="mt-2 text-danger-600 dark:text-danger-500">{{ $error }}</p>
        </div>
    @else
        <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Кредит</p>
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">#{{ $loan->id }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Остатъчна главница</p>
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        {{ number_format((float) $quote['outstanding_total'], 2, '.', ' ') }} €
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Лихва към</p>
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $asOf->format('d.m.Y') }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex flex-wrap justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Връщана главница</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">
                        {{ number_format((float) $quote['principal_total'], 2, '.', ' ') }} €
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Лихва за ползвания период</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">
                        {{ number_format((float) $quote['interest_total'], 2, '.', ' ') }} €
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Общо към инвеститорите</p>
                    <p class="text-lg font-bold text-primary-600 dark:text-primary-400">
                        {{ number_format((float) $quote['total'], 2, '.', ' ') }} €
                    </p>
                </div>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                @if ($quote['is_full'])
                    Приключва се <strong>целият</strong> кредит — оставащите вноски се отменят и кредитът минава в „Погасен“.
                @else
                    Приключват се <strong>{{ number_format((float) $quote['ratio'] * 100, 2, '.', ' ') }}%</strong>
                    от позицията на всеки инвеститор. Броят вноски и падежите остават същите — намалява се размерът им.
                @endif
                Лихвата е на база 30/360 за реално ползвания период.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Инвеститор</th>
                        <th class="py-2 pr-3 font-medium">План</th>
                        <th class="py-2 pr-3 text-right font-medium">Остатък</th>
                        <th class="py-2 pr-3 text-right font-medium">Главница</th>
                        <th class="py-2 pr-3 text-right font-medium">Лихва</th>
                        <th class="py-2 text-right font-medium">Общо</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($quote['positions'] as $position)
                        <tr>
                            <td class="py-2 pr-3 text-gray-900 dark:text-gray-100">
                                {{ $position['investment']->user?->name ?? '—' }}
                            </td>
                            <td class="py-2 pr-3 text-gray-500 dark:text-gray-400">
                                {{ $position['payout_type']->label() }}
                            </td>
                            <td class="py-2 pr-3 text-right text-gray-500 dark:text-gray-400">
                                {{ number_format((float) $position['outstanding'], 2, '.', ' ') }} €
                            </td>
                            <td class="py-2 pr-3 text-right text-gray-900 dark:text-gray-100">
                                {{ number_format((float) $position['principal'], 2, '.', ' ') }} €
                            </td>
                            <td class="py-2 pr-3 text-right text-gray-900 dark:text-gray-100">
                                {{ number_format((float) $position['interest'], 2, '.', ' ') }} €
                            </td>
                            <td class="py-2 text-right font-semibold text-gray-900 dark:text-gray-100">
                                {{ number_format((float) $position['total'], 2, '.', ' ') }} €
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
