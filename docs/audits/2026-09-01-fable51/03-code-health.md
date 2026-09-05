# Фаза 3 — Здраве на кода (само каквото застрашава паричния път)

Дата: 2026-09-01 · Режим: само четене. Стил, именуване и подобни не са тема тук — само дублирана финансова логика, бизнес правила на грешното място, обекти-богове, N+1 върху финансови агрегати и липсващи тестове за инвариантите от Фаза 1.

---

## 3.1 Дублирана финансова логика (една истина на няколко места)

### HEALTH-01 — «Натрупано до момента» за капитализирана позиция е реализирано три пъти, с различна семантика
- **Тежест:** medium · **Статус:** VERIFIED (виж PAY-12 за паричния анализ)
- **Къде:** [PayoutAccrualService.php:240-259](../../../app/Services/PayoutAccrualService.php) (LIKE `…:%`), [BuybackExecutionService.php:431-450](../../../app/Services/Loans/BuybackExecutionService.php) (LIKE), [EarlyClosureCalculationService.php:300-318](../../../app/Services/Loans/EarlyClosureCalculationService.php) (точен reference `…:capitalized` — не вижда `…:early_closure`).
- **Защо застрашава коректността:** трите двигателя (нощно изплащане, buyback, предсрочно погасяване) взимат решения за една и съща кофа `accrued` по три различни числа. Вторият path вече се разминава след първо частично погасяване.
- **Поправка:** `AccruedInterestLedger::netFor(loanId, investmentId)` + тест с последователни операции през трите двигателя.
- **Усилие:** S

### HEALTH-02 — Анюитетният цикъл съществува в два PHP файла и един JS файл, които трябва да са байт-идентични
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [AmortizationService.php:76-112](../../../app/Services/AmortizationService.php) и [OfferProjectionService.php:99-121](../../../app/Services/OfferProjectionService.php) — един и същ цикъл (лихва върху остатък, последен ред поема дрейфа), споделена е само формулата за месечната вноска (`calculateMonthlyPayment`, :124-139); [resources/js/utils/whatIf.js](../../../resources/js/utils/whatIf.js) реплицира отсичането с float (CLAUDE.md го документира като «правило»).
- **Защо застрашава коректността:** промяна в единия цикъл (напр. закръгляне вместо отсичане на лихвата) променя реалните плащания, без прожекцията и договорното приложение да го отразяват — инвеститорът вижда една сума, получава друга. Тестът `OfferProjectionServiceTest` пази паритета днес, но само за случаите, които покрива.
- **Поправка:** `OfferProjectionService::amortizing` да генерира редовете и за `AmortizationService` (един цикъл); FE да получава редовете от `/offer-quotes` вместо да ги смята.
- **Усилие:** S

### HEALTH-03 — Две конвенции за разпределение на остатъчните стотинки
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** Hamilton в [InvestorDistributionService.php:107-168](../../../app/Services/Loans/InvestorDistributionService.php) (repayment, early closure); «последният инвеститор поема остатъка» в [BuybackCalculationService.php:126-142](../../../app/Services/Loans/BuybackCalculationService.php) (legacy buyback лихва) и по документация в `EarlyRepaymentCalculationService`.
- **Защо:** едната е справедлива (≤ 0,01 отклонение на инвеститор), другата може да натовари един инвеститор с N−1 стотинки. Само legacy, но докато кодът се доставя, той е «истина» за някой бъдещ legacy кредит.
- **Поправка:** всичко през `largestRemainderSplit`; изтриване на legacy варианта при изваждане на legacy клона.
- **Усилие:** S

