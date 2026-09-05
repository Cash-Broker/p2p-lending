# Фаза 1 — Коректност на изплащанията и леджъра

Дата: 2026-09-01 · Обхват: инвариантите 1–10 от заданието · Режим: само четене.

**Как да се чете.** За всеки инвариант първо казвам дали кодът го **гарантира** (да / частично / не) и къде точно се чупи. Всяка находка има: ID, заглавие, тежест, статус **VERIFIED** (проследих пътя от край до край, включително местата, където би могъл да стои гард) или **SUSPECTED** (кодът мирише, но не съм в състояние да докажа ефекта без изпълнение), `файл:ред`, какво се чупи в пари, как се възпроизвежда, предложена поправка и оценка на усилието (S до 1 ден / M 2–5 дни / L над седмица). Статусите **не са размити**: SUSPECTED значи «човек трябва да го потвърди».

Тежест: **critical** = пари могат да излязат два пъти, да се създадат от нищото, да отидат при атакуващ или да се загубят без следа; **high** = сериозен паричен/регулаторен риск при реалистичен сценарий; **medium** = дефект с ограничен паричен ефект или контролна липса; **low** = хигиена, която не губи пари днес.

---

## Инвариант 1 — Двустранност и извеждане на салдото

**Гарантирано: НЕ (частично компенсирано с нощна реконсилиация).**

Факти:
- Всяко движение създава **един** ред в `transactions` с `type` и `amount` — без контра-сметка, без `balance_after` ([app/Services/WalletService.php:45-53](../../../app/Services/WalletService.php); [database/migrations/2026_03_29_100006:14-25](../../../database/migrations/2026_03_29_100006_create_transactions_table.php)).
- Салдата са **променяеми колони** в `wallets`, записвани с `forceFill(...)->save()` в същата транзакция (WalletService.php:41-43, 74-76, 276-279 и т.н.). Могат да се изведат от леджъра само чрез `LEDGER_MAP` ([app/Console/Commands/ReconcileLedger.php:52-92](../../../app/Console/Commands/ReconcileLedger.php)) и това се прави веднъж на ден в 03:00.
- Резервацията при теглене (`reserve` / `releaseReservation`) мести `available ↔ reserved` **без ред в леджъра** (WalletService.php:172-175, 219-222) — затова реконсилиацията сравнява `available + reserved` като една кофа (ReconcileLedger.php:141-146).
- Σ на всички редове в `transactions` не е нула и няма сметка, която да представлява омнибус банковата сметка, оригинатора или кредитополучателя. Таксите «излизат от леджъра» (коментар във [FeeService.php:24-29](../../../app/Services/FeeService.php)), бонусите «влизат от нищото» ([Transaction.php:63-67](../../../app/Models/Transaction.php)).
- Положително: не намерих запис в `wallets` извън `WalletService` в `app/` (единствено миграцията [2026_08_18_000002:49](../../../database/migrations/2026_08_18_000002_make_conditional_bonus_investable.php), еднократно). Всеки от 12-те публични метода взема `lockForUpdate` на реда и пише леджъра в същата транзакция.

### PAY-01 — Едностранен леджър; салдата са колони, които могат да дрейфнат
- **Тежест:** high · **Статус:** VERIFIED
- **Къде:** [app/Services/WalletService.php:38-54](../../../app/Services/WalletService.php); [app/Console/Commands/ReconcileLedger.php:52-92, 110-158](../../../app/Console/Commands/ReconcileLedger.php); [app/Models/Wallet.php:19-40](../../../app/Models/Wallet.php)
- **Какво се чупи в пари:** платформата не може да докаже пред одитор/PI/EMI, че Σ(клиентски пари по леджър) = салдо по омнибус сметката — липсва страната «банка». Ако бъг или ръчна SQL промяна отмести `wallets.available`, това се вижда най-рано в 03:00 на следващия ден, а междувременно балансът е теглим (одобрението на теглене чете `wallets.reserved`, не леджъра — WalletService.php:239-245).
- **Възпроизвеждане:** (1) `UPDATE wallets SET available = available + 1000 WHERE user_id = X` (или еквивалентен бъг); (2) инвеститорът заявява теглене 1000 € → `reserve()` минава, защото проверява само колоната; (3) админ одобрява преди 03:00 → парите излизат; (4) реконсилиацията в 03:00 съобщава разминаване след факта.
- **Поправка:** (а) втора таблица `ledger_entries` (сметка, дебит, кредит, `transaction_id`), попълвана от същите методи на `WalletService`, с контра-сметки `bank_omnibus`, `fees_revenue`, `marketing_bonus`; (б) `WithdrawalService::approve` да сверява `wallets.available + reserved` с Σ по леджъра за потребителя **преди** дебита (евтина защита, докато няма двустранност); (в) вж. backlog C1/C7.
- **Усилие:** L (а) / S (б)

### PAY-02 — Реконсилиацията чете без консистентен снапшот и пропуска изтрити портфейли
- **Тежест:** medium · **Статус:** VERIFIED (за пропускането); SUSPECTED (за фалшивата аларма — изисква изпълнение при конкурентност)
- **Къде:** [ReconcileLedger.php:110-117](../../../app/Console/Commands/ReconcileLedger.php) — `Wallet::chunk(100)` и отделна `SUM()` по `transactions` без транзакция/lock; [app/Services/AccountDeletionService.php:150](../../../app/Services/AccountDeletionService.php) — `$user->wallet()->delete()` при закриване.
- **Какво се чупи в пари:** (1) при движение между четенето на портфейла и сумирането на леджъра (инвестиция през API в 03:00, ръчно одобрение) нощната проверка може да прати фалшива аларма «спрете тегленията» или — по-лошо — да пропусне реално разминаване, ако двете грешки се неутрализират; (2) потребител с изтрит `wallets` ред остава с редове в `transactions`, които никой повече не реконсилира. Адверсариалната проверка уточни (2): това **не е паричен риск** — `WalletService` изисква съществуващ портфейл (`firstOrFail`) и никой друг не пише `transactions`, така че за закрит акаунт не може да възникне нов ред; остава **одитен** проблем: «Σ леджър = Σ портфейли» за цялата платформа престава да е доказуемо с една заявка.
- **Възпроизвеждане:** закрий акаунт през `POST /api/profile/delete` след пълно теглене; `SELECT SUM(amount) FROM transactions WHERE user_id = X` ≠ 0 по типове, но `ledger:reconcile` връща OK.
- **Поправка:** обхождане в `REPEATABLE READ` транзакция (една за целия рън) и втори цикъл по `SELECT DISTINCT user_id FROM transactions` за потребители без портфейл (очаквано Σ = 0 за всички кофи).
- **Усилие:** S

---

## Инвариант 2 — Идемпотентност

**Гарантирано: частично (силно за инвестиция и за всички «статусни» операции; слабо за админ действията и за заявката за теглене).**

Факти (положителни):
- Инвестиция: `X-Idempotency-Key` е задължителен ([LoanController.php:208-211](../../../app/Http/Controllers/Api/LoanController.php)), проверява се в началото на транзакцията ([InvestmentService.php:41-46](../../../app/Services/InvestmentService.php)) и е подкрепен от UNIQUE индекс ([2026_04_15_000002:12](../../../database/migrations/2026_04_15_000002_add_idempotency_key_to_investments_table.php)).
- Депозит/теглене одобрение и отказ: `where status = pending` + `lockForUpdate` + повторна проверка ([DepositService.php:112-115](../../../app/Services/DepositService.php); [WithdrawalService.php:61-64, 160-163](../../../app/Services/WithdrawalService.php)); `bank_reference` UNIQUE.
- Payout: редовете се избират по `status IN (pending, late)` и се маркират `paid` в същата транзакция ([PayoutAccrualService.php:86-115](../../../app/Services/PayoutAccrualService.php)); капитализацията начислява само делтата към целта (:164-212). Legacy: `status='paid'` гард ([RepaymentService.php:59-61](../../../app/Services/RepaymentService.php)).
- Buyback: терминален статус + `BuybackAlreadyExecutedException` ([BuybackExecutionService.php:137-144](../../../app/Services/Loans/BuybackExecutionService.php)). Бонус release/cancel: lock + `isLocked()` recheck ([BonusService.php:224-227, 267-270](../../../app/Services/BonusService.php)).
- Кроновете: `Cache::lock` 600 s ([ProcessScheduledPayouts.php:41-47](../../../app/Console/Commands/Loans/ProcessScheduledPayouts.php)), освобождаван във `finally`.
- Няма queue job, който движи пари (`app/Jobs` = имейл за парола + web push).

### PAY-03 — Заявката за теглене няма идемпотентен ключ
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [WithdrawalController.php:25-49](../../../app/Http/Controllers/Api/WithdrawalController.php); [WithdrawalService.php:34-56](../../../app/Services/WithdrawalService.php); SPA изпраща без ключ ([resources/js/views/WithdrawalPage.vue:120-130](../../../resources/js/views/WithdrawalPage.vue)); интерцепторът добавя ключ само за `/invest` ([resources/js/api/axios.js:60-74](../../../resources/js/api/axios.js)).
- **Какво се чупи в пари:** двойно кликване / повторен POST при таймаут → две pending заявки, всяка с отделна резервация. Пари не се губят (втората резервация се отказва при недостиг), но админът вижда два реда и може да одобри двата — легитимно, защото инвеститорът е резервирал и двете, но противно на намерението му.
- **Възпроизвеждане:** два бързи `POST /api/withdrawal {amount: 100, iban}` при available 500 → две заявки, reserved 200.
- **Поправка:** `withdrawal_requests.idempotency_key` UNIQUE + header от SPA, като при инвестицията.
- **Усилие:** S

