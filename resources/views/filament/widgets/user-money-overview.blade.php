{{--
    «Паричен поток» над списъка с потребители.

    Стиловете стъпват на класовете, които самият Filament ползва (панелът няма
    собствена тема, тоест произволни Tailwind класове може да липсват в
    компилирания CSS). Всичко цветно/динамично е inline — така изгледът не
    зависи от билд стъпка.
--}}

<div class="fi-wi-stats-overview">
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

        {{-- Трите основни числа --}}
        <div class="grid gap-px overflow-hidden rounded-t-xl bg-gray-100 sm:grid-cols-3 dark:bg-white/10">
            <div class="bg-white p-6 dark:bg-gray-900" style="border-top: 3px solid #1B2A4A;">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Инвестирани общо
                </p>
                <p class="mt-2 text-3xl font-bold text-gray-950 dark:text-white">{{ $invested }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">По текущия филтър и търсене</p>
            </div>

            <div class="bg-white p-6 dark:bg-gray-900" style="border-top: 3px solid #22C55E;">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Свободни общо
                </p>
                <p class="mt-2 text-3xl font-bold text-gray-950 dark:text-white">{{ $available }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if (bccomp($reserved, '0', 2) > 0)
                        + {{ $reservedLabel }} в процес на теглене
                    @else
                        По текущия филтър и търсене
                    @endif
                </p>
            </div>

            <div class="bg-white p-6 dark:bg-gray-900" style="border-top: 3px solid #F59E0B;">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Текущо начислени лихви
                </p>
                <p class="mt-2 text-3xl font-bold text-gray-950 dark:text-white">{{ $interest }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if (bccomp($inBalances, '0', 2) > 0)
                        от тях {{ $inBalancesLabel }} вече в балансите
                    @else
                        Печалба на инвеститорите до днес
                    @endif
                </p>
            </div>
        </div>

        {{-- Разбивка по погасителен план --}}
        <div class="border-t border-gray-100 p-6 dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                    Начислени лихви по погасителен план
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Сборът на трите е равен на общата сума горе
                </p>
            </div>

            {{-- Лентата на дяловете: показва кой план носи лихвата --}}
            <div class="mt-4 flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                @if ($hasInterest)
                    @foreach ($plans as $plan)
                        @if ($plan['share'] > 0)
                            <div
                                style="width: {{ $plan['share'] }}%; background-color: {{ $plan['color'] }};"
                                title="{{ $plan['label'] }} — {{ $plan['interest'] }}"
                            ></div>
                        @endif
                    @endforeach
                @endif
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                @foreach ($plans as $plan)
                    @php($principal = $capital[$plan['key']]['principal'] ?? '0.00')
                    @php($active = bccomp($principal, '0', 2) > 0)

                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-block h-2.5 w-2.5 rounded-full"
                                style="background-color: {{ $active ? $plan['color'] : '#D1D5DB' }};"
                            ></span>
                            <p class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ $plan['label'] }}</p>
                            @if ($hasInterest && $plan['share'] > 0)
                                <span class="ml-auto text-xs font-semibold text-gray-400 dark:text-gray-500">
                                    {{ $plan['share'] }}%
                                </span>
                            @endif
                        </div>

                        <p class="mt-2 text-xl font-bold text-gray-950 dark:text-white">{{ $plan['interest'] }}</p>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            @if ($active)
                                {{ \Illuminate\Support\Number::currency((float) $principal, 'EUR', 'bg') }} в позиции
                            @else
                                няма активни позиции
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
