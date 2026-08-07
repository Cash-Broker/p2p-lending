@component('mail::message')
# Buyback Queue: {{ $newlyCount }} {{ $newlyCount === 1 ? 'нов кредит' : 'нови кредита' }}

Здравейте, {{ $adminName }},

Ежедневната проверка за buyback-eligibility приключи в {{ $runAtFormatted }}. Резултат:

@component('mail::table')
| Параметър                          | Брой                          |
| :--------------------------------- | :---------------------------- |
| Нови днес                          | **{{ $newlyCount }}**         |
| Чакат > 3 дни в queue-a            | **{{ $olderCount }}**         |
@endcomponent

@if (!empty($loanIds))
**Нови loan ID-та:** {{ implode(', ', array_map(fn ($id) => '#' . $id, $loanIds)) }}

@endif
@component('mail::button', ['url' => $queueUrl, 'color' => 'primary'])
Отвори Buyback Queue
@endcomponent

**Напомняне за workflow-а:**

Buyback се активира СЛЕД като оригинаторът изпрати съответната сума към
платформата (off-platform банков превод). Когато парите са получени,
отвори Queue-а и натисни **Execute** на съответните кредити — това
разпределя сумата към инвеститорите proportionally.

Ако оригинаторът още не е платил, можеш да **Dismiss**-неш записа
(с кратка причина). Действието е reversible — повторно маркиране е
възможно по-късно.

Поздрави,
екипът на Vamaasset
@endcomponent
