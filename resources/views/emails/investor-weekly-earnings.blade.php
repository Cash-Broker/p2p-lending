@component('mail::message')
# Твоята седмица във Vamaasset

Здравей, {{ $name }},

@if ($hasWeekly)
За периода {{ $windowStart }} – {{ $windowEnd }} спечели:

@component('mail::panel')
**{{ $weeklyInterest }} €** изплатена лихва тази седмица
@endcomponent
@elseif ($hasAccrued)
Печалбата ти по погасителните планове продължава да се натрупва:

@component('mail::panel')
**+{{ $accruedNow }} €** текуща печалба към момента
@endcomponent
@else
Инвестицията ти вече работи — печалбата се начислява всеки ден по погасителния план.
@endif

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
