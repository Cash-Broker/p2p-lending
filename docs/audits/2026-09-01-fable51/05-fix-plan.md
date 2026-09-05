# План за поправки след одита (2026-09-03)

Контекст, зададен от Йордан: парите в платформата са виртуални (реалните се движат в банката), в момента има **над 60 000 € живи инвестиции**, и **бизнес логиката, поискана от Рени, не се променя без нейното знание** — поправяме бъгове, не решения.

## Правила за безопасност (важат за всяка поправка)

1. **Никакво движение на пари от миграция или скрипт.** Само добавящи миграции: нови nullable/default колони, нови таблици, нови индекси. Никакъв UPDATE върху `wallets`, `transactions`, `investment_schedules`, `investments`.
2. **`transactions`, `LEDGER_MAP`, DB CHECK-ове и триггери не се пипат** без изричен sign-off (CLAUDE.md, «Security review wiring» т. 3). Такива промени са в група C и чакат.
3. **Поведение, което Рени вижда (имейли до инвеститори, статуси на кредити, какво излиза в Telegram, какво може админът), не се променя мълчаливо** — група B е списък с въпроси към нея, не задачи.
4. **Всяка поправка идва с тест**, пуснат срещу `p2p_lending_test` (`php artisan test --filter=…`, после засегнатите пакети). Нищо не се комитва без зелени тестове.
5. **Нови контроли влизат с flag или праг по подразбиране «изключено/безкрайно»**, за да не спрат утрешните операции — включването е решение на Рени.
6. Деплой по реда от CLAUDE.md (config/route clear → migrate → cache → `queue:restart`).

---

## Група A — бъгове и hardening без промяна на решенията на Рени

Подредени по пакети; всеки пакет е един commit с тестове. Първо пакетите, които пазят пари.

### Пакет A1 — «двойно плащане и блокирана главница» (паричен път)
| ID | Поправка | Защо е бъг, а не решение |
|---|---|---|
| PAY-25 | `RepaymentService::processRepayment` отказва кредити с `usesOffers()`; «Погашения» листва само legacy кредити | двойно изплащане на едни и същи инвеститори; никой не го е поискал |
| PAY-28 | `LoanStatusUpdaterService::maybeRecoverLoan` — за офертен кредит преходът към `repaid` изисква всички `investment_schedules` в `paid|closed`, иначе → `active` | терминален статус с неплатени инвеститорски редове = блокирана главница |
| PAY-27 | coverage guard в `BuybackExecutionService::creditOfferBuyback` и `EarlyClosureCalculationService::quote`: инвестиция без редове → изключение, не «изплатена» | същото — блокирана главница; guard-ът вече съществува в auto-repay |
| PAY-29 | `loans:process-payouts --asof` ≤ днес | необратимо авансово изплащане при ръчна грешка |
| PAY-12 / HEALTH-01 | `EarlyClosureCalculationService::accruedToDate` да брои по същия LIKE reference като двигателя и buyback | три дефиниции на едно число |
| PAY-46 | след пренаписване редове с `principal = 0 AND interest = 0` → `closed`; условието за бонус брои само редове с `total > 0` | Рени изрично изключи предсрочно затворени вноски от бонуса — това ѝ спазва решението |
| PAY-35 | при пълно затваряне остатъкът в `accrued` за инвестицията се отписва (`reverseAccrued`) | лихвата е по правилото 30/360, което Рени избра; остатъкът е фантом от календара на двигателя |
| PAY-40 | горна граница на срока (360 м.) + assert `remaining ≥ 0` в генератора | отрицателна главница чупи нощното изплащане |

### Пакет A2 — «одобрение и закриване» (контроли, които не променят процеса на Рени)
| ID | Поправка |
|---|---|
| PAY-14 | `WithdrawalService::approve` отказва, ако `users.kyc_status ≠ approved` или няма портфейл |
| PAY-45 | `AccountDeletionService` отказва при теглене в статус `approved` (не само `pending`) |
| PAY-34 | KYC известието към инвеститора след commit (`DB::afterCommit`), извън lock-а |
| PAY-41 | `LoanController::invest` → 422 при `InvalidArgumentException` от WalletService |
| PAY-36 | `funded` отпада от ръчния Select (по CLAUDE.md това е статус, «в който кредит не стои») |
| PAY-38 | `EditLoan` записва статус под `lockForUpdate` + fresh recheck; `published → draft` изисква `funded_amount = 0` |
| PAY-39 | офертата се чете с `lockForShare()` в транзакцията на инвестицията |
| PAY-33 | idempotency ключът се търси и по `user_id`; чужд ключ → 409 |
| PAY-05 | catch-ът проверява името на индекса преди повторното търсене |
| PAY-32 | `WalletService` приема само `Transaction::TYPES` (без DB CHECK) |
| PAY-02 | `ReconcileLedger` в една `REPEATABLE READ` транзакция + втори цикъл за `transactions` без портфейл |

### Пакет A3 — наблюдение (само добавя сигнали, нищо не спира)
| ID | Поправка |
|---|---|
| PAY-20 | Telegram 🔴 + имейл при `loans_failed > 0`; `payouts.status = warning` в health endpoint-а |
| PAY-47 | `PlatformMetric` за реконсилиацията (`last_reconcile_*`) + ред в health endpoint-а + `appendOutputTo` |
| PAY-42 | `queue:monitor database:default` в графика + метрика «най-стар job» в health endpoint-а; аларма при `failed_jobs` |
| SEC-18 | маскиране на Telegram токена в лога |
| SEC-12 (частично) | за `QueryException` към Telegram се праща само клас + `file:line`, без `getMessage()` (SQL с bindings) — алармата остава |

