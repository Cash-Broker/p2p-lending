<x-filament-panels::page>
    {{-- Self-contained styles: independent of the Filament theme compilation,
         with explicit dark-mode rules. --}}
    <style>
        .vstat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        @media (min-width: 1024px) { .vstat-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .vstat-card {
            position: relative; overflow: hidden; display: flex; align-items: center; gap: 0.9rem;
            padding: 1rem 1.15rem; border-radius: 1rem; background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.06); box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06);
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }
        .vstat-card:hover { box-shadow: 0 6px 16px rgba(16, 24, 40, 0.10); transform: translateY(-1px); }
        .vstat-card::before {
            content: ''; position: absolute; inset: 0 auto 0 0; width: 4px;
            background: var(--vstat-accent); border-radius: 1rem 0 0 1rem;
        }
        .dark .vstat-card { background: #111827; border-color: rgba(255, 255, 255, 0.08); }
        .vstat-icon {
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 2.75rem; height: 2.75rem; border-radius: 0.75rem;
            background: color-mix(in srgb, var(--vstat-accent) 12%, transparent);
            color: var(--vstat-accent);
        }
        .dark .vstat-icon { background: color-mix(in srgb, var(--vstat-accent) 22%, transparent); }
        .vstat-icon svg { width: 1.4rem; height: 1.4rem; }
        .vstat-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin: 0; }
        .dark .vstat-label { color: #9ca3af; }
        .vstat-value { font-size: 1.45rem; font-weight: 800; line-height: 1.2; color: #111827; margin: 0.1rem 0 0; font-variant-numeric: tabular-nums; }
        .dark .vstat-value { color: #f9fafb; }
        .vstat-sub { font-size: 0.72rem; color: #9ca3af; margin: 0; }
    </style>

    <div class="vstat-grid">
        @foreach ($this->getStats() as $stat)
            <div class="vstat-card" style="--vstat-accent: {{ $stat['accent'] }}">
                <div class="vstat-icon">
                    @switch($stat['icon'])
                        @case('bolt')
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg>
                            @break
                        @case('clock')
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            @break
                        @case('chart')
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                            @break
                        @default
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    @endswitch
                </div>
                <div style="min-width: 0;">
                    <p class="vstat-label">{{ $stat['label'] }}</p>
                    <p class="vstat-value">{{ $stat['value'] }}</p>
                    <p class="vstat-sub">{{ $stat['sub'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
