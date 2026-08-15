<x-filament-panels::page>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach ($this->getStats() as $stat)
            <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ $stat['label'] }}</p>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