### Пакет A4 — хигиена на входа и одитна следа (добавящи миграции)
| ID | Поправка |
|---|---|
| PAY-03 | `withdrawal_requests.idempotency_key` (nullable, UNIQUE) + `X-Idempotency-Key` от SPA за `POST /withdrawal` |
| PAY-04 | `loan_early_closures.request_token` (nullable, UNIQUE) от модала; повторение → «вече е изпълнено» |
| PAY-15 | колони `approved_by`, `approved_at`, `processed_by` в `withdrawal_requests`; «Обработено» вече не презаписва `processed_at`; същото за `deposit_requests.approved_by` |
| PAY-16 / PAY-17 / SEC-13 / SEC-29 | `Auditable` на `SavedIban`, `InvestmentSchedule`, `AmortizationSchedule`, `Originator`, `LegalEntityProfile`, `BeneficialOwner`; списъкът за редакция + `email`, `kyc_*_path`, `bank_reference`, `eik`, `vat_number`, `legal_name` |
| SEC-07 / SEC-25 | `Cache-Control: no-store` за KYC документите; `AuditLog` ред `viewed` при сервиране на документ/договор |
| SEC-09 | нормализация на IBAN във FormRequest (главни букви, без интервали); «нето > 0» проверка още при заявката |
| SEC-14 | `throttle:5,1` на смяна на парола и изтриване на акаунт |
| SEC-23 | `e()` върху имена в Filament известията |
| SEC-05 | `/admin/trust-ip` изисква логнат админ + еднократно използване |
| PAY-48 | `investment_contracts.template_hash` (sha256 на Blade файла при създаване; проверка при рендер) |
| SEC-19 | «Нов линк» (ротация) за частен кредит + списък/отмяна на grants |
| HEALTH-25 | тестът за неизменимост на `audit_logs` да очаква конкретното изключение; нов тест за триггерите на `transactions` |

### Пакет A5 — тестове за инвариантите (без промяна на код)
- Тестове за: двойно одобрение/отхвърляне/«Обработено» на теглене през Filament; RepaymentService върху офертен кредит; late-детекция върху офертен кредит (документира днешното поведение); две последователни частични погасявания на капитализирана позиция; approve → изтриване; почти пълно частично погасяване → нощен рън → бонус.
- Architecture test: без `(float)` в `app/Services`/`app/Http`; без `Transaction::create` и без запис в `wallets` извън `WalletService`.
- Трите фейлващи теста от `SUMMARY.md` влизат като регресионни, когда съответните поправки (PAY-18 → група C, SEC-01 → група B, PAY-14 → A2) са направени.

---

## Група B — въпроси към Рени (нищо не се променя без нея)

> **Решения на Йордан, 2026-09-03** (за всеки ред по-долу): PAY-13 ✅ да (праг X дни, настройка изкл.) · PAY-31 ✅ «без одобрен KYC няма депозит» (направено: approve отказва) · PAY-30 ✅ да · PAY-43/44 ❌ остават както са · PAY-24 ✅ да (направено: `fee_quoted`) · PAY-18/19 ✅ да — LEDGER_MAP частта чака изричен sign-off · макс. срок ❌ без лимит · SEC-01 ✅ да · SEC-02/03 ❌ засега не · SEC-11 ✅ да · SEC-21 ❌ · SEC-22 ✅ да · SEC-12 ❌ · SEC-16 ✅ да · останалите ❌ не се пипат. Оперативни аларми → `ADMIN_ALERT_EMAIL` (направено: `App\Support\OpsAlert`, всеки крон с `emailOutputOnFailure`, реконсилиация, неуспешен payout run, задръстена опашка).

| ID | Какво трябва да реши | Какво предлагаме |
|---|---|---|
| PAY-13 | Днес офертните кредити **никога** не стават «закъснели» автоматично и платформата ги плаща безкрайно. Да включим ли late-детекция и за тях? Това означава: инвеститорите ще получават имейл «кредитът закъсня», Buyback опашката ще се пълни, а платформата ще спре да авансира след X дни. | Да, с праг X дни за пауза на авансирането (нова настройка, по подразбиране изключена) |
| PAY-18 / PAY-19 | Какво правим при върнат банков превод или грешно кредитиран депозит? Днес няма път. | Статус «Върнат превод» + типове `withdrawal_returned` / `correction` (група C за реализацията) |
| SEC-01 | Да искаме ли парола/код при добавяне на IBAN и да уведомяваме ли инвеститора при заявено теглене и нов IBAN? Cooling-off за нов IBAN? | Да: потвърждение по имейл за нов IBAN + известие при заявка + 24 ч. забрана за теглене към нов IBAN |
| SEC-02 / SEC-03 | 2FA за админ панела; втори админ над праг за кредитиране/бонус/теглене; роли `finance`/`compliance`. | 2FA веднага (не променя процеса); праг за четириочен принцип — Рени да каже сумата |
| SEC-11 | Как реално се взема пълният IBAN за превода днес? Да добавим ли бутон «Покажи IBAN» с парола и запис в одита? | Да |
| PAY-24 | Таксата се удържа по стойността при одобрение, не при заявка. Да се фиксира ли таксата, показана на инвеститора при заявката? | Да (потребителска защита) |
| PAY-31 | Депозити от инвеститори без одобрен KYC се кредитират. Да чакат ли KYC? | Да чакат |
| PAY-30 | Частично финансиран кредит плаща до падеж и остава отворен завинаги. Кога се затваря? | Авто-затваряне след изтичане на срока + closure и за `funding` |
| PAY-43 / PAY-44 | Buyback «+лихва» плаща цялата лихва до падеж наведнъж; изходът от `default` плаща лихва за просрочието. Това ли е намерението? | Опция «лихва до деня» + write-off закриване |
| PAY-23 | Данък при източника върху лихвата — правен въпрос към данъчен консултант | Структурна готовност (резидентност, бруто/данък/нето) |
| SEC-15 | Договорът прави ВАМА АСЕТ заемател, не цесионер — правен въпрос преди ECSP | Становище от юрист |
| SEC-24 / SEC-26 / SEC-27 | UBO/ПЕП/произход на средствата за фирми; структурни KYC данни; AML прагове | Ръчен чеклист като минимум преди одобрение |
| SEC-12 | Имена и имейли на инвеститори в Telegram канала | ID/инициали вместо име |
| SEC-16 | Изтриването унищожава KYC документи веднага — AML срок за съхранение? | Задържан контейнер X години |
| SEC-21 | Да се заключи ли името след одобрен KYC? | Да, смяна само от админ |
| SEC-22 | Потвърждение по имейл + период при закриване на акаунт | Да |
| SEC-28 | Реквизитите «[… — ПОПЪЛВА СЕ]» в Общи условия/Поверителност | Рени да ги даде; архив на версиите |

