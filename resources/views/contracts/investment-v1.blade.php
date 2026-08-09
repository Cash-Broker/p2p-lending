{{--
    «Договор за целеви паричен заем» — template v1 (2026-08-09).

    Text follows the lawyer-provided .docx verbatim; placeholders are
    filled from the frozen snapshots (party / terms). Per the client's
    explicit decision there are NO signature lines: the closing block
    records the click-wrap electronic acceptance instead («самото
    кликване и инвестиране е съгласието»).

    IMPORTANT: this file is version-pinned. Concluded contracts render
    with the template version they were accepted under — wording changes
    belong in a NEW investment-v2.blade.php, never here.

    $party      party_snapshot array (name, identifier_label, identifier,
                address, representative, representative_role, email, account_type)
    $terms      terms_snapshot array (see InvestmentContractService)
    $acceptance ['is_preview' => bool, 'accepted_at' => ?Carbon,
                 'ip_address' => ?string, 'investment_id' => ?int]
--}}
@php
    use Illuminate\Support\Carbon;

    // Missing data renders as a dotted blank, like an unfilled paper form.
    $blank = fn ($v) => ($v !== null && $v !== '') ? $v : '……………………';
    $bgMoney = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $bgDate = fn ($v) => $v ? Carbon::parse($v)->format('d.m.Y') : null;

    $isBullet = $terms['payout_type'] === 'capitalized';
