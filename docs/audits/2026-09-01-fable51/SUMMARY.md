# Одит на VamaAsset P2P — обобщение

Дата: 2026-09-01 · Модел: Claude Fable 5.1 · Режим: **само четене** (нищо в приложението не е променяно, нищо не е пускано срещу база с данни; тестовете по-долу **не са изпълнявани**).

Файлове на одита: [00-money-map.md](00-money-map.md) · [01-payout-and-ledger.md](01-payout-and-ledger.md) · [02-security.md](02-security.md) · [03-code-health.md](03-code-health.md) · [04-backlog.md](04-backlog.md).

Метод: ръчно проследяване на паричния път от водещия одитор (всички цитирани редове са отворени лично), паралелно 12 картографа по подсистеми и 12 търсачи по инварианти/вектори (пълен кръг, ~160 кандидат-находки), след което адверсариален панел (опровергай / възпроизведи по код / калибрирай) върху дедуплицираните кандидати. **Панелът в числа (пълен рън, 384 агента, 0 грешки):** 271 сурови кандидата → 218 след дедупликация → 358 верификации (опровергай / възпроизведи / калибрирай, тежестно-претеглени) → 202 издържали, 16 отхвърлени; после 3 «критици за пълнота» (запазване на парите / регулатор / атакуващ) предложиха 27 нови находки, от които 25 издържаха верификацията. Пет опровержения засягат мои находки и са отразени: PAY-05 и PAY-08 понижени до информационни (ефектът е недостижим при днешната схема/CHECK), PAY-27 понижена до low с несигурна предпоставка, PAY-02 (2) преквалифицирана от паричен в одитен риск, HEALTH-26 понижена до информационна. Останалите опровержения са за кандидати, които не бях включил (напр. «tampered Livewire Select» — Filament валидира опциите; «bank_reference без нормализация» — колацията `utf8mb4_unicode_ci` е case-insensitive и PAD SPACE; «buyback плаща цялата лихва» — документирано решение F2 Q2, включено като PAY-43 с флаг «решение»). Находките на критиците, които потвърдих лично, са PAY-42…49 и SEC-21…29; онова, което не отворих сам, е отбелязано като SUSPECTED вътре в текста им. Всички находки в докладите — независимо от панела — са потвърдени **лично от мен** по код, а тези, при които не съм проследил всичко, са изрично SUSPECTED. Статусът VERIFIED/SUSPECTED е буквален.

---

## Петте неща, които да поправите, преди едно евро да мине през платформата

### 1. Одобрено теглене няма обратен път, а грешно кредитиране няма поправка (PAY-18, PAY-19 — critical/high)
«Одобри» дебитира леджъра **преди** човек да е пуснал банковия превод ([WithdrawalService.php:58-126](../../../app/Services/WithdrawalService.php)); след това единственият статус е «Обработено». Върнат превод (грешен IBAN, закрита сметка) оставя реални пари на платформата и 0 в леджъра на инвеститора — без тип транзакция, услуга или бутон за връщане, а триггерите (правилно) забраняват ръчна SQL корекция. Същото за депозит, кредитиран с грешна сума или на грешен човек. **Направете:** типове `withdrawal_returned` и `correction_*` в `LEDGER_MAP` (със sign-off), статус `returned`, Filament действие «Върнат превод», колони `approved_by/approved_at/processed_by/bank_reference`. Размер M.

### 2. Веригата «открадната парола → нов IBAN → теглене» няма нито една спирачка (SEC-01, SEC-11, PAY-17 — critical)
Само парола за вход, без 2FA; IBAN се добавя и се приема суров в самата заявка без повторна автентикация, cooldown, известие към собственика или одитен запис; одобряващият вижда само `****1234` и не може да види пълния IBAN никъде в приложението — преводът се прави с данни, взети от базата на ръка. **Направете:** 2FA (първо за админите), потвърждение на нов IBAN + 24–48 ч. забрана за теглене към него, имейл/push «не съм аз → замрази» при заявка, флагове «нов IBAN / първо теглене / скорошна смяна на парола» в списъка, действие «Покажи IBAN» с парола и audit запис, `Auditable` на `SavedIban`. Размер M.