---

## Група B — изпълнение (след решенията на Йордан, 2026-09-03)

| ID | Статус | Какво е направено |
|---|---|---|
| PAY-31 | ✅ | `DepositService::approve` отказва, ако `kyc_status ≠ approved` (BG toast); формата «Захрани сметка» предупреждава при кода. Кодът остава pending → одобрява се след KYC. Тестове: `GroupBControlsTest`. |
| PAY-24 | ✅ | `withdrawal_requests.fee_quoted` се записва при заявка; `approve()` удържа точно нея; NULL (стари редове) → текуща настройка. `WithdrawalFeeTest` обърнат (флагът след заявката НЕ променя таксата). |
| Оперативни аларми | ✅ | `config('app.admin_email')` ← `ADMIN_ALERT_EMAIL` (по подразбиране адресът на Йордан); `App\Support\OpsAlert` + `OpsAlertMail` (синхронно — опашката може да е счупена); всеки крон `emailOutputOnFailure`; реконсилиация, неуспешен payout run, QueueBusy → имейл. |
| SEC-11 | ✅ | «Покажи IBAN» на одобрени/обработени тегления: парола на админа (`AdminReauthenticationService`, 5 грешки/15 мин), ред `viewed` в `audit_logs` (суфикс, не IBAN), модал с копиране; 2-минутен билет. Тестове: `WithdrawalIbanRevealTest` (7). |
| PAY-30 | ✅ | `funding → repaid` (само системно; Select-ът не го предлага): нощният sweep + hook веднага след payout run затварят частично финансиран кредит, чиито инвеститорски редове са всички платени/закрити; инвестиция в приключил кредит → 422; пълно/частично предсрочно затваряне и от `funding`. LoanEvent с `from_status=funding`, `partially_funded_term_completed`, funded/investable/investors. Тестове: `PartiallyFundedLoanTest` (8). |
| PAY-13 | ✅ | **План на кредитополучателя** за офертни кредити: редове в `amortization_schedules` с `plan_kind='borrower_tracker'` (линеен, датите са водещи, сумите са ориентировъчни и НИКОГА не се разпределят). Създава се ръчно от таб „Погасителен план“; автоматичното създаване при активиране е зад ключ `borrower_tracker_auto_generate`, **ИЗКЛЮЧЕН по подразбиране** (Йордан 2026-09-05: Рени не е създавала план на нито един кредит и няма да отбелязва вноски на ръка — до избор на автоматизация PAY-13 е неактивен: без план кредитът не става „закъснял“, никой не получава имейл) (първа вноска + „платени до“, с изрично потвърждение при вече просрочени редове). Админът отбелязва вноските на кредитополучателя („Платена от кредитополучателя“ / „Отбележи платени до дата“) — нула пари. Нощната проверка вече прави офертните кредити „закъснели“ (имейл до инвеститорите с ТЯХНАТА неиздължена главница, buyback опашка, 🟠 Telegram). **Пауза на авансирането:** `payout_pause_enabled` (изкл. по подразбиране — „по график“ на Рени остава) + `payout_pause_late_days` (30); реконсилерът е стъпка 0 на `loans:process-payouts`, печатът `loans.payouts_paused_at` + настройката са единствената дефиниция (`Loan::isPayoutPaused()`), проверена под lock в `PayoutAccrualService`; задържаните редове остават `pending` и се доплащат при възобновяване; инвеститорът вижда „плащанията са спрени“ / „Задържана“. Тестове: `BorrowerPlanServiceTest` (8), `OfferLoanLateDetectionAndPauseTest` (11), `AmortizationScheduleBorrowerActionsTest` (4), `OfferLoanPauseApiTest` (4). |
| SEC-01 | ✅ | Нов IBAN → имейл с подписан линк за потвърждение (60 мин, повторно изпращане 3/10 мин); теглене само към **потвърден** запазен IBAN, минал `withdrawal_new_iban_cooldown_hours` (24) — `WithdrawalRequest` иска `saved_iban_id`, свободен IBAN е забранен; заварените IBAN-и се третират като потвърдени от датата на създаване; инвеститорът получава имейл при всяка заявка за теглене. Тестове: `SavedIbanConfirmationTest` (7), `WithdrawalIbanGateTest` (9), vitest `ibanEligibility`. |
| SEC-22 | ✅ | Закриване на акаунт в 4 стъпки върху `users`: заявка (парола, бърза проверка за празен акаунт, имейл с два подписани линка) → потвърждение по имейл (без сесия — пощата е вторият фактор; насрочва `account_deletion_waiting_days`=7) → отмяна (профил / линк „не съм аз“, който прекратява всички сесии и токени и вдига 🟠 + имейл до админите / админ с причина / смяна на паролата / блокирано финализиране) → финализиране от `accounts:finalize-deletions` (04:30, ключ `account_deletion_finalize_enabled`), което повтаря всички проверки под lock и анонимизира както преди. Парите не се движат (само конфискацията на заключен бонус, както досега). Тестове: `AccountDeletionFlowTest` (12), `FinalizeAccountDeletionsTest` (3), обновени `ProfileTest`, `AccountDeletionDepositCodeTest`, `BonusLockTest`, vitest `deletionState`. |
| SEC-16 | ✅ | При финализиране на закриването документите за самоличност се копират в `kyc-retained/{user}/` (copy-then-delete, само след commit), а съгласията и самоличността се снимат криптирано в `kyc_retentions` (часовник `kyc_retention_years`=5, ЗМИП чл. 67). Редовете са неизменими освен прехода „заличен“; достъп само за админ през `/admin/kyc-retained/{id}/{kind}` (одит `viewed`, `no-store`) и Filament «KYC архив»; `kyc:purge-retained` (05:00, ключ `kyc_retention_purge_enabled`) заличава изтеклите. Блокирано финализиране не оставя частичен архив. Тестове: `KycRetentionTest` (8), `PurgeRetainedKycCommandTest` (4). |
| PAY-18/19 | ❌ | **Отложено от Йордан 2026-09-05** («едва ли ще ни се наложи»). Дизайнът е готов и критикуван; реализацията добавя 3 типа в `Transaction::TYPES` + 3 реда в `LEDGER_MAP` ⇒ по CLAUDE.md чака изричен sign-off. Точното предложение е в раздела „PAY-18/19 — предложение за sign-off“ по-долу. |