@endphp
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<title>Договор за целеви паричен заем</title>
<style>
    @page { margin: 90px 70px 80px 70px; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10.5px;
        line-height: 1.55;
        color: #111;
    }
    h1 {
        font-size: 14px;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 18px 0;
    }
    h2 {
        font-size: 11px;
        text-transform: uppercase;
        margin: 16px 0 6px 0;
    }
    p { margin: 0 0 8px 0; text-align: justify; }
    .center { text-align: center; }
    .footer {
        position: fixed;
        bottom: -55px;
        left: 0;
        right: 0;
        font-size: 8px;
        color: #666;
        text-align: center;
    }
    .watermark {
        position: fixed;
        top: 40%;
        left: 8%;
        font-size: 96px;
        color: rgba(200, 30, 30, 0.12);
        transform: rotate(-30deg);
        z-index: -1;
    }
    .preview-note {
        border: 1.5px solid #c02626;
        color: #c02626;
        padding: 8px 10px;
        font-size: 9.5px;
        margin-bottom: 16px;
    }
    .acceptance {
        border: 1px solid #444;
        padding: 10px 12px;
        margin-top: 22px;
        page-break-inside: avoid;
    }
    .acceptance-title {
        font-weight: bold;
        text-transform: uppercase;
        font-size: 10px;
        margin-bottom: 6px;
    }
    .acceptance table { font-size: 10px; border-collapse: collapse; }
    .acceptance td { padding: 1.5px 12px 1.5px 0; vertical-align: top; }
    .annex { page-break-before: always; }
    table.schedule {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 9.5px;
    }
    table.schedule th, table.schedule td {
        border: 0.75px solid #555;
        padding: 3.5px 6px;
    }
    table.schedule th { background: #eef1f5; text-align: center; }
    table.schedule td.num { text-align: right; }
    table.schedule td.date, table.schedule td.idx { text-align: center; }
    table.schedule tr.totals td { font-weight: bold; background: #f5f6f8; }
    .schedule-note { font-size: 9px; color: #444; margin-top: 8px; }
</style>
</head>
<body>

@if ($acceptance['is_preview'])
    <div class="watermark">ПРОЕКТ</div>
    <div class="preview-note">
        <strong>ПРОЕКТ.</strong> Този документ е образец на договора, който ще бъде сключен
        с извършване на действието „Инвестирай“ в платформата. Настоящият проект не поражда
        правни последици.
    </div>
@endif

<div class="footer">
    Договор за целеви паричен заем{{ $acceptance['investment_id'] ? ' · Инвестиция № '.$acceptance['investment_id'] : ' · ПРОЕКТ' }} · генериран електронно
</div>

<h1>Договор за целеви паричен заем</h1>

<p>Днес, {{ $blank($bgDate($terms['contract_date'])) }} г., в гр. {{ $blank($terms['city']) }}, се сключи настоящият договор между:</p>

{{--
    Individuals are identified by name + the platform profile (email in the
    acceptance record); ЕГН/адрес deliberately NOT printed — client decision
    2026-08-09 («засега без — ако тя иска, ще го направим»).
--}}
<p>
    @if ($party['account_type'] === 'legal_entity')
        <strong>{{ $blank($party['name']) }}</strong>,
        с {{ $party['identifier_label'] }} {{ $blank($party['identifier']) }},
        със седалище и адрес на управление {{ $blank($party['address']) }},
        представлявано от {{ $party['representative_role'] ? mb_strtolower($party['representative_role']) : 'представляващия' }} {{ $blank($party['representative']) }},
        наричано по-долу за краткост <strong>ЗАЕМОДАТЕЛ</strong>,
    @else
        <strong>{{ $blank($party['name']) }}</strong>,
        наричан по-долу за краткост <strong>ЗАЕМОДАТЕЛ</strong>,
    @endif
</p>

<p class="center">и</p>

<p>
    <strong>{{ $blank($terms['company_name']) }}</strong>,
    с ЕИК {{ $blank($terms['company_eik']) }},
    със седалище и адрес на управление: {{ $blank($terms['company_address']) }},
    представлявано от управителя {{ $blank($terms['company_manager']) }},
    наричано по-долу за краткост <strong>ЗАЕМАТЕЛ</strong>.
</p>

<p>Страните се споразумяха за следното:</p>

<h2>I. Предмет и целево предназначение</h2>

<p>
    <strong>Чл. 1.</strong> (1) ЗАЕМОДАТЕЛЯТ предоставя на ЗАЕМАТЕЛЯ паричен заем в размер на
    <strong>{{ $bgMoney($terms['amount']) }} {{ $terms['currency'] }} ({{ $terms['amount_words'] }})</strong>
    (наричана „Заемна сума“).
</p>
<p>
    (2) Заемът е <strong>ЦЕЛЕВИ</strong>. ЗАЕМАТЕЛЯТ се задължава да използва Заемната сума единствено
    и само за предоставянето ѝ на „НАЗАЕМ.БГ“ ООД като бюджет за финансиране на дейността на
    последното по отпускане на кредити на трети лица.
</p>
<p>
    (3) Използването на Заемната сума, изцяло или частично, за цели, различни от посочените в ал. 2,
    представлява съществено неизпълнение на настоящия договор.
</p>

<h2>II. Лихва, срок и погасяване</h2>

<p>
    <strong>Чл. 2.</strong> (1) За ползването на заема ЗАЕМАТЕЛЯТ дължи на ЗАЕМОДАТЕЛЯ възнаградителна
    лихва в размер на <strong>{{ rtrim(rtrim($terms['interest_rate'], '0'), '.') }}% ({{ $terms['interest_rate_words'] }} процента) годишно</strong>.
</p>
<p>
    (2) Заемът се предоставя за срок от <strong>{{ $terms['term_months'] }} ({{ $terms['term_words'] }})
    {{ $terms['term_months'] == 1 ? 'месец' : 'месеца' }}</strong>, считано от датата на предоставяне
    на Заемната сума.
</p>
<p>
    (3) Главницата и начислената лихва се погасяват
    @if ($isBullet)
        еднократно в края на срока, съгласно погасителен план — неразделна част от настоящия договор
        (Приложение № 1).
    @else
        на погасителни вноски съгласно погасителен план, неразделна част от настоящия договор
        (Приложение № 1).
    @endif
</p>
<p>
    (4) ЗАЕМАТЕЛЯТ има право да погаси заема предсрочно, изцяло или частично, по всяко време, без да
    дължи неустойки или такси за това. При предсрочно погасяване се дължи възнаградителна лихва само
    за периода, през който сумата е реално ползвана.
</p>

<h2>III. Алтернативни начини на погасяване</h2>

<p>
    <strong>Чл. 3.</strong> (1) ЗАЕМАТЕЛЯТ има право едностранно да извърши прихващане на свои
    изискуеми и ликвидни вземания от ЗАЕМОДАТЕЛЯ (включително, но не само, такси за управление или
    други услуги) срещу дължимите по този договор суми (главница и/или лихви).
</p>
<p>
    (2) Страните се съгласяват, че погасяването на задълженията по този договор може да се извърши и
    чрез прехвърляне (цесия) в полза на ЗАЕМОДАТЕЛЯ на вземания, които ЗАЕМАТЕЛЯТ има или ще придобие
    от „НАЗАЕМ.БГ“ ООД, произтичащи от предоставеното му по чл. 1, ал. 2 финансиране. С приемането на
    договора ЗАЕМОДАТЕЛЯТ дава своето неотменимо съгласие за такова изпълнение вместо плащане
    (datio in solutum).
</p>

<h2>IV. Предсрочна изискуемост</h2>

<p>
    <strong>Чл. 4.</strong> ЗАЕМОДАТЕЛЯТ има право да обяви целия заем (главница и начислени лихви) за
    предсрочно изискуем и да поиска връщането му в срок от два месеца, при настъпване на което и да е
    от следните обстоятелства:
</p>
<p>1. Използване на Заемната сума в нарушение на чл. 1, ал. 2 от договора;</p>
<p>2. Откриване на производство по несъстоятелност или ликвидация за ЗАЕМАТЕЛЯ.</p>

<h2>V. Други условия</h2>

<p>
    <strong>Чл. 5.</strong> (1) ЗАЕМОДАТЕЛЯТ декларира, че предоставените средства са със законен
    произход.
</p>
<p>(2) Всички изменения и допълнения на този договор се извършват в писмена форма.</p>
<p>
    (3) За всички неуредени въпроси се прилагат разпоредбите на Закона за задълженията и договорите и
    действащото българско законодателство.
</p>
<p>
    (4) Всички спорове ще се решават чрез преговори, а при невъзможност за постигане на съгласие — от
    компетентния съд в гр. Пловдив.
</p>

<p style="margin-top: 14px;">
    Настоящият договор се сключва от разстояние, в електронна форма, чрез инвестиционната платформа на
    ЗАЕМАТЕЛЯ. Договорът се счита за сключен от момента, в който ЗАЕМОДАТЕЛЯТ извърши действието
    „Инвестирай“ в платформата. Това действие се записва от платформата и представлява електронно
    изявление за съгласие на ЗАЕМОДАТЕЛЯ с условията на настоящия договор. Подписи не се полагат.
</p>

@if ($acceptance['is_preview'])
    <div class="acceptance">
        <div class="acceptance-title">Запис за електронно приемане</div>
        <p style="margin: 0;">
            Този запис се попълва автоматично при извършване на действието „Инвестирай“ — дата и час,
            потребител и IP адрес на приемането.
        </p>
    </div>
@else
    <div class="acceptance">
        <div class="acceptance-title">Запис за електронно приемане</div>
        <table>
            <tr><td>Приет от:</td><td><strong>{{ $party['name'] }}</strong> ({{ $party['email'] }})</td></tr>
            @if ($party['account_type'] === 'legal_entity' && $party['representative'])
                <tr><td>Чрез:</td><td>{{ $party['representative'] }}</td></tr>
            @endif
            <tr><td>Качество:</td><td>ЗАЕМОДАТЕЛ</td></tr>
            <tr><td>Дата и час:</td><td>{{ $acceptance['accepted_at']?->format('d.m.Y H:i:s') }} ч.</td></tr>
            <tr><td>IP адрес:</td><td>{{ $acceptance['ip_address'] ?? '—' }}</td></tr>
            <tr><td>Референция:</td><td>Инвестиция № {{ $acceptance['investment_id'] }}</td></tr>
        </table>
    </div>
@endif

<div class="annex">
    <h1>Приложение № 1 — Погасителен план</h1>
    <p class="center" style="margin-bottom: 4px;">
        неразделна част от Договор за целеви паричен заем
        от {{ $blank($bgDate($terms['contract_date'])) }} г.
    </p>
    <p class="center">
        План на изплащане: <strong>{{ $terms['payout_label'] }}</strong> ·
        Лихва: <strong>{{ rtrim(rtrim($terms['interest_rate'], '0'), '.') }}% годишно</strong> ·
        Заемна сума: <strong>{{ $bgMoney($terms['amount']) }} {{ $terms['currency'] }}</strong>
    </p>

    <table class="schedule">
        <thead>
            <tr>
                <th style="width: 8%;">№</th>
                <th style="width: 22%;">Дата на плащане</th>
                <th>Главница ({{ $terms['currency'] }})</th>
                <th>Лихва ({{ $terms['currency'] }})</th>
                <th>Общо ({{ $terms['currency'] }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($terms['schedule'] as $i => $row)
                <tr>
                    <td class="idx">{{ $i + 1 }}</td>
                    <td class="date">{{ $bgDate($row['due_date']) }} г.</td>
                    <td class="num">{{ $bgMoney($row['principal']) }}</td>
                    <td class="num">{{ $bgMoney($row['interest']) }}</td>
                    <td class="num">{{ $bgMoney($row['total']) }}</td>
                </tr>
            @endforeach
            <tr class="totals">
                <td colspan="2" class="date">Общо</td>
                <td class="num">{{ $bgMoney($terms['total_principal']) }}</td>
                <td class="num">{{ $bgMoney($terms['total_interest']) }}</td>
                <td class="num">{{ $bgMoney($terms['total_repaid']) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="schedule-note">
        Забележка: Датите на плащане са индикативни към датата на сключване на договора. Окончателният
        погасителен план се формира при активиране на кредита (предоставяне на Заемната сума), при
        запазване на размера на вноските, лихвения процент и броя на вноските по настоящото приложение.
    </p>
</div>

</body>
</html>
