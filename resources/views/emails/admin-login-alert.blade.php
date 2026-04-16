@component('mail::message')
@if ($isKnownIp)
# Вход в admin панела (познат IP)
@else
# ⚠️ Вход в admin панела от НОВ IP адрес
@endif

Здравей, {{ $admin->name }},

@if ($consolidatedCount > 1)
**Засечени са {{ $consolidatedCount }} опита за вход за последния час от един и същ IP.**
Получаваш това известие веднъж за интервала, за да не наводним пощата ти.

@endif
Засечен е успешен вход в admin панела с твоя акаунт:

- **Дата и час:** {{ $occurredAt->format('d.m.Y H:i:s') }} (UTC)
- **IP адрес:** `{{ $ipAddress }}`
- **Браузър:** `{{ $userAgent ?? 'неизвестен' }}`
- **Статус на IP:** {{ $isKnownIp ? 'познат (вече добавен в trusted)' : 'НОВ — не е виждан преди' }}

@if (! $isKnownIp)
Ако това си **ти**, добави IP адреса към доверените за да не получаваш предупреждения от него повече:

@component('mail::button', ['url' => $trustUrl, 'color' => 'success'])
Маркирай IP като trusted
@endcomponent

@endif
**Ако това НЕ си ти — смени паролата си веднага** и провери audit log-а за подозрителни действия.

@component('mail::button', ['url' => config('app.url') . '/admin', 'color' => 'red'])
Отвори admin панела
@endcomponent

Поздрави,
Сигурностният екип на {{ config('app.name') }}
@endcomponent