**Въпроси от чертежите, които само собственикът може да реши** (нищо от това не е кодирано):
- PAY-30: след частично погасяване кредит в `funding` остава отворен за нови инвестиции (така е направено). Да има ли краен срок за набиране? Да получават ли инвеститорите имейл «кредитът приключи» при авто-затваряне (днес няма и при active→repaid)?
- SEC-01: ако инвеститорът не може да получи потвърждаващия имейл — да може ли админ да маркира IBAN като потвърден (одитирано, 24-те часа остават)? Заварените IBAN-и се третират като потвърдени.
- SEC-16: Политиката за поверителност §7 обещава физическо изтриване на KYC файловете — текстът трябва да се смени (privacy v1.2 → re-consent модал за всички) заедно с или преди функцията.
- SEC-22: Общи условия 10.1 / Поверителност §7 описват изтриване без потвърждение и период — версия v1.3 или оставяме процедурата извън документите? Да може ли админ да закрие незабавно по писмена молба?
- SEC-11: да се показва ли IBAN и при `pending` (за сверка преди одобрение)? Направено само за approved/processed.
- PAY-13: (а) офертни кредити в `funding` (частично финансирани, но плащащи) НЕ могат да станат „закъснели“ — няма преход `funding → late`; планът се създава само за активен/закъснял кредит. Да се добави ли ръбът? (б) стойността на `payout_pause_late_days` — заложени 30 дни като placeholder; трябва да е < 60 (buyback тригера), за да има смисъл. (в) имейлът до инвеститора при пауза (`LoanPayoutsPausedNotification`) е НОВО инвеститорско съобщение — текстът иска одобрение от Рени ПРЕДИ ключът да се включи. (г) по време на пауза «Текуща печалба» продължава да тиктака за кредита (претенцията съществува, само кешът е задържан) — така ли да остане? (д) кой и колко често отбелязва вноските на кредитополучателя (месечно, по извлечение от оригинатора)? Без отбелязване кредитът става „закъснял“ на падеж + 10 дни.
- SEC-22: линкът за потвърждение е валиден 24 ч.; линкът „не съм аз“ — до 2 дни след датата на закриване. Да може ли админ да закрие незабавно (днес няма такъв бутон — периодът е контролът)?
- PAY-30 (от ревюто): при частично предсрочно погасяване на кредит в `funding` намаляваме `funded_amount` със затворената главница, за да не се обяви кредитът «напълно финансиран» с по-малко реални пари — т.е. «финансиран» = това, което инвеститорите ДЪРЖАТ в момента. Така ли иска Рени, или частичното погасяване в `funding` трябва да е забранено?

## Група C — изискват изричен sign-off (CLAUDE.md)

> **Йордан, 2026-09-05:** PAY-18/19 — отложено («едва ли ще ни се наложи»); C8 — не («не виждам смисъл»); PAY-01 — извън обхват (реархитектура); PAY-49 — обяснено, без решение. Въпросите към Рени (праг на паузата, имейл при пауза, «финансиран» при частично погасяване от funding, кой отбелязва вноските) се решават когато дойде времето — функциите са изключени/неактивни дотогава.

| ID | Промяна | Защо е C |
|---|---|---|
| PAY-18 / PAY-19 | нови типове `withdrawal_returned`, `correction_debit/credit` в `Transaction::TYPES` + `LEDGER_MAP`; статус `returned` | LEDGER_MAP |
| PAY-01 | двустранен леджър (`ledger_entries`) | нова парична структура |
| C8 (backlog) | DB CHECK върху статусните колони и `transactions.type/amount`; триггер за `investment_contracts` | CHECK/триггери |
| PAY-49 | `transactions.actor_id` | колона в неизменима таблица |

