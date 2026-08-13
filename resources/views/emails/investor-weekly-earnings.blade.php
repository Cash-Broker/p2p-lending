@component('mail::message')
# Твоята седмица във Vamaasset

Здравей, {{ $name }},

За периода {{ $windowStart }} – {{ $windowEnd }} спечели:

@component('mail::panel')
**{{ $weeklyInterest }} €** изплатена лихва тази седмица
@endcomponent

@component('mail::table')
| Показател                          | Сума                     |
| :--------------------------------- | :----------------------- |
| Текуща печалба (натрупва се)       | **{{ $accruedNow }} €**  |
| Изплатени лихви от началото        | **{{ $totalPaid }} €**   |
@endcomponent

Текущата печалба се начислява всеки ден по погасителните планове на твоите
инвестиции и се изплаща по сметката ти на падежите.

@component('mail::button', ['url' => $dashboardUrl, 'color' => 'success'])
Виж таблото си
@endcomponent

Поздрави,<br>
Екипът на Vamaasset
@endcomponent
