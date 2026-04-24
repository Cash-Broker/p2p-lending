<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" color="primary">
                Запази
            </x-filament::button>
        </div>
    </form>

    <div class="mt-10 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="fi-section rounded-xl bg-white ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Събрани такси (общо)</h3>
            <p class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">
                {{ number_format((float) $totalFeesAllTime, 2, '.', ',') }} €
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $feeCountAllTime }} транзакции</p>
        </div>
        <div class="fi-section rounded-xl bg-white ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">
                Този месец ({{ \Illuminate\Support\Carbon::now()->translatedFormat('F Y') }})
            </h3>
            <p class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">
                {{ number_format((float) $totalFeesMonth, 2, '.', ',') }} €
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $feeCountMonth }} транзакции</p>
        </div>
    </div>

    <div class="mt-6 fi-section rounded-xl bg-white ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-lg font-semibold text-gray-950 dark:text-white mb-4">Последни 10 такси</h3>
        @if ($recentFees->isEmpty())
            <p class="text-sm text-gray-500 italic dark:text-gray-400">
                Все още няма записани такси. Първата такса ще се появи тук след одобряване на теглене с включена такса.
            </p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($recentFees as $fee)
                    <li class="py-3 flex justify-between items-start gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-950 dark:text-white truncate">
                                {{ $fee->description ?? 'Такса' }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                {{ $fee->user?->name ?? 'Unknown' }} ·
                                {{ $fee->created_at?->format('d.m.Y H:i') }}
                                @if ($fee->reference)
                                    · ref: <code class="text-[0.7rem]">{{ $fee->reference }}</code>
                                @endif
                            </p>
                        </div>
                        <span class="text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">
                            {{ number_format((float) $fee->amount, 2, '.', ',') }} €
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