### PAY-18/19 — предложение за sign-off (дизайн 2026-09-05, НЕ е реализирано)

Два админски механизма върху съществуващите `WalletService::credit/debit`, нула нови CHECK-ове или тригери върху съществуващи таблици, три нови чисто-кешови реда в `LEDGER_MAP`:

1. **Върнат банков превод** (`WithdrawalService::markReturned`): за теглене в `approved|processed` кредитира точно нето-сумата от леджър реда `withdrawal_request:{id}` (никога от `amount`/`fee_quoted`; таксата НЕ се възстановява — открит въпрос), тип `withdrawal_returned`, референция `withdrawal_request:{id}:returned`, статус `returned` (терминален) + `returned_at/returned_by/return_bank_reference/return_reason`; заключване портфейл → ред; отказ при закрит акаунт (без портфейл); след commit — имейл до инвеститора, до другите админи и 🟠 Telegram. Това Е входящ банков ред (сверка по `return_bank_reference`).
2. **Корекция на грешно кредитиран депозит** (`LedgerCorrectionService::correctDeposit`): админът въвежда реалната сума по извлечението; разликата се дебитира (`correction_debit`) или кредитира (`correction_credit`) ВЕДНАГА в една транзакция, референция `deposit_request:{id}:correction:{uuid}`, ред в нова таблица `ledger_corrections` (FK към оригиналния и коригиращия леджър ред); отказ изцяло при вече похарчена сума (`InsufficientBalanceException` → максимумът е днешният `available`) или при чакащо теглене (първо отхвърли); пълно сторно (0) → статус `reversed` + освобождава `bank_reference` за правилния инвеститор; парола на админа (`AdminReauthenticationService`); настройка `ledger_correction_single_admin_cap_eur` (0 = без лимит). Това НЕ е банков ред. Бонус-подът в `reserve()` съзнателно не се гледа (корекцията връща пари на платформата).

**За подпис (дословно):**

```php
// app/Models/Transaction.php — TYPES + 3
public const TYPE_WITHDRAWAL_RETURNED = 'withdrawal_returned';
public const TYPE_CORRECTION_DEBIT = 'correction_debit';
public const TYPE_CORRECTION_CREDIT = 'correction_credit';

// app/Console/Commands/ReconcileLedger.php — LEDGER_MAP, след TYPE_BONUS_CANCELLED
Transaction::TYPE_WITHDRAWAL_RETURNED => ['cash' => 1,  'invested' => 0, 'earned' => 0, 'accrued' => 0],
Transaction::TYPE_CORRECTION_CREDIT   => ['cash' => 1,  'invested' => 0, 'earned' => 0, 'accrued' => 0],
Transaction::TYPE_CORRECTION_DEBIT    => ['cash' => -1, 'invested' => 0, 'earned' => 0, 'accrued' => 0],
```

Само в НОВАТА таблица (не пипа съществуващи ограничения): `chk_ledger_corrections_direction CHECK (direction IN ('debit','credit'))`, `chk_ledger_corrections_amounts CHECK (amount > 0 AND actual_amount >= 0 AND effective_before >= 0)`. Банкова сверка след това: очаквани клиентски пари в банката = Σ deposit + Σ correction_credit − Σ correction_debit − Σ withdrawal + Σ withdrawal_returned (бонусите се изключват, таксата е приход).

**Политики за решение преди реализация:** (1) такса при върнат превод — остава удържана или се връща; (2) банката връща по-малко (банкови разноски) — кой поема; (3) вече похарчена грешна сума — отказ + ръчен процес (дизайн) или частично прибиране; (4) лимит за един админ — 0 или сума. TYPES и LEDGER_MAP се пускат в един commit и `php artisan ledger:reconcile` минава веднага след деплой.

---

## Статус на изпълнението

**2026-09-03 — група A е направена изцяло (A1–A5). Некомитната, в работното дърво.**
Адверсариално ревю на A1/A2 (28 агента, 4 лещи: коректност / контроли / решения на клиента / тестове): нито една промяна не обръща решение на Рени; потвърдените забележки са поправени (таблица «След ревюто»). Без промяна на `LEDGER_MAP`, CHECK или тригери; без движение на данни.

### A1 + A2 — паричен път и контроли