### HEALTH-04 — «Остатъчна главница» се смята по четири различни източника
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** от **леджъра** — [InvestorDistributionService::outstandingPrincipalByUser :55-93](../../../app/Services/Loans/InvestorDistributionService.php); от **investment_schedules** — [EarlyClosureCalculationService.php:83-99](../../../app/Services/Loans/EarlyClosureCalculationService.php), [BuybackExecutionService.php:349-358](../../../app/Services/Loans/BuybackExecutionService.php), [LoanResource::outstandingPrincipal :763-769](../../../app/Filament/Resources/LoanResource.php); от **amortization_schedules** — [Loan::fundingCap :485-495](../../../app/Models/Loan.php).
- **Защо:** леджърът и графиците могат да се разминат (ръчно редактиран ред, грешен backfill) и тогава един двигател връща X, друг Y на същия инвеститор — точно семейството бъгове, което «clamp → throw» поправката в `WalletService` е трябвало да направи невъзможно; сега тя ще гръмне посред нощ вместо да се предотврати.
- **Поправка:** `ScheduleBalanceValidator`-подобна нощна проверка «Σ pending principal по инвестиция == invested − returned по леджър» с аларма (виж PAY-11).
- **Усилие:** S

### HEALTH-05 — Форматът на `transactions.reference` се парсва на пет места
- **Тежест:** low · **Статус:** VERIFIED · виж PAY-22 за местата.
- **Поправка:** `LedgerReference` value object (`forSchedule()`, `forCapitalized()`, `parseLoanId()`) — един източник на истина за формата, който днес е и «външен договор» (LIKE в SQL).
- **Усилие:** S

### HEALTH-06 — `formatAmount` и label-карти, дублирани във всяко Vue view
- **Тежест:** low · **Статус:** VERIFIED (CLAUDE.md го признава; grep `parseFloat` в `resources/js` → 40+ места) · **Поправка:** `utils/money.js`. · **Усилие:** S

---

## 3.2 Бизнес правила в контролери и Filament ресурси

| # | Място | Какво живее там | Защо е проблем |
|---|---|---|---|
| HEALTH-07 | [DepositRequestResource.php:208-285](../../../app/Filament/Resources/DepositRequestResource.php) | резолюция на код, предварителна проверка за дубликат, `Money::normalize`, картографиране на изключения към BG съобщения | 80 реда парична оркестрация в Livewire closure; тества се само през Livewire (`DepositCreditAccountActionTest`); четириочен принцип/лимит няма къде да се закачи |
| HEALTH-08 | [WithdrawalRequestResource.php:58-73](../../../app/Filament/Resources/WithdrawalRequestResource.php) | преходът `approved → processed` с inline `DB::transaction`; «Одобри»/«Отхвърли» извикват услугата без `try/catch` | единственият преход на тегленията **извън** `WithdrawalService`; при надпревара между два админа или `ValidationException` от таксата екранът показва суров Livewire error вместо BG toast (нарушава идиом 9 от CLAUDE.md); няма тест (виж 3.6) |
| HEALTH-09 | [UserResource::grantBonus :264-377](../../../app/Filament/Resources/UserResource.php) | replay guard, заявка към `BonusService`, 2 вида нотификации, Telegram, toast-ове | статичен метод в ресурс, извикван и от `DepositRequestResource`; replay guard-ът е бизнес правило, което трябва да е в `BonusService` |
| HEALTH-10 | [LoanController::alertAdminsOfInvestment :250-305](../../../app/Http/Controllers/Api/LoanController.php); [WithdrawalController::store :51-95](../../../app/Http/Controllers/Api/WithdrawalController.php) | fan-out на нотификации към админи (bell + mail + Telegram) с копирани `$safeNotify` closure-и | три копия на едно и също (и в `ProfileController::submitKyc :261-314`); контролерите знаят за Filament (`FilamentNotification`) |
| HEALTH-11 | [LoanResource.php:436-599](../../../app/Filament/Resources/LoanResource.php), [BuybackQueue.php:199-258](../../../app/Filament/Pages/BuybackQueue.php) | изплащане, предсрочно погасяване, buyback: изпълнение + цикъл по инвеститори за нотификации + toast-ове | 160+ реда оркестрация в UI слоя; при добавяне на API/CLI вход трябва да се копира |
| HEALTH-12 | [Loan::transitionTo :268-305](../../../app/Models/Loan.php) | моделът извиква `app(InvestmentScheduleGenerator::class)` / `app(AmortizationService::class)` при `funded → active` | паричен страничен ефект (генериране на графици) в Eloquent модел; трудно за тест и за разсъждение (кой генерира какво при коя транзакция) |
| HEALTH-13 | [DashboardController.php](../../../app/Http/Controllers/Api/DashboardController.php) (391 реда) | агрегации, «since last visit», следващо плащане, пазарни диапазони | display-only (без леджърен ефект), но всяко число, което инвеститорът вижда за парите си, минава оттук без услуга/тест на формулите |