### 3. Един админ = създаване на пари + одобрение на изтеглянето им (SEC-02, SEC-03, SEC-10, PAY-26 — critical)
«Захрани сметка» приема произволна сума срещу свободен текст `bank_reference`, бонус до 10 000 € на клик, legacy вноска с произволна лихва, «Одобри» теглене — всичко от един човек, без праг, без втори подпис, без банкова съпоставка; Filament логинът е само парола. Превзета админ сесия (или insider) източва платформата и реконсилиацията в 03:00 казва «OK». Към същия кръг контроли спадат и AML липсите, потвърдени от критик-кръга (SEC-24, SEC-26, SEC-27): юридически лица без действителен собственик и произход на средствата, KYC без структурни данни и скрининг, никакъв мониторинг на транзакциите. **Направете:** 2FA за админите, four-eyes над праг за кредитиране/бонус/теглене (maker ≠ checker в колона), роли `finance`/`compliance`/`ops`, дневен импорт на банково извлечение и маркиране на депозити без реален превод, UBO/ПЕП стъпка преди одобрен KYC на фирма. Размер M.

### 4. Late-детекцията е мъртва за живия продукт, а има път за двойно плащане (PAY-13, PAY-25, PAY-27, PAY-28 — high)
Целият F1/F2 механизъм (закъснение → late → buyback) чете само `amortization_schedules`; офертните кредити (всички живи) имат само `investment_schedules`, за които никой не пише статус `late`. Платформата плаща на инвеститорите по график от собствените си пари **безкрайно**, buyback никога не се задейства, а експозицията се смята само ръчно. Отделно «Погашения» може да разпредели борсова вноска върху офертен кредит и да плати инвеститорите втори път, а buyback/предсрочно погасяване пропускат инвестиции без график и затварят кредита терминално с блокирана главница. **Направете:** late-детекция върху `investment_schedules` (или запис на реалните вноски от кредитополучателя), `usesOffers()` гард в `RepaymentService`, coverage guard преди всеки терминален преход, праг за пауза на авансирането — с решение на клиента. Размер M.

### 5. Леджърът не може да докаже клиентските пари, а правната конструкция трябва да се потвърди (PAY-01, PAY-02, PAY-21, SEC-15 — high)
Едностранен леджър без контра-сметка «банка»: измислен депозит е неразличим от реален, Σ клиентски пари не се сравнява с омнибус сметката, нощната реконсилиация чете без снапшот и губи изтритите портфейли, а алармата «спрете тегленията» не спира нищо. Едновременно с това договорът, който се сключва при всяка инвестиция, прави **платформата заемател** на парите на инвеститора (целеви заем към ВАМА АСЕТ за финансиране на „НАЗАЕМ.БГ“), а не цесия на вземания към инвеститора, както е описан моделът в заданието. Кодът е верен на документа; дали документът е верен на ECSP/лицензионния режим, трябва да каже юрист **преди** пускане. **Направете:** двустранен леджър с `bank_omnibus`/`fees`/`marketing`, `withdrawals_paused` настройка, вдигана от реконсилиацията, реконсилиация в една транзакция + обхождане на потребители без портфейл; правно становище. Размер L (леджър) / S (спирачка).

---

## Какво е добро (за да не се преоткрива)

- Един шлюз за парите (`WalletService`) с `lockForUpdate` на реда и леджърен запис в същата транзакция, без запис в `wallets` извън него; bcmath навсякъде в ядрото; DECIMAL(12,2) + CHECK ≥ 0 на кофите; неизменими `transactions`/`audit_logs`/`loan_events` с MySQL триггери; тестовият пакет е закачен за MySQL.
- Идемпотентност на инвестицията (ключ + UNIQUE), на депозит/теглене решения (status recheck под lock), на нощното изплащане (статус на реда, делта за капитализацията), на buyback (терминален статус).
- Hamilton-разпределение със Σ = цяло по конструкция; последният ред поема дрейфа; капитализацията се сверява на падеж с реда.
- IDOR скопиране на всички инвеститорски ендпойнти; `role`/`kyc_status`/салда не са mass-assignable; смяна на парола иска текущата; няма ендпойнт за смяна на имейл; KYC файловете са извън web root и се сервират само на админ.
- Ясна дисциплина «пари първо, известия след commit» (с едно изключение — PAY-34).

---

## Пълен списък на находките