| ID | Промяна | Файлове |
|---|---|---|
| PAY-12 / HEALTH-01 | една дефиниция на «натрупано до момента» | `app/Support/Loans/AccruedInterestLedger.php` (нов); `PayoutAccrualService`, `BuybackExecutionService`, `EarlyClosureCalculationService` делегират |
| PAY-25 | `RepaymentService` отказва офертни кредити; «Погашения» ги не листва | `RepaymentService.php`, `Filament/Pages/ProcessRepayment.php` |
| PAY-27 | coverage guard преди buyback / closure | `BuybackExecutionService.php`, `EarlyClosureCalculationService.php` |
| PAY-28 | `late → repaid` само ако и инвеститорските редове са платени/закрити | `LoanStatusUpdaterService.php` |
| PAY-29 | `--asof` ≤ днес | `ProcessScheduledPayouts.php` |
| PAY-35 | изпразнена ПОЗИЦИЯ (пълно затваряне ИЛИ почти пълно частично) отписва остатъка в `accrued`; сумата се пази в `loan_early_closures.accrued_written_off` | `EarlyClosureExecutionService.php` |
| PAY-46 | нулеви редове → `closed`; бонусът брои само платени редове с `total > 0` | `EarlyClosureExecutionService.php`, `BonusService.php` |
| PAY-40 | никога отрицателна главница (guard в генераторите); формата на кредита/офертата отказва срок×лихва, при които 50 € не се амортизират; `offer-quotes` → 422, не 500. Cap 360 НЯМА (беше моя измислица — махнат след ревюто) | `LoanResource.php`, `OffersRelationManager.php`, `LoanController.php`, `OfferProjectionService.php`, `AmortizationService.php` |
| PAY-14 | одобрение само при `kyc_status = approved` + BG toast-ове за всеки изход | `WithdrawalService.php`, `WithdrawalRequestResource.php` |
| PAY-45 | закриване на акаунт блокирано при одобрено, неизплатено теглене | `AccountDeletionService.php` |
| PAY-34 | KYC известието след commit | `UserResource.php` |
| PAY-41 | загубена надпревара при инвестиция → 422 САМО за `InsufficientBalanceException` (нов, хвърлян от WalletService); всяка друга грешка остава 500 + CRITICAL | `LoanController.php`, `WalletService.php`, `app/Exceptions/InsufficientBalanceException.php` |
| PAY-36 | `funded` отпада от ръчния Select | `Loan.php` |
| PAY-38 | `published → draft` изисква `funded_amount = 0`; записът на формата е под lock | `Loan.php`, `EditLoan.php` |
| PAY-39 | офертата се чете със `sharedLock()` | `InvestmentService.php` |
| PAY-33 / PAY-05 | idempotency ключ по потребител; catch само за индекса на ключа | `InvestmentService.php` |
| PAY-32 | `WalletService` приема само `Transaction::TYPES` | `WalletService.php` |
| PAY-02 | реконсилиация в една транзакция + проверка на леджър без портфейл | `ReconcileLedger.php` |


### След ревюто (2026-09-03) — потвърдени забележки и поправки

| Забележка | Поправка |
|---|---|
| PAY-41: `catch (InvalidArgumentException)` в invest маскираше грешки на state machine / проекция / договор като «недостатъчен баланс» и гасеше CRITICAL алерта | `App\Exceptions\InsufficientBalanceException` (подклас) — хвърля се само при недостиг в `WalletService`; контролерът лови само него |
| PAY-35: отписването беше по флага `is_full` на кредита — почти пълно частично затваряне изпразва една позиция и оставя фантомен `accrued` без ред, който да го пусне | отписване по позиция; `accrued_written_off` в реда на затварянето; тест с капитализирана двойка 1 999,99 / 2 000 |
| PAY-40: cap 360 не покрива случая (50 € при 12/16/20 % → отрицателна вноска от ~100–119 мес.; ≤ 84 мес. са чисти); коментарът «≤ 600 не се възпроизвежда» беше грешен | cap махнат; проекционно правило във формата (срок) и в офертата (лихва); `offer-quotes` → 422; коментарът поправен |
| `EditLoan::handleRecordUpdate` връщаше нов модел, а Filament ползва свързания `$record` → един запис stale | `$record->refresh()` |
| `Carbon::startOfDay()` мутираше `$asOf` в payouts командата | `->copy()` |
| KYC текст в admin toast на английски | BG |

### A3 — наблюдение (нищо не спира, само сигнализира)

- Неуспешен payout run → `PayoutRunFailedAdminNotification` (mail + bell до всички админи, dedupe по run) + 🔴 Telegram с id-тата на кредитите; в health `payouts.status = warning`.
- `ledger:reconcile` записва `last_reconcile_*` метрики; health има блок `reconcile` (никога не е пускан / > 48 ч / mismatch → **critical**, влиза в worst-of) и блок `queue` (чакащи jobs, най-стар чакащ, failed_jobs за 24 ч — максимум warning, не пипа 503).
- `queue:monitor database:default --max=100` всеки 15 мин → `QueueBusy` → `TelegramQueueBusyAlert` (🟠 + метрика). Регистриран в `AppServiceProvider`; `EventListenerRegistrationTest` обновен.
- Telegram: bot token-ът никога не влиза в лога (`[bot-token]`); `QueryException` → в Telegram само клас + файл:ред (SQL с bound values остава в laravel.log).
- `ledger:reconcile` вече пише в `storage/logs/ledger-reconcile.log`.

### A4 — вход и одитна следа (миграция `2026_09_03_000001_add_audit_fix_evidence_columns`, само ADD COLUMN/INDEX)