### PAY-04 — «Частично погасяване» може да се изпълни два пъти с една и съща сума
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [EarlyClosureExecutionService.php:81-97](../../../app/Services/Loans/EarlyClosureExecutionService.php) — гардовете са само статус на кредита и `сума ≤ остатък` ([EarlyClosureCalculationService.php:128-133](../../../app/Services/Loans/EarlyClosureCalculationService.php)); Filament действието има само `requiresConfirmation()` ([LoanResource.php:734-760](../../../app/Filament/Resources/LoanResource.php)); `loan_early_closures` няма уникален ключ на заявката ([2026_08_18_000003:30-49](../../../database/migrations/2026_08_18_000003_create_loan_early_closures_table.php)).
- **Какво се чупи в пари:** загубен Livewire отговор + повторно натискане (или два таба) = две частични погасявания по 40 % — второто е «валидно» за кода, но кредитополучателят е върнал парите само веднъж. Платформата изплаща главница и лихва на инвеститорите, които никой не е внесъл. При кредит 50 000 € и 40 % това са 20 000 € авансирани без покритие, без аларма (Telegram съобщава «частично погасяване», не «дублирано»).
- **Възпроизвеждане:** отвори модала «Частично погасяване» в два таба, въведи 4 000 € в двата, потвърди двата → две `loan_early_closures` записи, Σ `early_repayment_principal` = 8 000 €.
- **Поправка:** еднократен токен в модала (`Hidden::make('request_token')->default(Str::uuid())`) → `loan_early_closures.request_token` UNIQUE; допълнително предупреждение при второ погасяване на същия кредит в рамките на X минути.
- **Усилие:** S

### PAY-05 — `invest()` третира всяко нарушение на UNIQUE като idempotency-повторение
- **Тежест:** info (понижено от low след адверсариалната проверка) · **Статус:** VERIFIED за кода; ефектът не е достижим при днешната схема
- **Къде:** [InvestmentService.php:177-180](../../../app/Services/InvestmentService.php)
- **Какво се чупи в пари:** нищо. Верификаторът провери всички таблици, писани вътре в транзакцията на инвестицията (`investments`, `transactions`, `investment_schedules`, `bonus_grants`, `loan_promotions`, `investment_contracts`), и единственият UNIQUE, който може да се задейства, е самият `idempotency_key` — т.е. днес catch-ът е коректен. Остава хигиенна забележка: бъдещ UNIQUE (напр. `investment_contracts.investment_id` вече е UNIQUE, но се пише след `investments` и не може да гръмне пръв) би се маскирал като «съществуваща инвестиция».
- **Поправка:** проверка на името на индекса в `$e->errorInfo` преди повторното търсене.
- **Усилие:** S

---

## Инвариант 3 — Конкурентност

**Гарантирано: ДА за портфейла (всяко read-check-write е под `lockForUpdate` на реда в `wallets`); частично за блокировките между процеси.**

Проверени read-check-write точки:
| Място | Заключване | Оценка |
|---|---|---|
| `WalletService::debit/reserve/debitReserved/invest/creditAvailableFromInvested/releaseAccrued/reverseAccrued` | `lockForUpdate` на `wallets` ред, проверка и запис в същата транзакция ([WalletService.php:67-76, 150-175, 236-245, 269-279, 502-534, 412-423, 450-459](../../../app/Services/WalletService.php)) | ✅ песимистично |
| `InvestmentService::validateInvestment` чете `$user->wallet` **без** lock (:198-203) | компенсирано: `WalletService::invest` проверява отново под lock (:272-274) | ✅ fail-fast + гард |
| `WalletService::reserve` чете `bonus_grants` (:159) без lock | освобождаване на бонус паралелно само **вдига** тавана; нов грант само го сваля | ✅ безопасно (консервативно) |
| `InvestmentService::invest` — свръхфинансиране | `loans` ред под lock (:49) преди валидация | ✅ |
| Два админа одобряват една заявка | `where status=pending` + lock ([WithdrawalService.php:61-64](../../../app/Services/WithdrawalService.php); [DepositService.php:112-115](../../../app/Services/DepositService.php)); Filament действието прави non-locking resolve само за приятелско съобщение ([DepositRequestResource.php:208-220](../../../app/Filament/Resources/DepositRequestResource.php)) | ✅ |
| Cron payout vs. ръчен «Пусни плащане сега» | двата минават през `PayoutAccrualService::processLoan` → lock на `loans` ред (:53) и на редовете от графика (:89, :132) | ✅ |
| Cron payout vs. закриване на акаунт | `AccountDeletionService` отказва при `invested > 0` (:79-83); плащанията изискват `wallets` ред (`firstOrFail`) | ✅ |

### PAY-06 — Обратен ред на заключване `wallets` ↔ `bonus_grants` (възможен deadlock)
- **Тежест:** low · **Статус:** SUSPECTED (проследено статично; ефектът е InnoDB 1213 → една от двете транзакции се връща назад; без загуба на пари)
- **Къде:** [AccountDeletionService.php:50-59](../../../app/Services/AccountDeletionService.php) заключва `wallets` → после `bonus_grants`; [BonusService::cancel :267-273](../../../app/Services/BonusService.php) заключва `bonus_grants` → после `wallets` (през `cancelLockedBonus`). Същият AB/BA модел е документиран за `users`↔`deposit_requests` ([DepositService.php:62-66](../../../app/Services/DepositService.php)), където е решен с 3 опита.
- **Какво се чупи в пари:** нищо; админ, който отменя бонус в секундата, в която инвеститорът закрива акаунта си, получава 500 вместо резултат.
- **Поправка:** единен ред на заключване (винаги `wallets` първо) или `DB::transaction(..., attempts: 3)` в `BonusService::cancel`.
- **Усилие:** S

### PAY-07 — Изплащането е една транзакция за целия кредит: един инвеститор блокира всички
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [PayoutAccrualService.php:52-75](../../../app/Services/PayoutAccrualService.php) — `DB::transaction` около всички инвестиции на кредита, всеки `wallets` ред се заключва последователно и се държи до края; [ScheduledPayoutService.php:84-92](../../../app/Services/ScheduledPayoutService.php) — грешка в един кредит = `Log::error` и продължаване.
- **Какво се чупи в пари:** (1) при N инвеститори транзакцията държи N реда в `wallets`; всяка инвестиция/теглене на тези потребители чака до commit (при 04:00 — приемливо; при ръчния бутон през деня — блокировка на API за секунди); (2) `InvestedUnderflowException` или липсващ портфейл за **един** инвеститор връща назад изплащането на **всички** инвеститори в кредита и това се повтаря всяка нощ, докато човек не се намеси — инвеститорите просто спират да получават пари, а сигналът е само ред в лога и брояч `loans_failed` в health endpoint-а.
- **Възпроизвеждане:** кредит с 3 инвеститора; ръчно намали `wallets.invested` на един под дължимата главница; пусни `php artisan loans:process-payouts` (в тестова база) → нито един от тримата не е платен; статус `failure`.
- **Поправка:** savepoint/транзакция **на инвестиция** с `processLoan` като координатор + Telegram 🔴 при провал на инвестиция; или поне алармa при `loans_failed > 0`.
- **Усилие:** M

---

## Инвариант 4 — Типове за пари

**Гарантирано: ДА в ядрото (bcmath низове, `decimal(12,2)`, `decimal:2` cast-ове връщат низове, JSON сумите са низове), НЕ по ръбовете.**

### PAY-08 — Такса и `PlatformSetting` от тип float минават през IEEE-754
- **Тежест:** info (понижено от low след адверсариалната проверка) · **Статус:** VERIFIED за кода; без достижим паричен ефект
- **Къде:** [FeeService.php:66](../../../app/Services/FeeService.php) `number_format((float) $raw, 2)`; [FeeQuote.php:35](../../../app/Services/FeeQuote.php); [PlatformSetting.php:43, 77](../../../app/Models/PlatformSetting.php) — `(float)` при четене и `number_format((float) $value, 2)` при запис; [FeesPage.php:152, 174](../../../app/Filament/Pages/FeesPage.php).
- **Какво се чупи в пари:** нищо, и то структурно: освен формата (FeesPage.php:93-98) стойността е ограничена и на DB ниво с CHECK `^[0-9]+([.][0-9]{1,2})?$` и 0–100 ([2026_04_25_000002:63-72](../../../database/migrations/2026_04_25_000002_seed_fee_platform_settings.php)) — всяко такова число се представя точно като double. Остава хигиенна забележка: правилото «никога float за пари» е нарушено на място, което един бъдещ процентен модел на таксите би разширил. CLAUDE.md го признава като «търпимо изключение».
- **Поправка:** `Money::normalize()` при запис и четене; премахване на `(float)`.
- **Усилие:** S

### PAY-09 — Float в API отговори и отчети (само за показване)
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [PortfolioController.php:116-145](../../../app/Http/Controllers/Api/PortfolioController.php) `number_format((float) …)` върху DECIMAL стойности от базата; [DashboardController.php:184-186](../../../app/Http/Controllers/Api/DashboardController.php); [ReportPayoutExposure.php:34, 58](../../../app/Console/Commands/Loans/ReportPayoutExposure.php); [Loan.php:548-556](../../../app/Models/Loan.php) `(float)` върху лихвени проценти.
- **Какво се чупи в пари:** не се записва нищо; при суми под 2^53 стотинки резултатът е верен. Рискът е, че отчетът за експозиция (ReportPayoutExposure), който е управленско число, и портфейлните тотали се произвеждат по път, различен от леджъра.
- **Поправка:** `bcadd($x, '0', 2)` вместо `number_format((float))`.
- **Усилие:** S

