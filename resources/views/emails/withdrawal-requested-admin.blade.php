@component('mail::message')
# Ново заявено теглене

Здравейте, {{ $adminName }},

**{{ $investorName }}** заяви теглене на **{{ $amount }} €**. Сумата е
резервирана в портфейла му и чака обработка.

@component('mail::table')
| Детайл          | Стойност                          |
| :-------------- | :-------------------------------- |
| Заявка          | #{{ $withdrawalId }}              |
| Инвеститор      | **{{ $investorName }}**           |
| Сума            | **{{ $amount }} €**               |
| Заявено на      | {{ $requestedAtFormatted }}       |
@endcomponent

IBAN-ът на получателя се вижда само в админ панела при обработка.

@component('mail::button', ['url' => $reviewUrl, 'color' => 'primary'])
Отвори тегленията
@endcomponent

Този имейл се изпраща в момента на заявката — средствата на
инвеститора са блокирани до обработката ѝ.

Поздрави,
екипът на Vamaasset
@endcomponent
