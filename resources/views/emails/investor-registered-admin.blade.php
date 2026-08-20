@component('mail::message')
@if ($isConsolidated)
# {{ $consolidatedCount }} нови регистрации

Здравейте, {{ $adminName }},

През последния час са създадени **{{ $consolidatedCount }}** нови инвеститорски профила.
За да не се залива пощата Ви, отделните имейли са спрени до края на часа — този
имейл ги замества.

@component('mail::button', ['url' => $usersUrl, 'color' => 'primary'])
Виж потребителите
@endcomponent

Ако това не изглежда като нормална активност, прегледайте списъка — регистрацията
е публична форма.
@else
# Нова регистрация

Здравейте, {{ $adminName }},

**{{ $investorName }}** си създаде инвеститорски профил.

@component('mail::table')
| Детайл          | Стойност                          |
| :-------------- | :-------------------------------- |
| Потребител      | **{{ $investorName }}**           |
| Имейл           | {{ $investorEmail }}              |
| Тип акаунт      | {{ $accountTypeLabel }}           |
| Регистриран на  | {{ $registeredAtFormatted }}      |
@endcomponent

@component('mail::button', ['url' => $profileUrl, 'color' => 'primary'])
Виж профила
@endcomponent

Профилът все още не е верифициран — ще получите отделно известие, когато
потребителят подаде документи за KYC.
@endif

Поздрави,
екипът на Vamaasset
@endcomponent