### PAY-10 — Извеждане на срока на капитализирана позиция с float `log()` след частично погасяване
- **Тежест:** low · **Статус:** SUSPECTED
- **Къде:** [OfferProjectionService.php:177-189](../../../app/Services/OfferProjectionService.php) `round(log($growth) / log(1 + $monthlyRate))`, извикано от [PayoutAccrualService.php:142-147](../../../app/Services/PayoutAccrualService.php) с `principal`/`interest` от реда; частичното погасяване презаписва `principal` и `interest` на реда поотделно, всяко закръглено до 2 знака ([EarlyClosureExecutionService.php:244-258](../../../app/Services/Loans/EarlyClosureExecutionService.php)).
- **Какво се чупи в пари:** за нормални суми аргументът «съседните n се различават с ~1.2 % в log-пространство» е верен. За **малка остатъчна позиция** (напр. 0,60 € главница и 0,05 € лихва след няколко частични погасявания) двойното закръгляне променя `growth` достатъчно, за да измести `n` с ±1 месец → месечните милстоуни се изместват и лихвата се начислява месец по-рано/по-късно. На падеж сумата се сверява с реда (`finalInterest`, :170-187), така че пожизненият тотал е верен; ефектът е във времето на начисляване, не в сумата.
- **Поправка:** пазете `term_months` като колона в `investment_schedules` (frozen при генериране) вместо да се извежда обратно.
- **Усилие:** S

---

## Инвариант 5 — Закръгляне и запазване на сумата

**Гарантирано: ДА за офертния двигател и за Hamilton-разпределението; ДА (детерминирано, но неравно) за legacy buyback; една вътрешна несъгласуваност.**

Доказателства:
- **Hamilton split** ([InvestorDistributionService.php:107-168](../../../app/Services/Loans/InvestorDistributionService.php)): всеки дял = `bcadd(exact, '0', 2)` (отсичане), остатъкът в стотинки = `(amount − Σ floor) × 100` като цяло число, раздаден по една стотинка на най-големите дробни части с tie-break по `user_id`. По конструкция Σ дялове = сума **точно**; никой не получава повече от exact + 0,01. Дегенериран случай (Σ тегла = 0) дава всичко на първия — сумата се пази.
- **Офертни графици** ([OfferProjectionService.php:99-121](../../../app/Services/OfferProjectionService.php)): анюитет — последният ред `principal = $remaining` поема дрейфа → Σ principal = инвестиция. Interest-only — главницата е една сума в последния ред. Капитализация — един ред `trunc(P·(1+r)^n, 2) − P`. Всички лихви `bcmul(..., 2)` **отсичат** (не закръглят): инвеститорът получава ≤ математическата лихва с до 0,01 на ред — детерминирано, в полза на платформата.
- **Капитализирано начисляване** ([PayoutAccrualService.php:164-212](../../../app/Services/PayoutAccrualService.php)): всеки месец = `цел − вече начислено (от леджъра)`; на падеж = `ред.interest − вече начислено` → Σ начислено = точно лихвата от реда.
- **Частично погасяване** ([EarlyClosureCalculationService.php:140-167](../../../app/Services/Loans/EarlyClosureCalculationService.php); [EarlyClosureExecutionService.php:229-259](../../../app/Services/Loans/EarlyClosureExecutionService.php)): затворената главница се разпределя по Hamilton; оставащата главница и `trunc(Σ лихва × keepShare)` се разпределят по редовете пак по Hamilton → Σ нови главници = остатък, Σ нови лихви = целевата лихва. Лихвата за ползваните дни е 30/360 (`DayCount`).
- **Legacy buyback interest** ([BuybackCalculationService.php:126-142](../../../app/Services/Loans/BuybackCalculationService.php)): последният инвеститор поема остатъка → Σ = total, но може да поеме до N−1 стотинки (неравно, детерминирано). Само legacy (0 живи кредита).
- **Промо бонус** ([PromotionService.php:67](../../../app/Services/PromotionService.php)): `bcdiv(bcmul(amount, pct, 4), 100, 2)` — отсичане, детерминирано.

### PAY-11 — Legacy: последната вноска връща «остатъка по леджър», а не сумата от реда
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [RepaymentService.php:90-101](../../../app/Services/RepaymentService.php) — `isFinalInstallment = unpaidCount <= 1`, при което главницата на всеки инвеститор е неговият точен остатък от леджъра, независимо от `$schedule->principal`.
- **Какво се чупи в пари:** запазването **на инвеститор** е гарантирано (Σ върнато = инвестирано). Но ако Σ остатъци ≠ главницата на последния ред (кредит, редактиран от админ, или инвестиция след частично изплатен график), реално разпределеното се разминава мълчаливо с борсовия график — без лог, без проверка. Само за legacy.
- **Поправка:** `Log::warning` + Telegram при разминаване > 0,01 между Σ остатъци и `$schedule->principal`.
- **Усилие:** S

### PAY-12 — Три различни дефиниции на «начислено до момента» за една и съща инвестиция
- **Тежест:** low · **Статус:** SUSPECTED (проследено статично; паричният ефект по моята сметка е неутрален за инвеститора, но класификацията в леджъра се разминава)
- **Къде:** [PayoutAccrualService.php:240-259](../../../app/Services/PayoutAccrualService.php) (LIKE `loan:{l}:investment:{i}:%`), [BuybackExecutionService.php:431-450](../../../app/Services/Loans/BuybackExecutionService.php) (LIKE), [EarlyClosureCalculationService.php:300-318](../../../app/Services/Loans/EarlyClosureCalculationService.php) (**точен** reference `…:capitalized`). Частичното погасяване освобождава начислено с reference `…:early_closure` ([EarlyClosureExecutionService.php:168, 181-188](../../../app/Services/Loans/EarlyClosureExecutionService.php)), който третата дефиниция **не вижда**.
- **Какво се чупи в пари:** при **второ** частично погасяване на капитализирана позиция калкулаторът надценява натрупаното (не приспада първото освобождаване). Таванът `accruedRelease ≤ closedInterest` (:263) ограничава щетата: инвеститорът получава същата сума в кеш, но част от нея се взема от кофата `accrued` (тип `interest_released`) вместо да се кредитира като `early_repayment_interest` → кофата `accrued` пада под очакваното от payout-двигателя, който на следващата нощ я «донапълва» с делта. Пожизненият тотал е верен; класификацията и междинното салдо — не.
- **Поправка:** една имплементация (`AccruedInterestLedger::netFor()`), използвана от трите; тест с две последователни частични погасявания (backlog C4).
- **Усилие:** S

---

## Инвариант 6 — Машини на състоянията

**Гарантирано: ДА за кредита (моделен гард + `transitionTo`), ДА за `bonus_grants` (DB CHECK); НЕ за `withdrawal_requests`/`deposit_requests`/`investment_schedules` (свободни `string` колони, гардове само в кода); критична дупка в late-детекцията за живия продукт.**

Изброяване:
| Обект | Статуси | Кой пише | Гард |
|---|---|---|---|
| `loans` | draft, published, funding, funded, active, late, default, repaid, bought_back ([Loan.php:118-132](../../../app/Models/Loan.php)) | `transitionTo`, Filament Select, услугите | `booted()` хвърля при невалиден преход (:188-193); funding→draft само при funded=0 (:201-209); `MANUAL_STATUS_BLOCKLIST` пази repaid/bought_back; `late → default` е **само ръчно** и без движение на пари |
| `investments` | няма статус | — | състоянието е производно от графика |
| `investment_schedules` | pending, late, paid, closed ([InvestmentSchedule.php:16-29](../../../app/Models/InvestmentSchedule.php)) | paid: PayoutAccrualService, BuybackExecutionService; closed/пренаписване: EarlyClosureExecutionService; **late: никой** | колоната е свободен `string` ([2026_06_18_100002:30](../../../database/migrations/2026_06_18_100002_create_investment_schedules_table.php)); гардът е `whereIn(['pending','late'])` + `lockForUpdate` в двигателите → двойно плащане на ред е изключено, докато всички пишещи минават през тези филтри (проверено за трите) |
| `amortization_schedules` (legacy) | pending, late, paid, default | LateDetectionService, RepaymentService, Buyback/EarlyRepayment, админ RM (само draft/published) | `status='paid'` гард |
| `withdrawal_requests` | pending → approved → processed; pending → rejected | WithdrawalService, Filament inline | само в кода; **няма approved → returned/failed** (PAY-13); свободен `string` |
| `deposit_requests` | pending → approved / rejected | DepositService | само в кода; свободен `string` |
| `bonus_grants` | locked → released / cancelled | BonusService | DB CHECK ([2026_08_18_000001:84-86](../../../database/migrations/2026_08_18_000001_add_locked_bonus_support.php)) + `released_at` двойка ([000002:267-275](../../../database/migrations/2026_08_18_000002_make_conditional_bonus_investable.php)) |
| `users.kyc_status` | pending → submitted → in_review → approved / rejected | ProfileController, UserResource | from-state recheck под lock ([UserResource.php:387-398](../../../app/Filament/Resources/UserResource.php)); **approved не може да бъде отнет** от UI (:151-164 — действията са видими само за submitted/in_review) |

Отговори на конкретните въпроси: изплащане/теглене **не може** да се одобри два пъти (lock + status recheck); **не може** да се одобри след отказ (гардът иска `pending`); кредит **не може** да се върне във финансируем статус с пари в него (`funding → draft` изисква funded=0, а `active` няма ребро назад); ред от графика **не може** да бъде платен от два двигателя (всички избират `pending/late` под lock и маркират в същата транзакция).