- `withdrawal_requests.idempotency_key` (unique) + SPA праща `X-Idempotency-Key` на `POST /withdrawal` (`utils/idempotency.js`, vitest); повторение връща първата заявка; чужд ключ → 422; едновременни → unique index, губещият получава заявката на печелившия.
- `withdrawal_requests.approved_by/approved_at/processed_by`, `deposit_requests.approved_by` — plain id-та с индекс, **без FK** (доказателството трябва да надживее изтрит/анонимизиран админ; целият test suite подава admin id 1).
- `loan_early_closures.request_token` (unique) + `Hidden` в двете форми → двоен submit се отказва под lock на кредита; `accrued_written_off` (PAY-35).
- `investment_contracts.template_hash` — sha256 на Blade шаблона при сключване; рендер при несъвпадение хвърля `LogicException` (v1 е бил редактиран — забранено по CLAUDE.md); стари договори с NULL се рендерират без проверка (без backfill — не се фабрикува доказателство).
- `Auditable` върху `SavedIban`, `InvestmentSchedule`, `AmortizationSchedule`, `Originator`, `LegalEntityProfile`, `BeneficialOwner`; редакция и на `eik`, `vat_number`, `legal_name`, `representative_egn`, `national_id`, `kyc_*_path`, `bank_reference`; e-mail се маскира (`r***@domain`) — промяната остава видима за ATO разследване, стойността не.
- Достъп до документи: KYC файл → `Cache-Control: no-store, private` + `audit_logs` ред `viewed` (кой чий документ; без пътя); договор PDF (админ маршрут и инвеститорски `GET /api/investments/{id}/contract`) → `viewed`.
- IBAN нормализация (uppercase, без интервали) при теглене и при запазен IBAN.
- Такса при теглене: сума ≤ такса се отказва при заявката (не дни по-късно при одобрение, докато парите стоят резервирани); проверката при одобрение остава за флаг, включен след заявката.
- Throttle: `PUT /profile/password` и `POST /profile/delete` → 5/мин. **Открит и поправен стар бъг:** всеки `throttle:N,1` без префикс споделя ЕДИН брояч на потребител — 5 прегледа на договор блокираха `POST /withdrawal` за минута. Всеки limiter вече има собствен bucket.
- `/admin/trust-ip/{user}/{ip}` — само логнатият адресиран админ (подписаният линк пътува по имейл).
- «Нов линк» (LoanResource + EditLoan) за частни кредити — нов `share_token`, старите `loan_grants` се трият; инвеститорите с позиция запазват достъп през инвестицията.
- `Phase3AuditTrailTest`: catch-all → изисква тригера (SQLSTATE 45000) и доказва реда.
- Toast с име на инвеститор в `UserResource` → `e()`.

### A5 — тестове

`ObservabilityAndEvidenceTest` (10), `ReviewFollowupsTest` (10), `InvariantSuiteTest` (6: двоен клик / втори админ по тегления; частично затваряне → buyback; пълно затваряне от `default`; характеризация PAY-13; архитектурни — няма нов `(float)` в money кода, транзакции и портфейлни кофи се пишат само от `WalletService`), +1 в `MoneyPathHardeningTest`, +5 в `SchedulerHealthEndpointTest`, +1 в `AdminLoginAlertTest`, `WithdrawalFeeTest` адаптиран (2), vitest `utils/idempotency.test.js` (3).

### Деплой (към реда от CLAUDE.md)

1. `php artisan migrate` — само добавящи колони/индекси; безопасно при живи инвестиции.
2. **Веднага след деплой:** `php artisan ledger:reconcile` — иначе health-ът е `critical` (503) до 03:00 (същата логика като `loans:process-payouts`). Полезно и като проверка след толкова промени по паричния път.
3. **Преди деплой, на прод:** `SELECT id, investment_id, principal FROM investment_schedules WHERE principal < 0;` и същото за `amortization_schedules` — до сега нямаше guard (PAY-40). Очаквано: 0 реда (живите кредити са ≤ 84 мес.).
4. `php artisan queue:restart`; `queue:monitor` върви от schedule-а (не е нов процес).
5. Нов лог: `storage/logs/ledger-reconcile.log`.
6. Кодът вече пише новите колони (`template_hash`, `approved_by`…) — миграцията A4 трябва да върви ПРЕДИ или ЗАЕДНО с кода, не в отделен по-късен деплой.

### След ревюто на група B (2026-09-05, 88 агента — 7 лещи, по 2 опровергатели на находка, критик)

29 потвърдени + 3 от критика, всички поправени; 6 спорни — 1 поправена (legacy IBAN тест), 5 са документирано поведение и остават.