---

## 3.3 Обекти-богове (само тези, които държат парична логика)

| Файл | Редове | Коментар |
|---|---|---|
| [LoanResource.php](../../../app/Filament/Resources/LoanResource.php) | 880 | форма + таблица + 8 парични действия + помощни (`outstandingPrincipal`, `closurePreview`, `runClosure`) |
| [Loan.php](../../../app/Models/Loan.php) | 600 | константи, машина на състоянията, генератор на графици, APR, оферти, достъп |
| [WalletService.php](../../../app/Services/WalletService.php) | 602 | кохезивен (12 метода, един шаблон) — **не** е проблем, но е единствената точка на отказ и заслужава architecture test (виж D2 в backlog) |
| [UserResource.php](../../../app/Filament/Resources/UserResource.php) | 519 | KYC преходи + бонус + телефон + инфолист |
| [LoanStatusUpdaterService.php](../../../app/Services/Loans/LoanStatusUpdaterService.php) | 459 | две машини (late/recovery и auto-repay) с два източника (amortization/investment schedules) — точно тук е дупката PAY-13 |
| [BuybackExecutionService.php](../../../app/Services/Loans/BuybackExecutionService.php) | 451 | 110 реда docblock + два клона (offer/legacy) |
| [AccruedEarningsService.php](../../../app/Services/AccruedEarningsService.php) | 431 | display-only, но повторно имплементира натрупване (виж HEALTH-01 семейството) |

---

## 3.4 Мъртъв/осиротял код в паричния път

| # | Какво | Къде | Риск |
|---|---|---|---|
| HEALTH-14 | `InvestmentDisbursementService` — «orphaned (test-only)» | [app/Services/InvestmentDisbursementService.php](../../../app/Services/InvestmentDisbursementService.php) | втори двигател за изплащане, който някой може да извика; CLAUDE.md го признава |
| HEALTH-15 | Legacy клонът (RepaymentService, AmortizationService::generateSchedule, EarlyRepayment*, BuybackCalculationService::distribute, ProcessRepayment страница) при **0 живи legacy кредита** | множество | поддържа се двойна семантика на «остатък», «late» и «разпределение»; late-детекцията работи само за него (PAY-13) |
| HEALTH-16 | `TYPE_BONUS_RELEASED` (никога не се пише), `deposit_requests.expires_at` (+ API поле), `LoanEvent::TYPE_FEE_APPLIED`, `wallets.bonus_locked` (премахнат, но кодови коментари остават) | [Transaction.php:82-85](../../../app/Models/Transaction.php), [DepositController.php:28-37](../../../app/Http/Controllers/Api/DepositController.php) | шум за одитор; нулев паричен риск |
| HEALTH-17 | Висящи референции към несъществуващ `DECISIONS.md` | [FeeService.php:21, 24](../../../app/Services/FeeService.php), [PlatformSettingResource.php:23](../../../app/Filament/Resources/PlatformSettingResource.php), [BuybackExecutionService.php:44](../../../app/Services/Loans/BuybackExecutionService.php) и др. | решенията, на които се позовава кодът, не могат да се проверят |
| HEALTH-18 | `envtest/` (throwaway harness), липса на `.github/` | корен | няма CI: финансовият пакет се пуска само ръчно |

---

## 3.5 N+1 и цена на заявките върху финансови агрегати

