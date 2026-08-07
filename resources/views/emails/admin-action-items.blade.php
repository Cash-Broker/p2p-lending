@component('mail::message')
# Има задачи за обработка

Здравейте, {{ $adminName }},

Сутрешната проверка ({{ $runAtFormatted }}) откри чакащи задачи по платформата:

@component('mail::table')
| Задача                             | Брой                          |
| :--------------------------------- | :---------------------------- |
@if ($kycPending > 0)
| KYC чакащи преглед                 | **{{ $kycPending }}**         |
@endif
@if ($depositsPending > 0)
| Депозити чакащи потвърждение       | **{{ $depositsPending }}**    |
@endif
@if ($withdrawalsPending > 0)
| Тегления чакащи обработка          | **{{ $withdrawalsPending }}** |
@endif
@if ($buybackQueue > 0)
| Buyback queue                      | **{{ $buybackQueue }}**       |
@endif
@endcomponent
@if ($loansLate > 0)

За информация: **{{ $loansLate }}** {{ $loansLate === 1 ? 'кредит е' : 'кредита са' }} в статус late/default.
@endif

@component('mail::button', ['url' => $adminUrl, 'color' => 'primary'])
Отвори админ панела
@endcomponent

Този имейл се изпраща само когато има чакащи задачи — сутрин без
задачи не генерира съобщение.

Поздрави,
екипът на Vamaasset
@endcomponent