| ID | Заглавие (кратко) | Тежест | Статус |
|---|---|---|---|
| PAY-01 | Едностранен леджър; салдата са колони | high | VERIFIED |
| PAY-02 | Реконсилиация без снапшот; пропуска изтрити портфейли | medium | VERIFIED / SUSPECTED |
| PAY-03 | Теглене без идемпотентен ключ | low | VERIFIED |
| PAY-04 | Частично погасяване може да се изпълни двойно | medium | VERIFIED |
| PAY-05 | `invest()` маскира всяко UNIQUE нарушение (недостижимо днес) | info | VERIFIED |
| PAY-06 | Обратен ред на заключване wallets↔bonus_grants | low | SUSPECTED |
| PAY-07 | Payout на цял кредит в една транзакция | medium | VERIFIED |
| PAY-08 | Float в такси/PlatformSetting (DB CHECK го обезврежда) | info | VERIFIED |
| PAY-09 | Float в API отговори (display) | low | VERIFIED |
| PAY-10 | Float log за срок на капитализация | low | SUSPECTED |
| PAY-11 | Legacy последна вноска ≠ ред | low | VERIFIED |
| PAY-12 | Три дефиниции на «начислено до момента» | low | SUSPECTED |
| PAY-13 | Late-детекция само за legacy — живият продукт без спирачка | high | VERIFIED |
| PAY-14 | Одобрение на теглене без проверка на инвеститора | medium | VERIFIED |
| PAY-15 | Доказателство за одобрение = текст; `processed_at` се презаписва | medium | VERIFIED |
| PAY-16 | Графиците без одит, пренаписват се на място | medium | VERIFIED |
| PAY-17 | IBAN без одитен запис | medium | VERIFIED |
| PAY-18 | Одобрено теглене без обратен път (лимбо) | critical | VERIFIED |
| PAY-19 | Няма refund/reversal/корекция | high | VERIFIED |
| PAY-20 | Провал на payout = само лог | medium | VERIFIED |
| PAY-21 | Няма банкова реконсилиация / процедура | medium | VERIFIED |
| PAY-22 | Свързаност с ръчния банков процес | medium | VERIFIED |
| PAY-23 | Данък при източника — нищо | high (рег.) | VERIFIED / SUSPECTED (право) |
| PAY-24 | Такса при одобрение, не при заявка; float | medium | VERIFIED |
| PAY-25 | «Погашения» плаща офертен кредит втори път | high | VERIFIED |
| PAY-26 | Ръчна лихва във вноска без граница | medium | VERIFIED |
| PAY-27 | Buyback/closure прескачат инвестиции без график | low | VERIFIED / SUSPECTED (предпоставка) |
| PAY-28 | `late → repaid` затваря офертен кредит с неплатени редове | medium | VERIFIED |
| PAY-29 | `--asof` в бъдещето изплаща всичко | medium | VERIFIED |
| PAY-30 | Частично финансиран кредит без край | medium | VERIFIED |
| PAY-31 | Депозит от непроверен инвеститор се кредитира | medium | VERIFIED |
| PAY-32 | Леджърът приема всякакъв type/amount | low | VERIFIED |
| PAY-33 | Idempotency ключ не е по потребител | low | VERIFIED |
| PAY-34 | KYC имейл вътре в транзакцията с lock | medium | VERIFIED |
| PAY-35 | Пълно погасяване на капитализирана позиция оставя лихва в `accrued` | medium | VERIFIED |
| PAY-36 | Ръчен «Финансиран» — задънена улица | low | VERIFIED |
| PAY-37 | Replay guard на бонуса — неблокиращ | low | SUSPECTED |
| PAY-38 | `published → draft` без гард за пари / без lock | low | SUSPECTED |
| PAY-39 | Офертата се чете от стар snapshot | low | SUSPECTED |
| PAY-40 | Отрицателна последна главница при много дълъг срок | low | SUSPECTED |
| PAY-41 | Загубила надпревара инвестиция = 500 + CRITICAL | low | VERIFIED |
| PAY-42 | Опашката с контролните имейли няма наблюдение | medium | VERIFIED |
| PAY-43 | Buyback «+лихва» изплаща цялата лихва до падеж наведнъж | low (решение) | VERIFIED |
| PAY-44 | Единственият изход от `default` плаща лихва за просрочието | low (решение) | VERIFIED |
| PAY-45 | Закриване на акаунт с одобрено, неизпратено теглене | medium | VERIFIED |
| PAY-46 | Почти пълно частично погасяване → нулеви «платени» редове → бонус | low | VERIFIED |
| PAY-47 | Реконсилиацията без доказателство и без health | medium | VERIFIED |
| PAY-48 | Договорният шаблон без отпечатък | low | VERIFIED |
| PAY-49 | IP/UA на леджърния ред е на админа | low | VERIFIED |
| SEC-01 | ATO → нов IBAN → теглене без спирачки | critical | VERIFIED |
| SEC-02 | Превзет админ = mint + одобрение | critical | VERIFIED |
| SEC-03 | Няма maker-checker никъде | high | VERIFIED |
| SEC-04 | Няма замразяване / отнемане на KYC | medium | VERIFIED |
| SEC-05 | Signed trust-ip линк без auth | low | VERIFIED |
| SEC-06 | Без velocity контроли | medium | VERIFIED |
| SEC-07 | Одит логът пази KYC пътища/имейл | low | VERIFIED |
| SEC-08 | Имена в Telegram/опашка | low | VERIFIED |
| SEC-09 | Само клиентски проверки (quote-vs-commit по избор) | low–medium | VERIFIED |
| SEC-10 | Депозит без съпоставяне с извлечение | high | VERIFIED |
| SEC-11 | Пълният IBAN не се вижда в приложението | high | VERIFIED |
| SEC-12 | PII към Telegram; SQL bindings в изключенията | medium | VERIFIED |
| SEC-13 | GDPR изтриване пропуска фирмен профил/UBO | medium | VERIFIED |
| SEC-14 | Парола-оракули без throttle | low | VERIFIED |
| SEC-15 | ECSP защити липсват; договорът прави платформата заемател | medium (рег.) | VERIFIED / SUSPECTED (право) |
| SEC-16 | Изтриването унищожава KYC/съгласия без AML задържане | medium (рег.) | VERIFIED / SUSPECTED (право) |
| SEC-17 | Бекъпът без KYC директорията и без APP_KEY процедура | medium | VERIFIED |
| SEC-18 | Telegram токен в лога при мрежова грешка | low | SUSPECTED |
| SEC-19 | Линкът за частен кредит е вечен | low | VERIFIED |
| SEC-20 | `Secure` cookie флаг зависи от env | low | SUSPECTED |
| SEC-21 | Верифицираното име се сменя свободно след KYC | medium | VERIFIED |
| SEC-22 | Самообслужваното изтриване като ATO «довършващ ход» | medium | VERIFIED |
| SEC-23 | Име с HTML в Filament toast | low | VERIFIED |
| SEC-24 | Юридически лица без ДСС/представител/произход на средствата | high (рег.) | VERIFIED / SUSPECTED (право) |
| SEC-25 | Достъпът до документи за самоличност не се логва | medium | VERIFIED |
| SEC-26 | KYC без структурни данни и без скрининг | medium (рег.) | VERIFIED |
| SEC-27 | Няма AML мониторинг на транзакциите | medium (рег.) | VERIFIED |
| SEC-28 | Приетият текст на условията не се архивира; placeholder-и в живите условия | medium | VERIFIED |
| SEC-29 | Оригинаторът без правна идентичност/одит | medium (рег.) | VERIFIED |
| HEALTH-01…28 | виж [03-code-health.md](03-code-health.md) | low–medium | VERIFIED (с отбелязани SUSPECTED) |