| # | Място | Форма | Оценка |
|---|---|---|---|
| HEALTH-19 | [ReconcileLedger.php:110-117](../../../app/Console/Commands/ReconcileLedger.php) | 1 `SUM … GROUP BY type` на портфейл, `chunk(100)` | O(портфейли); индекс `(user_id, type)` съществува ([2026_03_29_100006:23](../../../database/migrations/2026_03_29_100006_create_transactions_table.php)) — приемливо до десетки хиляди; проблемът е консистентността (PAY-02), не цената |
| HEALTH-20 | [BonusService::qualifiedInvestedAmount :166-202](../../../app/Services/BonusService.php) | `withCount` ✅, но fallback към `AmortizationSchedule::count()` ×2 на legacy инвестиция | N+1 само за legacy — с 0 живи legacy кредита не боли |
| HEALTH-21 | [PayoutAccrualService::accruedToDate :242-249](../../../app/Services/PayoutAccrualService.php) | LIKE по `reference` на капитализирана инвестиция на рън | префиксен LIKE е индексируем при обикновен индекс върху `reference` (миграция `2026_04_15_000003` — **не съм отворил съдържанието ѝ**; SUSPECTED, че индексът е обикновен) |
| HEALTH-22 | [Loan::fundingCap :485-495](../../../app/Models/Loan.php) | `exists()` + `get()` на всяко извикване; викан в цикли ([PromotionService.php:141](../../../app/Services/PromotionService.php), Dashboard) | двойна заявка на кредит на страница; коректност ОК |
| HEALTH-23 | [PayoutAccrualService::processLoan :52-75](../../../app/Services/PayoutAccrualService.php) | всички инвестиции на кредита в една транзакция, `wallets` редове се заключват последователно и се държат до commit | при 500 инвеститора в кредит = 500 заключени портфейла за секунди; през деня («Пусни плащане сега») блокира техните API заявки (виж PAY-07) |

---

## 3.6 Тестово покритие на инвариантите от Фаза 1

Проверено чрез списък на тестовите методи и grep; **не съм отварял всеки тестов файл** — където не съм, пиша SUSPECTED. Пакетът е закачен за MySQL ([phpunit.xml:32-33](../../../phpunit.xml)), т.е. CHECK/триггерите реално се упражняват; няма CI.