| Находка | Поправка |
|---|---|
| **Закриването трие портфейла, а новата orphan-проверка на реконсилиацията иска и `earned` = 0** → всеки закрит акаунт с получена лихва = вечен нощен mismatch + 503 | Orphan-клонът сравнява само балансовите кофи (`cash`, `invested`, `accrued`); `earned` е житейски брояч. Тест: закрит акаунт с лихва → `ledger:reconcile` 0. |
| Линковете за IBAN и за закриване/«не съм аз» сменяха състояние на GET — mail-скенери (Defender/Proofpoint) ги отварят при доставка | GET показва страница с един бутон, действието е POST (CSRF, подписът е в action URL-а); IBAN линкът презарежда токен/срок ПОД lock (TOCTOU при resend). |
| «Не съм аз» не махаше push абонаментите — телефонът на нападателя продължава да получава сумите | `revokeAllSessions` трие и `push_subscriptions`; текстът на имейла го казва. |
| Закрит акаунт можеше да се съживи през reset на паролата (`deleted_N@removed.p2pinvest.bg` е чужд домейн) | Placeholder → `deleted_N@deleted.invalid` (RFC 2606); `User::isClosed()` (вкл. стария домейн) отказва вход, reset-линк и reset. |
| `.env.example` с `ADMIN_ALERT_EMAIL=` ЗАГЛУШАВА всички ops имейли (празният env бие default-а) | `config/app.php` с `?:`, `OpsAlert::DEFAULT_RECIPIENT`, примерът носи адреса. Тест. |
| Добавяне/изтриване на IBAN без throttle → mail-flood / confirmation fatigue през add→delete→add | `throttle:5,60,iban-add`, `throttle:10,60,iban-delete`. Тест: 6-тият опит → 429. |
| Частично предсрочно погасяване от `funding` не намаляваше `funded_amount` → фалшив «напълно финансиран» и грешен капацитет | При `funding` `funded_amount -= затворената главница` (само там е живо число). Тест с последващ инвеститор. ⚠ Продуктово следствие за Рени — виж въпросите. |
| Стъпка 0 на 04:00 (паузата) без изолация — една грешка спираше цялото плащане | try/catch на кредит и на цикъл; `last_payouts_pause_failed`; OpsAlert; плащанията продължават. Тест. |
| Дайджестът спираше да напомня за неотбелязана вноска щом стане `late` | Броят `pending` + `late`. Тест. |
| Неизтрити оригинални KYC файлове след закриване минаваха тихо (`throw => false`) | Проверка на boolean-а → Log::error + OpsAlert + 🟠. |
| Името на инвеститора остава в неизменимия одит и след анонимизация | `Auditable` маскира `users.name` до инициали (както имейла). |
| «Отмени закриването» — success toast и стар запис дори при no-op | Връщаната стойност се чете, записът се refresh-ва, warning toast при no-op. |
| Планирана дата на закриване се печаташе с 1 ден по-рано от реалното 04:30 | `deletion_scheduled_for` = началото на деня; датата в SPA/имейл/Filament = денят на крона. |
| Твърдо «7 дни» / «24 ч.» в имейл и SPA при настройки, които админ може да сменя | Имейлът получава `waitingDays`; API дава `deletion_waiting_days` и `cooldown_hours`; SPA ги чете. |
| Датите на вноските на кредитополучателя се сравняваха с UTC «днес» — след полунощ в София отбелязване «в бъдещето» | Календарни дни в Europe/Sofia (сървиз + date picker-и). |
| Deadlock клас (finalize: users→wallet→deposit/withdrawal; approve: request→wallet) | `DB::transaction(…, 3)` + `retain()` чисти остатъци преди копиране (идемпотентен retry). |
| Съгласията в KYC архива — на един ред | `listWithLineBreaks()`. |
| Stale published→draft — суров Livewire error | `EditLoan` лови `LogicException` → BG toast + презареждане на формата. |
| Loan страницата не показваше «Задържана» (без `loan` relation) + противоречив tooltip в портфолиото | `myInvestments` зарежда `loan`; етикетите са общи; tooltip-ът е условен. |
| Тестови дупки: admin известие при пауза; refresh на days_late само за живи кредити; reset-паролата отменя заявката; mail-only/sync на имейлите; компенсация след успешно копие; legacy IBAN → реално теглене; regex-ите на архитектурния тест пропускаха `->available =`, `increment('available')`, `DB::table('transactions')`; `(float)` сканът не гледаше app/Support и app/Console | Всички добавени/разширени. |

Спорни, оставени по документирано решение: включване/изключване на `payout_pause_enabled` праща повторно «спрени» (нов печат = ново събитие); PAY-30 отказът за инвестиция в приключил funding кредит не е зад ключа; админ bell-ове съдържат името (платформена конвенция).

### Деплой на група B (2026-09-05)

1. `php artisan migrate` — две добавящи миграции: `2026_09_03_000001` (A4 + SEC-01/22/16 колони, `kyc_retentions`, настройки) и `2026_09_05_000001` (PAY-13: `loans.payouts_paused_at`, `amortization_schedules.plan_kind/borrower_paid_on/recorded_by`, настройки `payout_pause_enabled`=false / `payout_pause_late_days`=30). Нищо не се UPDATE-ва.
2. `npm run build` (нови екрани: закриване на акаунт, IBAN потвърждение, пауза на плащанията) + `queue:restart`.
3. Веднага след деплой: `php artisan ledger:reconcile` (както при A) — нищо от група B не пипа леджъра, това е контролата.
4. **PAY-13 е неактивен след деплоя** — нищо за правене: `borrower_tracker_auto_generate` и `payout_pause_enabled` са изключени, нито един кредит няма план на кредитополучателя, нищо ново не може да направи офертен кредит „закъснял“. Активира се, когато изберете как вноските на длъжника да влизат автоматично: (а) внос на банково извлечение с напасване по номер на договора (1–2 дни работа), или (б) обърната логика — бутон „Длъжникът не плати“ за изключенията (половин ден). Ръчният път (бутоните в таб „Погасителен план“) съществува и днес, ако някога потрябва за отделен кредит.
5. SEC-16: Политиката за поверителност §7 обещава физическо изтриване на KYC файловете — текстът трябва да се смени (privacy v1.2 → re-consent) заедно с функцията или преди първото реално закриване.
6. Новите кронове (`accounts:finalize-deletions` 04:30, `kyc:purge-retained` 05:00) са в schedule-а — нищо ново в crontab. Здравният адрес добавя информативен блок `account_deletions` и `payouts.payout_pause_enabled`; нищо ново не влиза в worst-of.

### Нови въпроси към Рени (излязоха от ревюто)

- **Максимален срок на кредит.** Cap няма (тя поиска всичко да е редактируемо); формата отказва само математически невъзможни комбинации. Иска ли продуктов лимит (напр. 84 мес.)?
- ~~PAY-35 видим спад на «Текущо салдо»~~ — Йордан 2026-09-03: не се повдига, поведението е вярно и остава както е.

## Ред на работа

1. **A1 → A2** (паричен път, контроли) — днес; всеки пакет: код → тестове → `php artisan test` на засегнатите файлове → преглед на инвариантите от CLAUDE.md.
2. **A3** (наблюдение) — веднага след това; нулев риск за парите.
3. **A4** (добавящи миграции) — след потвърждение, че миграциите са само ADD COLUMN/INDEX; деплой с реда от CLAUDE.md.
4. **A5** тестове — паралелно с A1–A4.
5. **B** — списъкът се предава на Рени; каквото потвърди, влиза като отделни пакети.
6. **C** — след sign-off.