Разпределение (PAY + SEC): critical 3 · high 9 · medium 35 · low 29 · info 2; плюс 28 HEALTH позиции (low–medium).

---

## Три теста, които днес ще фейлнат (не са пускани, не са комитвани)

Стилът следва [tests/Feature/WithdrawalTest.php](../../../tests/Feature/WithdrawalTest.php) (`RefreshDatabase`, `createVerifiedInvestor`, `Notification::fake()`). Всеки тест описва **желаното** поведение; всеки фейлва по конкретна причина, посочена в коментара.

```php
<?php

namespace Tests\Feature\Audit20260901;

use App\Models\SavedIban;
use App\Models\User;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MoneyPathRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedInvestor(array $walletOverrides = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $wallet = $user->wallet()->create();
        if ($walletOverrides) {
            $wallet->forceFill($walletOverrides)->save();
        }

        return $user;
    }

    /**
     * PAY-18. Банката връща одобрен превод. Очакваме парите да се върнат на
     * инвеститора и заявката да стане `returned`.
     *
     * ДНЕС ФЕЙЛВА С: Illuminate\Database\Eloquent\ModelNotFoundException —
     * WithdrawalService::reject() приема само status='pending'
     * (app/Services/WithdrawalService.php:160-163). Няма нито един метод,
     * който да върне пари по одобрено теглене; `available` остава 600.00.
     */
    public function test_a_bounced_wire_returns_the_money_to_the_investor(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => '1000.00']);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '400.00', 'BG80BNBG96611020345678');
        $service->approve($withdrawal->id, 1);
        $this->assertSame('600.00', $user->wallet->fresh()->available);

        // Банката отхвърля превода (сгрешен IBAN / закрита сметка).
        $service->reject($withdrawal->id, 1, 'Преводът е върнат от банката');

        $this->assertSame('1000.00', $user->wallet->fresh()->available);
        $this->assertSame('0.00', $user->wallet->fresh()->reserved);
        $this->assertDatabaseHas('withdrawal_requests', [
            'id' => $withdrawal->id,
            'status' => 'returned',
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'withdrawal_returned',
            'amount' => 400,
        ]);
    }

    /**
     * SEC-01 / PAY-17. Добавянето на IBAN за изплащане е най-експлоатираната
     * стъпка при превзет акаунт: трябва да иска паролата, да уведоми
     * собственика и да остави одитен запис.
     *
     * ДНЕС ФЕЙЛВА НА ПЪРВИЯ ASSERT: POST /api/profile/ibans без парола връща
     * 201 (app/Http/Controllers/Api/ProfileController.php:335-356). Няма
     * известие към собственика и SavedIban не е Auditable
     * (app/Models/SavedIban.php).
     */
    public function test_adding_a_payout_iban_requires_the_password_and_tells_the_owner(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor();

        $this->actingAs($user)
            ->postJson('/api/profile/ibans', ['iban' => 'BG80BNBG96611020345678'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/profile/ibans', [
                'iban' => 'BG80BNBG96611020345678',
                'password' => 'password', // UserFactory default
            ])
            ->assertStatus(201);

        Notification::assertSentTo($user, \App\Notifications\PayoutDestinationAddedNotification::class);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => SavedIban::class,
            'action' => 'created',
            'user_id' => $user->id,
        ]);
    }

    /**
     * PAY-14 / SEC-04. Compliance отнема верификацията между заявката и
     * одобрението. Одобрението трябва да бъде отказано.
     *
     * ДНЕС ФЕЙЛВА С: "Failed asserting that exception of type ValidationException
     * is thrown" — WithdrawalService::approve() проверява само status='pending'
     * на заявката (app/Services/WithdrawalService.php:60-64) и парите излизат
     * към непроверен акаунт. Няма и UI, което да отнема одобрен KYC
     * (app/Filament/Resources/UserResource.php:151-164 — действията са
     * видими само за submitted/in_review).
     */
    public function test_approval_is_refused_when_the_investor_is_no_longer_verified(): void
    {
        Notification::fake();
        $user = $this->createVerifiedInvestor(['available' => '1000.00']);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->createRequest($user->id, '400.00', 'BG80BNBG96611020345678');

        // Единственият начин днес — директно в базата.
        $user->forceFill(['kyc_status' => 'rejected'])->save();

        try {
            $service->approve($withdrawal->id, 1);
            $this->fail('Одобрение на теглене към инвеститор с отнет KYC мина без грешка — парите излязоха.');
        } catch (ValidationException) {
            // желано поведение
        }

        $this->assertSame('400.00', $user->wallet->fresh()->reserved);
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id, 'type' => 'withdrawal']);
    }
}
```

