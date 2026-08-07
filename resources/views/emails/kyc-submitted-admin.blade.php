@component('mail::message')
# Нова KYC заявка

Здравейте, {{ $adminName }},

**{{ $applicantName }}** изпрати документи за верификация и чака преглед.

@component('mail::table')
| Детайл          | Стойност                          |
| :-------------- | :-------------------------------- |
| Потребител      | **{{ $applicantName }}**          |
| Имейл           | {{ $applicantEmail }}             |
| Тип акаунт      | {{ $accountTypeLabel }}           |
| Подадено на     | {{ $submittedAtFormatted }}       |
@endcomponent

@component('mail::button', ['url' => $reviewUrl, 'color' => 'primary'])
Преглед на потребителя
@endcomponent

Този имейл се изпраща в момента на подаване на заявката — потребителят
чака одобрение, за да може да инвестира.

Поздрави,
екипът на Vamaasset
@endcomponent