### PAY-13 — Late-детекцията вижда само legacy графици: офертните кредити никога не стават `late`, buyback/default са недостижими, авансирането няма спирачка
- **Тежест:** high · **Статус:** VERIFIED
- **Къде:** [LateDetectionService.php:63-84, 103](../../../app/Services/Loans/LateDetectionService.php) — работи само с `AmortizationSchedule`; [LoanStatusUpdaterService.php:65-66](../../../app/Services/Loans/LoanStatusUpdaterService.php) — `active → late` изисква `amortizationSchedules` със статус late; `InvestmentSchedule::STATUS_LATE`, `became_late_at`, `days_late` ([InvestmentSchedule.php:20, 40-42](../../../app/Models/InvestmentSchedule.php)) **нямат нито един писач** (grep по `app/`); buyback изисква `late|default` ([BuybackExecutionService.php:147-152](../../../app/Services/Loans/BuybackExecutionService.php)); payout-двигателят плаща в `PAYOUT_ELIGIBLE_STATUSES` без оглед на кредитополучателя ([Loan.php:100-106](../../../app/Models/Loan.php); [PayoutAccrualService.php:15-19](../../../app/Services/PayoutAccrualService.php)).
- **Какво се чупи в пари:** за **всички живи кредити** (0 legacy към 2026-08-18 по CLAUDE.md) автоматизацията F1/F2 е мъртва: кредит, по който кредитополучателят е спрял да плаща, остава `active`, инвеститорите продължават да получават главница и лихва от парите на платформата всяка нощ, `loans:detect-buyback-eligible` никога не го маркира, `Buyback Queue` е празна, а «експозицията» (`payouts:exposure`) се пуска само ръчно. Единственият изход е админ ръчно да смени статуса на `late` през Select — за което няма нито сигнал, нито срок. При 7 живи кредита и авансиране по график сумата расте линейно с всеки пропуснат месец на кредитополучателя.
- **Забележка за решенията:** плащането «по график» е изрично клиентско решение (CLAUDE.md). **Липсата на детекция на закъснение за офертните кредити не е** — CLAUDE.md описва F1/F2 като работещи и посочва buyback крон-а като пътя към оригинатора.
- **Възпроизвеждане:** офертен кредит `active` без `amortization_schedules`; постави `investment_schedules.due_date` 60 дни назад; пусни `loans:process-late` и `loans:detect-buyback-eligible` в тестова база → нула преходи, нула eligible; пусни `loans:process-payouts` → инвеститорите са платени.
- **Поправка:** (1) `LateDetectionService` да маркира и `investment_schedules` (или отделен източник на «кредитополучателят плати/не плати», защото днес няма запис за реалните входящи вноски по кредита); (2) `LoanStatusUpdaterService::markLoanLate` да гледа и двата графика; (3) праг «N дни без вноска от кредитополучателя → пауза на авансирането за този кредит» като platform setting, с решение на клиента.
- **Усилие:** M

### PAY-14 — Одобрението на теглене не проверява статуса на инвеститора
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [WithdrawalService.php:58-126](../../../app/Services/WithdrawalService.php) — единствената проверка е `status='pending'` на заявката; KYC гейтът е само на API входа ([routes/api.php:130-134](../../../routes/api.php)). Одобрен KYC не може да бъде отнет от UI (виж таблицата).
- **Какво се чупи в пари:** ако между заявката и одобрението компланс промени решението си (документите се оказват фалшиви; сигнал за ATO), няма механизъм, който да спре одобрението — админът може да натисне «Одобри» и парите излизат към IBAN на непроверен/компрометиран акаунт. AML-контролът е изцяло в главата на оператора.
- **Възпроизвеждане:** инвеститор с KYC approved създава заявка; `UPDATE users SET kyc_status='rejected'` (или бъдеща «отнеми KYC» функция); `WithdrawalService::approve()` минава.
- **Поправка:** в `approve()` под lock: `users.kyc_status === 'approved'` и няма флаг `frozen`; добави флаг `users.is_frozen` (SEC-04) и действие «Отнеми KYC».
- **Усилие:** S

---

## Инвариант 7 — Имутабилност и одитна следа

**Гарантирано: ДА за `transactions`, `audit_logs`, `loan_events` (MySQL триггери, тестовият пакет е закачен за MySQL — [phpunit.xml:32-33](../../../phpunit.xml)); частично за `investment_contracts` (само app-level); НЕ за редовете, които определят изплащанията.**

Факти:
- Триггери: [2026_03_29_110003:27-45](../../../database/migrations/2026_03_29_110003_add_transaction_immutability_triggers.php), [2026_04_27_000002:41-59](../../../database/migrations/2026_04_27_000002_add_audit_log_immutability_triggers.php), loan_events (миграция 2026_04_23_140004). Покриват UPDATE/DELETE, **не** TRUNCATE/DROP (само привилегии на DB потребителя ги спират).
- `InvestmentContract`: `performUpdate`/`performDeleteOnModel` хвърлят ([InvestmentContract.php:101-109](../../../app/Models/InvestmentContract.php)); query builder и raw SQL не са покрити (документирано, чака sign-off).
- `Auditable` е на: BonusGrant, Borrower, DepositRequest, Investment, Loan, LoanEarlyClosure, LoanOffer, LoanPromotion, PlatformSetting, Transaction, User, Wallet, WithdrawalRequest. **Не е на:** `InvestmentSchedule`, `AmortizationSchedule`, `SavedIban`, `LegalEntityProfile`, `BeneficialOwner`, `ConsentRecord`, `Originator`, `LoanGrant` (grep по `app/Models`).
- `logAudit` записва `auth()->id()` (null за крон) и редактира `password, remember_token, personal_id, iban, full_name, address, phone` ([app/Traits/Auditable.php:44-56](../../../app/Traits/Auditable.php)); `email`, `kyc_*_path`, `bank_reference`, `eik`, `legal_name` не се редактират (User е Auditable → пътищата към личните карти влизат в `audit_logs.new_values`).

### PAY-15 — Доказателството «кой одобри» е свободен текст; времето на одобрение се презаписва
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [WithdrawalService.php:119-123](../../../app/Services/WithdrawalService.php) `admin_note => "Approved by admin #{$adminId}"`, `processed_at => now()`; [WithdrawalRequestResource.php:65-73](../../../app/Filament/Resources/WithdrawalRequestResource.php) «Обработено» презаписва `processed_at`; [DepositService.php:169-173](../../../app/Services/DepositService.php) същият модел; таблицата няма `approved_by/approved_at/processed_by/bank_reference` ([2026_03_29_100008:11-23](../../../database/migrations/2026_03_29_100008_create_withdrawal_requests_table.php)).
- **Какво се чупи в пари:** нищо директно; при инцидент («кой пусна 50 000 € и кога?») отговорът изисква парсване на JSON в `audit_logs` — `admin_note` е презаписваем текст, а моментът на одобрение се губи при «Обработено». За ECSP/PI одитор това е недостатъчно доказателство за контрол.
- **Поправка:** структурни колони + DB CHECK на статусите (backlog C3).
- **Усилие:** M

### PAY-16 — Редовете на графика (които определят всяко плащане) нямат одитна следа и се пренаписват на място
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [InvestmentSchedule.php](../../../app/Models/InvestmentSchedule.php) — без `Auditable`; [EarlyClosureExecutionService.php:250-259](../../../app/Services/Loans/EarlyClosureExecutionService.php) — `forceFill(principal, interest, total)->save()` върху съществуващите редове; предишните стойности оцеляват само като агрегат `loan_early_closures.ratio` ([2026_08_18_000003:36-40](../../../database/migrations/2026_08_18_000003_create_loan_early_closures_table.php)).
- **Какво се чупи в пари:** инвеститорът е подписал договор с приложение (индикативен график); след частично погасяване никой не може да реконструира по ред «какво беше обещано → какво стана» освен чрез обратно смятане от `ratio`. Ръчна SQL промяна на `principal` в pending ред мести реални пари на следващата нощ без следа.
- **Поправка:** `Auditable` на `InvestmentSchedule` и `AmortizationSchedule`; при пренаписване — нови редове с `superseded_by`, вместо UPDATE.
- **Усилие:** S (trait) / M (версиониране)

### PAY-17 — Добавяне/изтриване на IBAN не оставя одитен запис
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [SavedIban.php:8-32](../../../app/Models/SavedIban.php) — без `Auditable`; [ProfileController.php:335-365](../../../app/Http/Controllers/Api/ProfileController.php) — `create()`/`delete()` без лог, без нотификация.
- **Какво се чупи в пари:** най-експлоатираният път при ATO (смяна на IBAN → теглене) е невидим ретроспективно: кога и от кой IP е добавен IBAN-ът, към който излязоха парите? Само `withdrawal_requests.ip_address` на самата заявка остава.
- **Поправка:** `Auditable` (IBAN се редактира от trait-а) + нотификация към собственика (виж SEC-01).
- **Усилие:** S

---

## Инвариант 8 — Обработка на провали

**Гарантирано: НЕ.** Атомарността вътре в транзакциите е добра (всичко се връща назад), но **след** commit няма път назад за най-важната операция — изходящия превод.