Четвърти кандидат, който също би фейлнал и е още по-паричен: две изпълнения на `EarlyClosureExecutionService::execute($loan, $admin, '4000.00')` една след друга → очакваме второто да бъде отхвърлено като дубликат, а днес създава два реда в `loan_early_closures` и удвоява изплатената главница (PAY-04). Не го включвам сред трите, защото зависи от дизайн-решение (какво е «дубликат» при разрешени многократни погасявания).

---

## Какво НЕ успях да проследя и къде съм най-малко уверен

**Не проследено (по обективни причини):**
- **Състоянието на продукционната база** — дали триггерите и CHECK-овете реално са приложени (миграциите ги прескачат при sqlite), дали има офертни кредити с борсов план (предпоставка за PAY-25), инвестиции без график (PAY-27), legacy инвестиции по живи кредити. Одитът е само по код.
- **Поведение при реална конкурентност** — всички изводи за deadlock (PAY-06), фалшиви аларми на реконсилиацията (PAY-02) и двойно вмъкване на редове от графика (backfill без lock) са статични; нищо не е изпълнявано.
- **Изпълнение на изчисления** — PAY-10 (float `log()` при малки остатъци) и PAY-12 (натрупано при повторни частични погасявания на капитализирана позиция) са изведени аналитично; числен пример не е прогонен.
- **Прод конфигурация** — `.env` умишлено не е отварян: часова зона, Telegram получатели, SMTP, `SESSION_LIFETIME`, стриктен режим на MySQL (влияе на няколко «raw SQL error» находки).
- **Vendor кодът** — поведението на Filament при повторно изпращане на Livewire действие (основа за PAY-04) и на Sanctum `AuthenticateSession` при смяна на парола (положителната находка в 02-security) са по документация, не по четене на vendor.
- **Web push / Telegram / имейл** класове — четени избирателно (SEC-08/12), не всички.
- **AccruedEarningsService, PayoutLiabilityService, DashboardController, PortfolioController** — display-only пътища, прегледани само за float и за разминаване с двигателя, не ред по ред.
- **Правната квалификация** на договорната конструкция (SEC-15) и на данъчното задължение (PAY-23) — извън обхвата на кодов одит; посочени са като въпроси.

