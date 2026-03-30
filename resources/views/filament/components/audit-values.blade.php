@php
    $values = $getState();
    if (is_string($values)) $values = json_decode($values, true);
@endphp

@if(is_array($values) && count($values) > 0)
    <div class="rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50">
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Поле</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Стойност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($values as $key => $value)
                    <tr>
                        <td class="px-4 py-2 text-gray-600 font-medium">{{ $key }}</td>
                        <td class="px-4 py-2 text-gray-900 font-mono text-xs">
                            @if($value === '[REDACTED]')
                                <span class="text-red-500 italic">{{ $value }}</span>
                            @elseif(is_null($value))
                                <span class="text-gray-400 italic">null</span>
                            @else
                                {{ is_array($value) ? json_encode($value) : $value }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="text-gray-400 text-sm">Няма данни</p>
@endif