### PAY-18 — Одобрено теглене няма обратен път: върнат/неуспешен банков превод оставя парите в лимбо
- **Тежест:** critical · **Статус:** VERIFIED
- **Къде:** [WithdrawalService.php:58-126](../../../app/Services/WithdrawalService.php) — «Одобри» дебитира `reserved` и пише `withdrawal` в леджъра **преди** човек да е пуснал превода; [WithdrawalService.php:157-163](../../../app/Services/WithdrawalService.php) — `reject()` работи само от `pending`; [WithdrawalRequestResource.php:65-73](../../../app/Filament/Resources/WithdrawalRequestResource.php) — единственият следващ статус е `processed`; няма тип `withdrawal_returned`/`refund` в [Transaction::TYPES](../../../app/Models/Transaction.php) (:91-109) и в `LEDGER_MAP`; `transactions` е неизменима на DB ниво.
- **Какво се чупи в пари:** банката връща превода (сгрешен IBAN, закрита сметка, санкционен скрининг) → парите са в омнибус сметката на платформата, но в леджъра инвеститорът е с 0. Няма бутон, услуга или тип транзакция, с които да му се върнат. Единствената «поправка» е ръчен депозит с фиктивен `bank_reference` — което разрушава банковата реконсилиация и записва лъжа в `deposit_requests`. Всяко възстановяване през raw SQL е блокирано от триггерите (правилно). Резултат: реален инвеститор с реални пари, които платформата държи и не може счетоводно да му върне.
- **Възпроизвеждане:** `createRequest` → `approve` → банката отхвърля превода → `reject()` хвърля `ModelNotFoundException` (статусът не е pending); никакъв друг публичен метод не докосва `withdrawal_requests`. Вж. теста в `SUMMARY.md`.
- **Поправка:** (1) нови типове `withdrawal_returned` (cash +1) и `withdrawal_failed` (ако дебитът се отложи), добавени в `LEDGER_MAP` със sign-off; (2) статус `returned` + Filament действие «Върнат превод» с банкова референция и причина; (3) в средносрочен план — дебитът да следва потвърждението от rail-а, не кликването (backlog P6).
- **Усилие:** M

### PAY-19 — Няма refund/reversal/корекция за грешно кредитиран депозит
- **Тежест:** high · **Статус:** VERIFIED
- **Къде:** grep `refund|reverse|reversal|chargeback|correction` в `app/` → само `reverseAccrued` (лихва) и коментари ([Loan.php:200-208](../../../app/Models/Loan.php): «Process investor refunds first — manual compensating transactions», без реализация); [DepositService.php:109-176](../../../app/Services/DepositService.php) стъмпва `amount` от ръчния вход на админа.
- **Какво се чупи в пари:** админът въвежда 5 000 вместо 500 (или кредитира грешен код) → парите са в `available` на потребителя и са **инвестируеми и теглими в същата секунда**; няма `debit` по инициатива на админа (единствените дебити са теглене, такса, инвестиция, отмяна на бонус). Ако инвеститорът вече е инвестирал, нищо не може да се направи без съгласието му.
- **Поправка:** тип `correction_debit/credit` с задължителна връзка към оригиналната транзакция и four-eyes; таван на кредитирането без втори одобряващ.
- **Усилие:** M

### PAY-20 — Провалът на изплащане за кредит е само ред в лога
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [ScheduledPayoutService.php:84-92](../../../app/Services/ScheduledPayoutService.php) `Log::error` и продължаване; [ProcessScheduledPayouts.php:63-67, 76-88](../../../app/Console/Commands/Loans/ProcessScheduledPayouts.php) — `FAILURE` + метрика; [SchedulerHealthController.php:125-135](../../../app/Http/Controllers/Api/SchedulerHealthController.php) показва `loans_failed`, но `status` остава `healthy`, докато кронът се изпълнява навреме. Няма Telegram/имейл при `loans_failed > 0` (за разлика от buyback/late).
- **Какво се чупи в пари:** инвеститорите в проваления кредит не получават нищо нощ след нощ; никой не е уведомен, освен ако не чете `storage/logs/loans-process-payouts.log`.
- **Поправка:** Telegram 🔴 + имейл в командата при `loans_failed > 0`; `payouts.status = 'warning'` в health endpoint-а при провал.
- **Усилие:** S

### PAY-21 — Няма реконсилиация спрямо банката; «не знаем какво стана» няма процедура
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** `ReconcileLedger` сравнява само леджър ↔ `wallets`; `bank_reference` е свободен текст от админа ([DepositRequestResource.php:202-206](../../../app/Filament/Resources/DepositRequestResource.php)), проверяван само за уникалност; `withdrawal_requests` няма банкова референция; `docs/runbooks/` съдържа само backup-restore.
- **Какво се чупи в пари:** дублиран превод, неправилна сума или несъпоставен изходящ превод се откриват само ако човек сравни извлечението на ръка. При инцидент няма дефинирана процедура «замрази → сравни → коригирай».
- **Поправка:** backlog O1/O5.
- **Усилие:** M

---

## Инвариант 9 — Свързаност с провайдъра

**Гарантирано: НЕ — семантиката «ръчен банков превод» е вградена в домейна.**

### PAY-22 — Банковите реквизити и «одобри = преводът е направен» са в кода
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [DepositController.php:35-44](../../../app/Http/Controllers/Api/DepositController.php) — IBAN/BIC на Postbank като литерали в контролера; `WithdrawalService::approve` = дебит (виж PAY-18); никакъв интерфейс `PaymentRail`/`Gateway` в `app/` (grep `interface`); форматът на `transactions.reference` се парсва на ≥5 места ([WalletService.php:591-601](../../../app/Services/WalletService.php); [InvestorDistributionService.php:61-66](../../../app/Services/Loans/InvestorDistributionService.php); [PayoutAccrualService.php:242-243](../../../app/Services/PayoutAccrualService.php); [BuybackExecutionService.php:433-434](../../../app/Services/Loans/BuybackExecutionService.php); [EarlyClosureCalculationService.php:302-307](../../../app/Services/Loans/EarlyClosureCalculationService.php)); EUR е имплицитен навсякъде (няма `currency` колона).
- **Какво трябва да се смени за PI/EMI:** (а) `DepositController::index` → отговор от `rail->fundingInstructions(user)`; (б) `DepositService::approve` → извикван от webhook `incomingCreditReceived(reference, amount)` вместо от ръчна форма; (в) `WithdrawalService::approve` да се раздели на «компланс одобрение» и «изпращане към rail», с дебит при потвърждение; (г) `payment_instructions` таблица със статусите на rail-а; (д) за индивидуални IBAN-и — `users.dedicated_iban` и съпоставка по IBAN; (е) `currency` колона. Нищо от това не изисква промяна на `WalletService` или на леджърните типове освен добавянето на `withdrawal_returned`.
- **Усилие:** M (виж backlog P1–P6)

---

## Инвариант 10 — Данъци и такси

### PAY-23 — Данък при източника върху лихвата на инвеститорите не съществува никъде
- **Тежест:** high (регулаторно; не губи пари днес) · **Статус:** VERIFIED за липсата в кода; **SUSPECTED** за правното задължение (трябва да го потвърди данъчен консултант — не твърдя данъчно право като факт)
- **Къде:** grep `tax|данък|withholding|ДДФЛ` в `app/`, `database/`, `resources/js/` → съвпадения само в правните страници на SPA; лихвата се кредитира бруто ([WalletService.php:553-582](../../../app/Services/WalletService.php)); няма поле за данъчна резидентност/ТИН в регистрацията ([RegisterRequest.php:55-96](../../../app/Http/Requests/RegisterRequest.php)) или профила ([UserResource.php (API):11-47](../../../app/Http/Resources/UserResource.php)); няма годишен отчет.
- **Какво се чупи в пари:** по договора платформата е ЗАЕМАТЕЛ, т.е. **платецът на лихва към физически лица** (CLAUDE.md, раздел договори). Ако консултантът потвърди задължение за удържане (за БГ резиденти и/или за EEA нерезиденти по чл. 37 ЗДДФЛ/СИДДО), днешният код е платил бруто и няма как ретроактивно да раздели плащанията, защото `transactions` е неизменима — корекцията ще е ново удържане при следващо плащане или извън платформата.
- **Поправка:** структурна готовност сега (backlog R3): `tax_residency`, `tax_withheld` кофа/тип, разделяне бруто/данък/нето при всяко лихвено плащане с правило по резидентност, годишна справка.
- **Усилие:** L

### PAY-24 — Таксата се определя при одобрение, не при заявка; базата и закръглянето минават през float
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [WithdrawalService.php:66-72](../../../app/Services/WithdrawalService.php) — котировката се взема вътре в транзакцията на **одобрението**; поведението е закрепено с тест ([tests/Feature/WithdrawalFeeTest.php:185-209](../../../tests/Feature/WithdrawalFeeTest.php)); SPA показва таксата от `/api/fees/config` в момента на заявката ([WithdrawalPage.vue:33-46](../../../resources/js/views/WithdrawalPage.vue)); таксата не се записва в `withdrawal_requests`; сумата ѝ идва през float (PAY-08).
- **Какво се чупи в пари:** инвеститорът заявява 100 € при показана такса 0 €; админът включва такса 2,50 € и одобрява → инвеститорът получава 97,50 € без да е информиран и без запис какво му е било показано. Дефинираната база е «плоска сума» (без процент), закръгляне — 2 знака от float. При обратния случай (изключена такса след заявката) платформата губи таксата. За потребителски/ECSP правила таксата трябва да е тази, разкрита при заявката.
- **Поправка:** `withdrawal_requests.fee_quoted` (стъмпва се при `createRequest`), `approve()` да начислява точно нея; `Money::normalize` за стойността.
- **Усилие:** S

**Марж на платформата.** ГПР − доходност («Марж», LoanResource) е само справочно число; в леджъра няма нито един запис, който да представлява прихода на платформата от спреда — той се реализира извън платформата. Не е дефект, но е още една сметка, която двустранният леджър (PAY-01) би трябвало да носи.

---

## Допълнителни находки от многоагентния преглед (потвърдени лично от водещия одитор)

Кандидатите по-долу са предложени от паралелните картографи; включени са само тези, чиито редове отворих и потвърдих сам. Отхвърлените или непотвърдените са в `SUMMARY.md`, раздел «Какво не проследих».