| Инвариант | Покритие | Доказателство | Липсва |
|---|---|---|---|
| 1. Леджър ↔ портфейл | **силно** за жизнени цикли | [ReconcileLedgerTest.php:33-154](../../../tests/Feature/ReconcileLedgerTest.php); [Phase2WalletIntegrityTest.php A1–B5](../../../tests/Audit/Phase2WalletIntegrityTest.php); [AuditFixesTest.php:367-401](../../../tests/Feature/AuditFixesTest.php) | тест за потребител с транзакции без портфейл (PAY-02); тест, че `Transaction` не може да съществува без промяна в `wallets` (структурен — architecture test) |
| 2. Идемпотентност | **средно** | invest същ ключ [AuditFixesTest.php:176](../../../tests/Feature/AuditFixesTest.php), [PreLaunchFixesTest.php:101-125](../../../tests/Feature/PreLaunchFixesTest.php) (симулирано, не паралелно); двойно погашение [AuditFixesTest.php:310]; двоен buyback [Phase2WalletIntegrityTest B3]; консумиран код [DepositTest.php:350]; повторен рън на двигателя за същия ден ✅ [PayoutAccrualServiceTest.php:150](../../../tests/Feature/PayoutAccrualServiceTest.php) | **двойно одобрение на теглене**, **двойно «Обработено»**, **двойно частично погасяване с една и съща сума** (PAY-04; [EarlyClosureTest.php:171](../../../tests/Feature/Loans/EarlyClosureTest.php) тества *различни* последователни суми — т.е. потвърждава, че повторението е «валидно»), **RepaymentService върху офертен кредит** (PAY-25) |
| 3. Конкурентност | **липсва (реална)** | [LoanTest.php:303](../../../tests/Feature/LoanTest.php) «concurrent» е последователна симулация; [ProfileTest.php:440] тества `Cache::lock`; grep `pcntl|parallel` → нищо (потвърдено и от търсача за тестове) | две паралелни тегления на целия баланс; cron vs ръчен payout; deadlock `wallets`↔`bonus_grants` (PAY-06); stale snapshot на офертата (PAY-39) |
| 4. Типове за пари | **косвено; самите оракули ползват float** | паричните assert-и са върху низове; но очакваните стойности в одитните тестове минават през `number_format((float) …)` ([Phase2WalletIntegrityTest.php:475, 692-721](../../../tests/Audit/Phase2WalletIntegrityTest.php); [PerInvestorConservationTest.php:112-137](../../../tests/Feature/PerInvestorConservationTest.php)) — оракулът нарушава правилото, което проверява | тест, който фейлва при `(float)` в `app/Services`/`app/Http` и в `tests/Audit` (architecture test); тест за `FeeService::getAmount` с гранична стойност |
| 5. Закръгляне Σ = цяло | **силно за legacy, средно за офертите** | [AuditFixesTest.php:81] (3 инвеститора); [PerInvestorConservationTest](../../../tests/Feature/PerInvestorConservationTest.php); Python оракул [audit/reference_calculator.py](../../../audit/reference_calculator.py) — функции само за `amortization_schedule`, `pro_rata`, `buyback_total`, `early_repayment_total` (**legacy**); `OfferProjectionServiceTest`, `EarlyClosureTest` (SUSPECTED за Σ) | оракул за офертните планове (капитализация, interest-only, частично погасяване, начисляване по делта); две последователни частични погасявания (PAY-12) |
| 6. Машини на състоянията | **силно за кредита** | [Phase3StateMachineMatrixTest](../../../tests/Audit/Phase3StateMachineMatrixTest.php); [AuditFixesTest.php:215] | `approved → reject/return` на теглене (PAY-18); одобрение при отнет KYC (PAY-14); **late-детекция за офертен кредит** (PAY-13 — днес тест би минал «успешно» с нула преходи); DB CHECK за статусите (няма ги) |
| 7. Имутабилност | **силно за audit_logs/loan_events; за `transactions` — не е доказано** | [AuditFixesTest.php:402-445] тества триггерите на `audit_logs`; Phase3AuditTrailTest; InvestmentContractTest; grep за тест, който прави UPDATE/DELETE върху `transactions` и очаква SQLSTATE 45000 → **не намерих** (картографът wallet-ledger стигна до същото) | тест за триггерите на `transactions`; UPDATE на `investment_schedules.principal` без следа (PAY-16); `SavedIban` без audit (PAY-17) |
| 8. Провали след одобрение | **липсва** | — | върнат банков превод (PAY-18); провал на един инвеститор в payout на кредит (PAY-07) — SUSPECTED, че `ScheduledPayoutTest` покрива «един кредит фейлва, другите продължават», но не «инвеститорите в проваления кредит остават неплатени без аларма» |
| 9. Провайдър | n/a | — | — |
| 10. Данъци/такси | **такси средно; данъци нула** | [WithdrawalFeeTest.php](../../../tests/Feature/WithdrawalFeeTest.php) (закрепя таксата **при одобрение** — PAY-24) | тест «таксата, показана при заявката, е таксата, която се удържа»; данъци — нищо |
| Filament парични действия през Livewire | **частично** | DepositCreditAccountActionTest, AdminBonusTest, BuybackQueuePageTest, LoanResourceEarlyRepaymentActionTest, EarlyClosureAdminActionTest | **«Одобри»/«Отхвърли»/«Обработено» на теглене през Filament** — не намерих тест (grep `approve` в `WithdrawalAdminListTest` → нищо; SUSPECTED) |