**Най-малко уверен съм в:**
1. **Тежестта на PAY-13** зависи от това как реално се управляват просрочията днес (може би ръчно, с дисциплина). Кодовият факт е сигурен: няма автоматизация за офертни кредити.
2. **PAY-12** — паричният ефект по моята сметка е неутрален за инвеститора, но не съм сигурен за всички комбинации (стъб-дни, cap на лихвата) — затова е SUSPECTED low.
3. **Реалистичността на PAY-25** — изисква офертен кредит с борсов план; кодът изрично предвижда такива кредити, но не знам дали съществуват.
4. **SEC-15 (структурата на договора)** — сигурен съм какво пише в шаблона; не съм сигурен как ще го квалифицира регулаторът.

**Какво остана недовършено от самия одит:** панелът и критиците минаха изцяло (виж числата в началото). Остават непроследени, по обективни причини, изброените по-долу неща; критиците добавиха към списъка: обработката на HEIC през ImageMagick делегати (повърхност за атака през качен файл), поведението при бъдещ CDN/прокси (всички IP-лимити са по `request()->ip()` без trusted proxies), реда за възстановяване на `sessions`/`jobs`/`push_subscriptions` при restore, SPF/DKIM/DMARC на домейна за алармите (инфраструктура) и правата на DB потребителя на прод (може ли да `DROP TRIGGER`).

**Отхвърлени/непотвърдени кандидати от паралелните агенти** (не са включени, защото не ги потвърдих лично или ги смятам за неверни): «стрендвани стотинки accrued след голямо *частично* погасяване» (SUSPECTED, не е доказано — пълното затваряне е PAY-35), «X-Idempotency-Key > 64 знака → 500» (възможно, но без паричен ефект), «гейтът за повторно съгласие fail-open» (по дизайн — регистрацията винаги пише запис), «фронтенд картите сумират само текущата страница» (display), «tampered Livewire save заобикаля MANUAL_STATUS_BLOCKLIST» (Filament Select валидира стойността срещу опциите — смятам го за невярно), «няма throttle:api група» (Laravel 11+ дефинира лимитер `api` по подразбиране — не е проверено, но е вероятно невярно), «CreatePromotion приема loan_id без exists» и «loan detail излага late данни на legacy кредити» (не отворих файловете).