### PAY-25 — Страницата «Погашения» може да плати офертен кредит втори път
- **Тежест:** high · **Статус:** VERIFIED
- **Къде:** [ProcessRepayment.php:36](../../../app/Filament/Pages/ProcessRepayment.php) — списъкът е `Loan::where('status', active)` без `usesOffers()` филтър; [RepaymentService.php:42-158](../../../app/Services/RepaymentService.php) — няма проверка за офертен кредит и разпределя реда от `amortization_schedules` пропорционално на **всички** `investments` на кредита; офертен кредит може да има борсов план (`AmortizationSchedulesRelationManager` «Изчисли»/«Добави вноска» в draft/published, [:105-148](../../../app/Filament/Resources/LoanResource/RelationManagers/AmortizationSchedulesRelationManager.php); `Loan::fundingCap` и `LoanStatusUpdaterService::autoRepayOfferLoan :422-431` са написани точно за този случай).
- **Какво се чупи в пари:** инвеститорите в офертен кредит вече се плащат от `investment_schedules` (PayoutAccrualService). Ако админът избере същия кредит в «Погашения» и «обработи» вноска от борсовия план, `RepaymentService` им кредитира главница и лихва **втори път**. Единственият гард е `InvestedUnderflowException` в `WalletService` (:505-529), който хвърля само ако главницата от борсовия ред надвишава остатъка в `invested` на инвеститора — при ранните вноски (малка главница, голям остатък) двойното плащане минава изцяло, включително лихвата. Резултат: двойна главница + двойна лихва, теглими веднага, без аларма; реконсилиацията е «OK».
- **Възпроизвеждане:** офертен кредит с борсов план (draft → «Изчисли погасителен план» → публикуване → инвестиции → активиране); след първото нощно изплащане отвори «Погашения», избери кредита и първата вноска → `RepaymentService::processRepayment` минава.
- **Поправка:** `RepaymentService` да отказва кредити с `usesOffers()`; «Погашения» да не листва такива кредити; в средносрочен план — премахване на legacy клона (backlog D3).
- **Усилие:** S

### PAY-26 — Ръчно въведена лихва във вноска няма горна граница
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [AmortizationSchedulesRelationManager.php:50](../../../app/Filament/Resources/LoanResource/RelationManagers/AmortizationSchedulesRelationManager.php) — `interest` е само `numeric()->required()`; главницата има `ScheduleBalanceValidator` (:37-49), лихвата — не.
- **Какво се чупи в пари:** админ (или превзета админ сесия) създава legacy вноска с лихва 100 000 € — `RepaymentService` я разпределя и кредитира като `earned`/`available` (теглимо). Трети път за единолично «създаване» на пари след депозита и бонуса (SEC-02/03).
- **Поправка:** валидация `interest ≤ remaining × месечна лихва × 1.05` или четириочен принцип; `Auditable` на `AmortizationSchedule`.
- **Усилие:** S

### PAY-27 — Buyback и предсрочно погасяване прескачат инвестиции без график и затварят кредита терминално
- **Тежест:** low (понижено от medium след адверсариалната проверка) · **Статус:** VERIFIED за кода; **SUSPECTED за предпоставката** — верификаторът показа, че при днешния код (графикът се генерира в самата транзакция на инвестицията от 2026-08-13) инвестиция без редове не може да възникне по нормален път; тя е възможна само като заварени данни отпреди тази дата (за които съществува `loans:backfill-investment-schedules`) или ръчна намеса в базата. Дали в прод има такива редове, не може да се провери от кода.
- **Къде:** [BuybackExecutionService.php:349-356](../../../app/Services/Loans/BuybackExecutionService.php) — `if ($unpaid->isEmpty()) continue;` третира «няма редове» като «изцяло изплатено»; същото в [EarlyClosureCalculationService.php:83-91](../../../app/Services/Loans/EarlyClosureCalculationService.php); след това `bought_back`/`repaid` са терминални ([Loan.php:130-131](../../../app/Models/Loan.php)). Съществуването на инвестиции без график е реален случай — затова има `loans:backfill-investment-schedules` и coverage guard в `autoRepayOfferLoan` ([LoanStatusUpdaterService.php:392-404](../../../app/Services/Loans/LoanStatusUpdaterService.php)), но buyback/closure нямат такъв guard.
- **Какво се чупи в пари:** инвеститор с позиция без редове (пред-2026-08-13, невъзстановена) получава 0 при buyback, кредитът става `bought_back`, парите му остават в `invested` завинаги; реконсилиацията е «OK» (леджър = портфейл).
- **Поправка:** същият coverage guard преди терминален преход + аларма.
- **Усилие:** S

### PAY-28 — Възстановяване `late → repaid` гледа само борсовия план: офертен кредит може да стане терминален с неплатени инвеститорски редове
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [LoanStatusUpdaterService.php:212-221](../../../app/Services/Loans/LoanStatusUpdaterService.php) — tiebreaker `paidSchedules === totalSchedules` върху `amortizationSchedules` → `transitionTo(REPAID)`; `PAYOUT_ELIGIBLE_STATUSES` не включва `repaid` ([Loan.php:100-106](../../../app/Models/Loan.php)).
- **Какво се чупи в пари:** офертен кредит с борсов план, който е бил `late` и е бил ръчно/автоматично «възстановен» с всички борсови редове `paid`, се затваря като `repaid`, а `investment_schedules` на инвеститорите остават `pending` — двигателят повече не ги вижда. Главницата е блокирана в `invested`.
- **Поправка:** в `maybeRecoverLoan` — за `usesOffers()` терминалният преход да изисква и всички `investment_schedules` в `paid|closed` (както прави `autoRepayOfferLoan`).
- **Усилие:** S

### PAY-29 — `loans:process-payouts --asof` приема бъдеща дата и изплаща всичко до нея
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [ProcessScheduledPayouts.php:50](../../../app/Console/Commands/Loans/ProcessScheduledPayouts.php) — `Carbon::parse($this->option('asof'))` без горна граница; двигателят филтрира `due_date <= asOf` ([PayoutAccrualService.php:88](../../../app/Services/PayoutAccrualService.php)) и за капитализация смята `elapsed` до `asOf` (:158-162).
- **Какво се чупи в пари:** `php artisan loans:process-payouts --asof=2030-01-01` (грешка при ръчно пускане, «да догоним изтървана нощ») кредитира на всички инвеститори всички бъдещи вноски и лихви наведнъж — необратимо (леджърът е неизменим, няма reversal).
- **Поправка:** `asOf ≤ now()` в командата (както `EarlyClosureExecutionService` прави за `as_of`, :70-72).
- **Усилие:** S

### PAY-30 — Частично финансиран кредит няма край: плаща се до падеж, никога не става `repaid`, остава отворен за инвестиции
- **Тежест:** medium · **Статус:** VERIFIED · **is_product_decision:** частично (плащането от момента на инвестицията е решение на клиента; липсата на терминален път не е)
- **Къде:** [Loan.php:100-106, 118-132](../../../app/Models/Loan.php) — `published/funding` са payout-eligible, но единствените преходи от тях са `funding → funded/draft`; [LoanStatusUpdaterService.php:289](../../../app/Services/Loans/LoanStatusUpdaterService.php) — auto-repay обхожда само `active`; `EarlyClosureExecutionService::CLOSABLE_STATUSES` = active/late/default (:44).
- **Какво се чупи в пари:** кредит, който никога не се напълни, плаща на инвеститорите (от парите на платформата — кредитополучателят може изобщо да не е получил нищо) 12 месеца, след което остава `funding` завинаги, продължава да се показва като инвестируем и всяка нова инвестиция стартира нов 12-месечен цикъл. Няма начин главницата да се върне на инвеститорите преди падеж (closure не работи за `funding`).
- **Поправка:** решение на клиента: (а) `funding` с изтекъл срок → авто-`repaid`/затваряне за нови инвестиции; (б) closure и за funding-статуси; (в) праг «минимум X % финансиране, иначе връщане на парите» (ECSP чл. 22 тип защита).
- **Усилие:** M (след решение)

### PAY-31 — Депозити на непроверени (KYC) инвеститори се кредитират без проверка
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [DepositService.php:109-176](../../../app/Services/DepositService.php) — единствените проверки са статус, сума, наличие на портфейл и уникална референция; кодът се издава преди KYC ([routes/api.php:96](../../../routes/api.php) — `/deposit` не е зад `kyc`).
- **Какво се чупи в пари:** платформата приема и записва клиентски пари от лице, чиято самоличност не е проверена (AML: източник на средства); SPA обещава «обработката чака KYC», бекендът не го налага. Парите не могат да излязат без KYC (теглене е зад `kyc`), но могат да бъдат инвестирани? — не: `/invest` също е зад `kyc`. Остават блокирани в `available` без процедура за връщане (PAY-19).
- **Поправка:** `approve()` да изисква `kyc_status = approved` или изричен флаг «приемам с последващ KYC»; политика за връщане на превод от непроверено лице.
- **Усилие:** S

### PAY-32 — Леджърът приема всякакъв `type` и всякаква сума на ниво DB и шлюз
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [2026_03_29_100006:14-25](../../../database/migrations/2026_03_29_100006_create_transactions_table.php) — без CHECK на `amount > 0` и `type IN (...)`; [WalletService.php:32-55](../../../app/Services/WalletService.php) — `credit()` приема произволен `$type` низ (проверява се само сумата > 0).
- **Какво се чупи в пари:** грешно класифициран ред (напр. бонус, записан като `deposit`) ще «реконсилира» и ще замърси банковата съпоставка; ред с непознат тип ще срине реконсилиацията (default-deny) вместо да бъде отхвърлен при запис.
- **Поправка:** `in_array($type, Transaction::TYPES)` в шлюза + DB CHECK (изисква sign-off по CLAUDE.md).
- **Усилие:** S