### HEALTH-25 — Тест за неизменимост, който минава при всякакво изключение
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [tests/Audit/Phase3AuditTrailTest.php:236-261](../../../tests/Audit/Phase3AuditTrailTest.php) — `catch (\Throwable $e) { $this->assertTrue(true, …) }`: DB грешка от всякакъв вид (липсваща колона, connection refused) се брои за «одитният ред е защитен».
- **Защо:** това е тестът, който би трябвало да докаже неизменимостта на `audit_logs` в одитния пакет; днес доказва само, че *нещо* е хвърлило. За `transactions` изобщо няма такъв тест (виж 3.6).
- **Поправка:** `expectException(QueryException::class)` + `assertStringContainsString('immutable', …)`.
- **Усилие:** S

### HEALTH-26 — Одитните оракули смятат очакваните суми през float
- **Тежест:** info (адверсариалният панел го отхвърли като дефект: сумите са 2-знакови DECIMAL низове с малка величина и `number_format((float))` е точен за тях) · **Статус:** VERIFIED за модела; без ефект върху резултата на тестовете · виж 3.6 ред 4 — остава като хигиена: оракулът нарушава правилото, което проверява. · **Усилие:** S

### HEALTH-27 — Прекалено широки `$fillable` върху паричните/статусните модели
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [Loan.php:307-334](../../../app/Models/Loan.php) (`funded_amount`, `status`, `share_token`, `bought_back_at`, `early_repaid_at`), [WithdrawalRequest.php:15-24](../../../app/Models/WithdrawalRequest.php) (`status`, `processed_at`, `user_id`), [DepositRequest.php:16-27](../../../app/Models/DepositRequest.php) (`amount`, `status`, `confirmed_at`, `bank_reference`), [Investment.php:26-38](../../../app/Models/Investment.php) (`user_id`, `interest_rate`, `payout_type`), [Transaction.php:111-119](../../../app/Models/Transaction.php), [BonusGrant.php:45-58](../../../app/Models/BonusGrant.php) (`status`).
- **Защо:** днес нищо не подава request-масив към тези модели (проверено — виж 02-security §2.0), т.е. защитата е дисциплина на извикващия. Един бъдещ `->update($request->validated())` върху `WithdrawalRequest` би позволил смяна на `status`. Контрастира с `User`/`Wallet`, където това е направено правилно.
- **Поправка:** `$guarded` за статусните/паричните колони; записи през `forceFill` в услугите (както вече правят).
- **Усилие:** S

### HEALTH-28 — Няма тест, който минава през два двигателя последователно
- **Тежест:** low · **Статус:** VERIFIED (по имената и обхвата на тестовете; потвърдено от критик-кръга)
- **Къде:** [EarlyClosureTest.php:83-327](../../../tests/Feature/Loans/EarlyClosureTest.php) — затваряния само върху `active` кредити с кръгли суми, никога последвани от buyback; [OfferBuybackTest.php:69-181](../../../tests/Feature/OfferBuybackTest.php) — `late` се задава с `forceFill`, никога от частично затворен или `default` кредит; ProfileTest изтриване (:698-829) — никога с одобрено теглене.
- **Защо:** PAY-12, PAY-35, PAY-44, PAY-45, PAY-46 са точно взаимодействия между двигатели; всеки двигател е тестван сам за себе си.
- **Поправка:** сценарни тестове: частично → buyback; closure от default; approve → изтриване; почти пълно частично затваряне → нощен рън → бонус.
- **Усилие:** M

### HEALTH-24 — Финансовият Audit пакет не е в никакъв автоматичен рън
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [phpunit.xml:14-19](../../../phpunit.xml) (Audit «opt-in only», коментарът говори за «default CI suite», а `.github/` не съществува); CLAUDE.md: «No CI exists».
- **Защо:** най-ценните тестове (Phase2 оракул, Phase3 матрица) се пускат само когато някой се сети; локалните пускания страдат от `[2002]` флакове по CLAUDE.md, което обучава хората да игнорират червено.
- **Поправка:** backlog D1.
- **Усилие:** S