### PAY-33 — Idempotency ключът при инвестиция е глобален, не по потребител
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [InvestmentService.php:41-46](../../../app/Services/InvestmentService.php) — `Investment::where('idempotency_key', $key)->first()` без `user_id`/`loan_id`.
- **Какво се чупи в пари:** нищо директно (ключът е UUID v4 от клиента — неотгатваем); но при повторение на **чужд** ключ API връща чуждата инвестиция с 201 «Investment successful» ([LoanController.php:240-247](../../../app/Http/Controllers/Api/LoanController.php)) — разкриване на данни и объркан клиент, ако ключ изтече (лог, прокси).
- **Поправка:** `where user_id = auth` + `loan_id`; 409 при чужд ключ.
- **Усилие:** S

### PAY-34 — KYC решението праща имейл синхронно вътре в транзакцията, която държи lock върху `users`
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [UserResource.php:387-398](../../../app/Filament/Resources/UserResource.php) — `$user->notify(new KycStatusNotification(...))` вътре в `DB::transaction` след `lockForUpdate`; `KycStatusNotification` **не** имплементира `ShouldQueue` ([KycStatusNotification.php:12-20](../../../app/Notifications/KycStatusNotification.php)) — mail + database каналите се изпълняват инлайн.
- **Какво се чупи в пари:** KYC е паричният гейт. SMTP таймаут държи реда на потребителя заключен секунди; SMTP грешка **връща назад** одобрението, макар че push/bell може вече да са тръгнали — обратното на правилото «пари първо, известия след commit», което самият CLAUDE.md документира като поука от 2026-08-17.
- **Поправка:** известието след commit (`DB::afterCommit`) или `ShouldQueue`.
- **Усилие:** S

### PAY-35 — Пълно предсрочно погасяване на капитализирана позиция оставя няколко дни лихва завинаги в `accrued`
- **Тежест:** medium · **Статус:** VERIFIED (по код; числено не е прогонено)
- **Къде:** двигателят начислява по **календарни милстоуни** от `firstDue = maturity − (term−1) months`, където `maturity = invest + 30·term дни` ([PayoutAccrualService.php:153-162](../../../app/Services/PayoutAccrualService.php); [OfferProjectionService.php:197-202](../../../app/Services/OfferProjectionService.php)) — за 12-месечен план първият милстоун е ~25 дни след инвестицията; погасяването смята «натрупаното до деня» по **30/360 цели месеци + стъб** от `invested_at` ([EarlyClosureCalculationService.php:234-248](../../../app/Services/Loans/EarlyClosureCalculationService.php)) и освобождава `min(накопено, дължимо)` (:263); при пълно затваряне кредитът става `repaid` ([EarlyClosureExecutionService.php:123-129](../../../app/Services/Loans/EarlyClosureExecutionService.php)) и никой повече не докосва `accrued` (нито release, нито reverse).
- **Какво се чупи в пари:** пример: инвестиция 1 000 € @20 %, пълно погасяване на 28-ия ден. Двигателят вече е начислил 1 месец (16,67 €, милстоунът е на ~25-ия ден); погасяването дължи 28/360 (15,56 €) → освобождава 15,56 €, а 1,11 € остават в `accrued` на инвеститор, чийто кредит е «Изплатен». Сумата е малка на позиция, но е **постоянна, натрупваща се и невидима за реконсилиацията** (леджър = портфейл); «Текущо салдо» на инвеститора показва фантомна печалба, която нито може да бъде изтеглена, нито отписана. Същото при всяко пълно затваряне между милстоун и следващата 30-дневна граница.
- **Поправка:** при пълно затваряне — след release на дължимото, `reverseAccrued` на остатъка за инвестицията (или release, ако бизнесът реши, че натрупаното по милстоун се дължи); еднакъв календар за двигателя и калкулатора (backlog C4).
- **Усилие:** S

### PAY-36 — Ръчен «Финансиран» от Select-а е задънена улица
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [Loan.php:242-258](../../../app/Models/Loan.php) — `selectableStatusTransitions()` за `funding` връща `funded` (само `repaid/bought_back` и `funded → active` са изключени); [LoanResource.php:230-262](../../../app/Filament/Resources/LoanResource.php) го предлага в Select-а; `funded → active` става само автоматично от `InvestmentService::invest` при последното евро (:129-144), което вече не може да се случи, защото `funded` не е `FUNDABLE`.
- **Какво се чупи в пари:** админ маркира частично финансиран кредит като «Финансиран» → кредитът не приема инвестиции, не може да стане `active`, а двигателят продължава да плаща (`funded` е payout-eligible). Няма път назад (`funded` няма ребро към `funding`).
- **Поправка:** премахване на `funded` от ръчните опции или гард `isFullyFunded()`.
- **Усилие:** S

### PAY-37 — Replay guard-ът на админ бонуса е неблокиращ `exists()` извън транзакцията
- **Тежест:** low · **Статус:** SUSPECTED (изисква два едновременни Livewire submit-а)
- **Къде:** [UserResource.php:292-305](../../../app/Filament/Resources/UserResource.php) — проверка по (user, type, amount, 2 мин) преди `grantAdminBonus`; вътре в транзакцията няма уникален ключ (референцията е `bonus:admin:{id}:{uuid}` — уникална за всяко извикване).
- **Какво се чупи в пари:** два таба/два бързи клика → двата минават проверката → два бонуса. Малка сума, но е единственият път за създаване на пари от един човек (SEC-03).
- **Поправка:** уникална референция от модала (`Hidden` uuid) → UNIQUE по `transactions.reference` или `bonus_grants.request_token`.
- **Усилие:** S

### PAY-38 — `published → draft` няма гард за налични пари и записът от формата не заключва реда
- **Тежест:** low · **Статус:** SUSPECTED (прозорецът е тесен; статично проследен)
- **Къде:** [Loan.php:119-120, 195-209](../../../app/Models/Loan.php) — `published → draft` е позволен без проверка на `funded_amount` (гардът е само за `funding → draft`); Filament формата записва без `lockForUpdate` ([EditLoan.php:93-96](../../../app/Filament/Resources/LoanResource/Pages/EditLoan.php)), за разлика от действието «Върни в чернова» ([LoanResource.php:419-421](../../../app/Filament/Resources/LoanResource.php)), което заключва.
- **Какво се чупи в пари:** админ отваря `published` кредит, инвеститор инвестира (кредитът става `funding`, `funded_amount` > 0), админът записва формата със `status = draft` → `booted()` сравнява с **паметния** оригинал (`published`) и пуска прехода; UPDATE-ът записва само dirty колоните → кредит в `draft` с пари вътре, невидим за инвеститора, плащан от двигателя? — не (`draft` не е payout-eligible), т.е. инвеститорът спира да получава плащания без обяснение.
- **Поправка:** `lockForUpdate` + fresh recheck в `EditLoan::handleRecordUpdate`; гард `funded_amount == 0` и за `published → draft`.
- **Усилие:** S

### PAY-39 — Офертата се чете от стар snapshot след заключването на кредита
- **Тежест:** low · **Статус:** SUSPECTED (следва от REPEATABLE READ; не е изпълнявано)
- **Къде:** [InvestmentService.php:41-59, 221-240](../../../app/Services/InvestmentService.php) — първото (неблокиращо) четене в транзакцията е проверката на idempotency ключа (:42) — то фиксира snapshot-а; `lockForUpdate` на `loans` (:49) чете актуалното, но `LoanOffer::where(...)->first()` (:223) е обикновен SELECT и вижда **snapshot-а отпреди заключването**.
- **Какво се чупи в пари:** админ сменя лихвата/изключва офертата в секундата, в която инвестиция чака lock-а → инвестицията се снапшотва със **старата** лихва, а quote-vs-commit гардът (:68-76) я сравнява със същата стара стойност и минава. Инвеститорът получава условия, които вече не съществуват (в негова или в ущърб на платформата).
- **Поправка:** `->lockForShare()`/`lockForUpdate()` на офертата или четене с `DB::selectFromWriteConnection`; тест с два процеса.
- **Усилие:** S

### PAY-40 — Анюитетният генератор може да произведе отрицателна главница в последния ред при много дълъг срок
- **Тежест:** low · **Статус:** SUSPECTED (посочено от търсач; механизмът е верен, числен пример не съм прогонил)
- **Къде:** [OfferProjectionService.php:99-121](../../../app/Services/OfferProjectionService.php) — `interest = bcmul(remaining, r, 2)` **отсича** надолу всеки ред, така `principalPart = payment − interest` е с до 0,01 по-голяма от точната; Σ на непоследните главници може да надхвърли P при стотици редове; последният ред = `remaining` без проверка за знак; `term_months` няма горна граница ([LoanResource.php:308](../../../app/Filament/Resources/LoanResource.php) — само `minValue(1)`).
- **Какво се чупи в пари:** ред с отрицателна главница се пропуска от двигателя (`bccomp(principal, 0) > 0`), но Σ на изплатените главници вече е > инвестицията → `InvestedUnderflowException` на някой от последните редове → цялото нощно изплащане на кредита пада (PAY-07).
- **Поправка:** `max: 360` на срока + assert `remaining ≥ 0` в генератора + тест по матрица (P от 50 до 1 000 000, срок 1–360).
- **Усилие:** S

### PAY-41 — Загубилата надпреварата инвестиция е HTTP 500 + CRITICAL Telegram
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [InvestmentService.php:197-203](../../../app/Services/InvestmentService.php) — fail-fast проверката хвърля `ValidationException`; но реалната проверка под lock в `WalletService::invest` (:272-274) хвърля `InvalidArgumentException`, която [LoanController::invest](../../../app/Http/Controllers/Api/LoanController.php) (:220-229) не улавя → 500 и CRITICAL аларма ([bootstrap/app.php:143-173](../../../bootstrap/app.php)) за нормален потребителски сценарий (две инвестиции от два таба с един баланс).
- **Поправка:** `catch (InvalidArgumentException) → 422` в контролера.
- **Усилие:** S

## Находки от кръга «критици за пълнота» (потвърдени лично)

### PAY-42 — Опашката, по която пътуват всички контролни имейли, няма наблюдение
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [config/queue.php:16](../../../config/queue.php) — `database` драйвер по подразбиране; графикът в [bootstrap/app.php:25-115](../../../bootstrap/app.php) няма `queue:monitor`/`queue:prune-failed`; `/api/health/scheduler` не знае за опашката ([SchedulerHealthController.php:92-136](../../../app/Http/Controllers/Api/SchedulerHealthController.php)); ресетът на парола е job ([AuthController.php:159-163](../../../app/Http/Controllers/Api/AuthController.php)), а по CLAUDE.md админ алармите за KYC, теглене, регистрация и логин са queued.
- **Какво се чупи в пари:** алармата за админ логин е документирана като «компенсираща контрола за липсата на 2FA»; мъртъв worker спира нея, известията за нови тегления и ресета на пароли — без нищо да го покаже. Всички «event-driven» контроли от раздел 02 зависят от процес, чието здраве никой не мери.
- **Поправка:** `queue:monitor database:default --max=…` в графика + метрика в health endpoint-а; `failed_jobs` аларма.
- **Усилие:** S

### PAY-43 — Buyback с покритие «главница + лихва» изплаща наведнъж цялата лихва до падеж
- **Тежест:** low · **Статус:** VERIFIED · **is_product_decision:** да (F2 Q2 — «scheduled interest of unpaid installments only», [BuybackCalculationService.php:19-23](../../../app/Services/Loans/BuybackCalculationService.php))
- **Къде:** [BuybackExecutionService.php:358-361, 403-412](../../../app/Services/Loans/BuybackExecutionService.php) — Σ `interest` на всички неплатени редове (за капитализация — цялата лихва на падежния ред) се освобождава/кредитира веднага.
- **Какво се чупи в пари:** капитализирана позиция 1 000 € @20 % за 12 м., изкупена във 2-ия месец, получава ~219 € лихва за 10 месеца, които не са изтекли. Ако оригинаторът реално плаща само главница + лихва до деня, разликата е за сметка на платформата. Панелът го квалифицира като решение, не дефект — но решението трябва да е взето с тези цифри пред очи.
- **Поправка:** покритие «лихва до деня (30/360)» като трета опция или потвърждение на решението писмено.
- **Усилие:** S

### PAY-44 — Единственият изход от `default` е предсрочно погасяване с лихва за целия период
- **Тежест:** low · **Статус:** VERIFIED · **is_product_decision:** да (write-off е отворено решение по CLAUDE.md)
- **Къде:** [EarlyClosureExecutionService.php:44](../../../app/Services/Loans/EarlyClosureExecutionService.php) (`CLOSABLE_STATUSES` включва `default`); [Loan.php:100-106, 242-258](../../../app/Models/Loan.php) (`default` не е payout-eligible и няма ръчен изход); лихвата се смята 30/360 от последния платен ред до `as_of` ([EarlyClosureCalculationService.php:203-211](../../../app/Services/Loans/EarlyClosureCalculationService.php)).
- **Какво се чупи в пари:** «default спира плащанията» е вярно до деня, в който админът затвори кредита — тогава инвеститорите получават офертна лихва за цялото просрочие. Няма затваряне «само главница» или write-off.
- **Усилие:** M (след решение)

### PAY-45 — Акаунт може да бъде закрит с одобрено, но още неизпратено теглене
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [AccountDeletionService.php:79-95](../../../app/Services/AccountDeletionService.php) проверява `invested/available/reserved`; след «Одобри» `reserved` вече е 0 ([WithdrawalService.php:91-123](../../../app/Services/WithdrawalService.php)); :98-99 блокира само `status='pending'` заявки; :143-150 трие `saved_ibans` и портфейла, :129-140 анонимизира името.
- **Какво се чупи в пари:** между «Одобри» и реалния превод инвеститорът може да изтрие акаунта си: човекът, който ще пуска превода, вече няма име на получател, IBAN-ите са изтрити, а върнат превод няма портфейл, в който да се върне (PAY-18 в най-лошия вариант).
- **Поправка:** блокиране на закриване при `withdrawal_requests.status IN (approved)`; при бъдещ `sent` статус — до `settled`.
- **Усилие:** S

### PAY-46 — Почти пълно частично погасяване оставя нулеви `pending` редове, които после стават «платени» без пари
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [EarlyClosureCalculationService.php:138, 143](../../../app/Services/Loans/EarlyClosureCalculationService.php) — `is_full` само при точно равенство, а Hamilton може да даде на един инвеститор целия му остатък; [EarlyClosureExecutionService.php:229-259](../../../app/Services/Loans/EarlyClosureExecutionService.php) — `remainingPrincipal = 0.00` → всички редове стават 0/0/0 и остават `pending`; [PayoutAccrualService.php:97-115](../../../app/Services/PayoutAccrualService.php) — редът се маркира `paid` дори без движение; [BonusService.php:166-202](../../../app/Services/BonusService.php) брои `paid` редове за условието на бонуса върху **оригиналната** сума на инвестицията.
- **Какво се чупи в пари:** позиция, върната почти изцяло, изпълнява условието «3 получени вноски» с три нулеви вноски → бонус, който клиентът изрично не искаше да се отключва при предсрочно затваряне, се освобождава.
- **Поправка:** редове с `principal = 0 AND interest = 0` след пренаписване → `closed`; условието на бонуса да брои само редове с `total > 0`.
- **Усилие:** S

### PAY-47 — Нощната реконсилиация не оставя доказателство и не е в health endpoint-а
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [ReconcileLedger.php:196-198](../../../app/Console/Commands/ReconcileLedger.php) — при успех само `$this->info()`; никакъв `PlatformMetric` (grep: 0 съвпадения); [bootstrap/app.php:27-30](../../../bootstrap/app.php) — единствената команда без `appendOutputTo`; [SchedulerHealthController.php:92-136](../../../app/Http/Controllers/Api/SchedulerHealthController.php) следи 3 крона, не и този.
- **Какво се чупи в пари:** за одитор «леджърът се сверява всяка нощ» е недоказуемо — мъртъв крон и чист рън изглеждат еднакво. Единствената контрола срещу дрейф (PAY-01) няма собствен пулс.
- **Поправка:** `PlatformMetric::record('last_reconcile_*')`, ред в health endpoint-а, таблица `reconciliation_runs` с резултат.
- **Усилие:** S

### PAY-48 — Договорният шаблон няма отпечатък: редакция на Blade файла променя всеки исторически PDF
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [InvestmentContract.php:45-49](../../../app/Models/InvestmentContract.php) — пази `template_version = 'v1'` и snapshot-и; `resources/views/contracts/` съдържа един файл; правилото «v1 не се пипа» е само дисциплина (CLAUDE.md).
- **Какво се чупи в пари:** доказателството за приети условия се рендерира на поискване от редактируем файл; без sha256 на шаблона/на рендера никой не може да докаже, че PDF-ът днес е този, който инвеститорът е приел.
- **Поправка:** `template_hash` при създаване + проверка при рендер; по избор архив на рендерирания PDF.
- **Усилие:** S

### PAY-49 — IP/UA върху леджърния ред е на админа, не на инвеститора, при админ-инициирани движения
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [WalletService.php:51-52](../../../app/Services/WalletService.php) — `request()?->ip()/userAgent()` на активната заявка се записват върху ред с `user_id` на инвеститора (депозит, бонус, одобрение на теглене = браузърът на админа; крон = null).
- **Какво се чупи в пари:** «форензичното доказателство» на транзакцията може да сочи грешния човек при спор «не съм го правил»; няма колона `actor_id`.
- **Поправка:** `actor_type/actor_id` върху `transactions` (изисква sign-off — таблицата е неизменима, но добавяне на колона е допустимо).
- **Усилие:** S

---

## Обобщение по инварианти

| # | Инвариант | Гарантирано? | Ключови находки |
|---|---|---|---|
| 1 | Двустранност / извеждане | не | PAY-01, PAY-02, PAY-32 |
| 2 | Идемпотентност | частично | PAY-03, PAY-04, PAY-05, PAY-33, PAY-37 |
| 3 | Конкурентност | да (портфейл) / частично (между процеси) | PAY-06, PAY-07, PAY-34, PAY-38, PAY-39, PAY-41 |
| 4 | Типове за пари | ядро да / ръбове не | PAY-08, PAY-09, PAY-10 |
| 5 | Закръгляне | да (с два ръба) | PAY-11, PAY-12, PAY-35, PAY-40, PAY-46 |
| 6 | Машини на състоянията | кредит да / останалите в кода; late-детекция мъртва за живия продукт | PAY-13, PAY-14, PAY-25, PAY-27, PAY-28, PAY-30, PAY-36, PAY-43, PAY-44, PAY-45 |
| 7 | Имутабилност / одит | леджър да / графици не | PAY-15, PAY-16, PAY-17, PAY-26, PAY-48, PAY-49 |
| 8 | Провали | не | PAY-18, PAY-19, PAY-20, PAY-21, PAY-29, PAY-31, PAY-42, PAY-47 |
| 9 | Провайдър | не | PAY-22 |
| 10 | Данъци / такси | не | PAY-23, PAY-24 |
