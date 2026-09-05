# Фаза 2 — Сигурност (финтех повърхност)

Дата: 2026-09-01 · Режим: само четене. Не разглеждам общи OWASP теми, освен ако пряко водят до пари.

Всяка находка носи: тежест, **експлоатируемост** (какво трябва да има атакуващият), **радиус на щетата в пари**, статус VERIFIED/SUSPECTED, `файл:ред`, възпроизвеждане, поправка, усилие.

---

## 2.0 Какво е наред (проверено, за да не се повтаря работа)

| Проверка | Резултат | Къде |
|---|---|---|
| IDOR: нотификации | скопирани към `$request->user()->notifications()` | [NotificationController.php:34-61](../../../app/Http/Controllers/Api/NotificationController.php) |
| IDOR: транзакции / история на депозити и тегления | `where user_id` | [TransactionController.php:17](../../../app/Http/Controllers/Api/TransactionController.php), [DepositController.php:58](../../../app/Http/Controllers/Api/DepositController.php), [WithdrawalController.php:107](../../../app/Http/Controllers/Api/WithdrawalController.php) |
| IDOR: договор на чужда инвестиция | 404 (не 403 — без oracle) | [InvestmentContractController.php:33-35](../../../app/Http/Controllers/Api/InvestmentContractController.php) |
| IDOR: `saved_iban_id` на чужд потребител при теглене | `Rule::exists(...)->where('user_id')` + втора проверка | [WithdrawalRequest.php:25](../../../app/Http/Requests/WithdrawalRequest.php), [WithdrawalController.php:30-32](../../../app/Http/Controllers/Api/WithdrawalController.php) |
| IDOR: изтриване на IBAN | `SavedIbanPolicy::delete` | [ProfileController.php:360](../../../app/Http/Controllers/Api/ProfileController.php), [SavedIbanPolicy.php:25-28](../../../app/Policies/SavedIbanPolicy.php) |
| Mass assignment: `role`, `kyc_status`, салда | не са fillable | [User.php:29-47](../../../app/Models/User.php), [Wallet.php:19-21](../../../app/Models/Wallet.php); `funded_amount` не е поле във формата на кредита (LoanResource.php форма :230-308) |
| Смяна на парола | иска текущата | [ChangePasswordRequest.php:18](../../../app/Http/Requests/ChangePasswordRequest.php) |
| Смяна на имейл | **няма** ендпойнт (UpdateProfileRequest = name/phone) — положително | [ProfileController.php:35-43](../../../app/Http/Controllers/Api/ProfileController.php) |
| Невалидиране на другите сесии при смяна/ресет на парола | Sanctum `AuthenticateSession` е в stateful middleware → сесии с друг хеш на паролата се логаутват | [config/sanctum.php:84-88](../../../config/sanctum.php); ресетът ротира remember token ([AuthController.php:181-185](../../../app/Http/Controllers/Api/AuthController.php)) |
| CSRF/CORS за cookie-сесията | `statefulApi()` + explicit `allowed_origins`, `supports_credentials` | [bootstrap/app.php:123](../../../bootstrap/app.php), [config/cors.php:12, 23](../../../config/cors.php) |
| KYC файлове | `storage/app/private/kyc-documents/`, извън web root; сервиране само `auth` + `isAdmin` + защита от traversal | [config/filesystems.php:33-39](../../../config/filesystems.php), [routes/web.php:46-69](../../../routes/web.php) |
| Публичен health endpoint без PII | само операционни метрики | [SchedulerHealthController.php:92-136](../../../app/Http/Controllers/Api/SchedulerHealthController.php) |
| Публични маршрути без auth | само register/login/reset/health/fees/email-verify (HMAC + signed) | [routes/api.php:24-39, 149-163](../../../routes/api.php) |
| Webhook-и | няма — няма неавтентикиран път, който да кредитира салдо | `routes/*` |

Пълната матрица «ендпойнт → middleware → скопиране» за всичките 33 инвеститорски маршрута е проверена и от картографа `api-data-exposure` (транскриптът на одита); нито един cross-tenant read не е намерен освен глобалния idempotency ключ (PAY-33). Ключовите редове, които отворих лично: `LoanPolicy::view` + `Loan::isAccessibleBy` ([LoanPolicy.php:19-30](../../../app/Policies/LoanPolicy.php), [Loan.php:587-599](../../../app/Models/Loan.php)); `LoanController::myInvestments/events/shared` ([LoanController.php:180-200, 383-431](../../../app/Http/Controllers/Api/LoanController.php)); `InvestRequest` офертата да е на този кредит ([InvestRequest.php:24-29](../../../app/Http/Requests/InvestRequest.php)).

---

## SEC-01 — Верига «превзет акаунт → теглене»: без 2FA, без повторна автентикация при нов IBAN, без известие към собственика, с приемане на нов IBAN директно в заявката
- **Тежест:** critical · **Експлоатируемост:** паролата на инвеститор (фишинг, credential stuffing, преизползвана парола) · **Радиус:** целият `withdrawable` баланс на жертвата за едно одобрение; ограничен единствено от вниманието на един админ, който вижда само маскиран IBAN
- **Статус:** VERIFIED
- **Къде:**
  - Логин: само парола, `RateLimiter` 5 опита по ключ `email|ip` ([LoginRequest.php:36-68](../../../app/Http/Requests/LoginRequest.php)); няма TOTP/WebAuthn никъде (grep `two.?factor|totp|webauthn` → само алармата за админ логин и правна страница).
  - Добавяне на IBAN: `POST /api/profile/ibans` валидира само формата; без парола/OTP, без cooldown, без нотификация към собственика, без одитен запис ([ProfileController.php:335-356](../../../app/Http/Controllers/Api/ProfileController.php); `SavedIban` без `Auditable`, [SavedIban.php:8-32](../../../app/Models/SavedIban.php)). Маршрутът **не е** зад `kyc`/`consent.current` ([routes/api.php:117-119](../../../routes/api.php)).
  - Теглене към **никога невиждан** IBAN: `iban` се приема като суров вход в самата заявка ([WithdrawalRequest.php:22](../../../app/Http/Requests/WithdrawalRequest.php)) — запазен IBAN не е нужен.
  - Уведомяване: при заявка се уведомяват **само админите** ([WithdrawalController.php:71-95](../../../app/Http/Controllers/Api/WithdrawalController.php)); инвеститорът получава известие едва при одобрение ([WithdrawalService.php:128-132](../../../app/Services/WithdrawalService.php)) — т.е. когато парите вече са дебитирани.
  - Какво вижда одобряващият: име, сума, маскиран IBAN, дата ([WithdrawalRequestResource.php:36-53](../../../app/Filament/Resources/WithdrawalRequestResource.php)) — без флаг «нов IBAN», «първо теглене», «смяна на парола преди < 24 ч.», «логин от нов IP/устройство» (алармата за нов IP съществува **само за админи** — `SendAdminLoginAlert`).
  - Ресет на парола: standard broker, токен 60 мин ([config/auth.php:95-100](../../../config/auth.php)); след ресет няма период на забрана за теглене.
  - Няма известие към собственика при логин от ново устройство/IP (алармата `SendAdminLoginAlert` е само за админи), при смяна на парола ([ProfileController.php:70-77](../../../app/Http/Controllers/Api/ProfileController.php)) или при ресет; «Изход от всички устройства» ([AuthController.php:129-146](../../../app/Http/Controllers/Api/AuthController.php)) трие само Sanctum токени (неизползвани) и текущата сесия — други cookie-сесии остават; инвеститорът не може да види/прекрати сесиите си. Положително: смяната на паролата обезсилва другите сесии през `AuthenticateSession` и recaller-cookie-то (хешът е в него).
  - `remember` флагът се приема от API ([LoginRequest.php:36](../../../app/Http/Requests/LoginRequest.php)), макар SPA да не го праща — атакуващ с парола може да си издаде дългоживеещо recaller cookie.
  - Web-push устройствата на атакуващия преживяват смяна/ресет на парола — единственият код, който трие абонаменти на потребител, е закриването на акаунта ([AccountDeletionService.php:149](../../../app/Services/AccountDeletionService.php)); `changePassword` не ги докосва — таен канал за суми по депозити/тегления след «възстановяване».
- **Възпроизвеждане:** (1) вход с открадната парола; (2) `POST /api/withdrawal {amount: <withdrawable>, iban: <IBAN на атакуващия>}` — 201; (3) жертвата не получава нищо; (4) админът вижда «Иван Иванов заяви теглене на 4 900 €» и `****1234`; натиска «Одобри»; (5) преводът е ръчен, но нищо в системата не подсказва, че IBAN-ът е нов.
- **Поправка (минимален набор):** (а) 2FA за инвеститори поне при добавяне на IBAN и заявка за теглене (или потвърждение по имейл-линк за нов IBAN); (б) cooldown 24–48 ч. за теглене към IBAN, добавен след последния логин/ресет на парола; (в) имейл + push към собственика при добавен IBAN и при **заявено** теглене с бутон «Не съм аз → замрази»; (г) флагове в списъка «Тегления»: нов IBAN / първо теглене / IBAN ≠ IBAN на депозита / парола сменена преди < 72 ч.; (д) `Auditable` на `SavedIban`.
- **Усилие:** M (а–г) / S (д)

## SEC-02 — Превзет админ = неограничено създаване на пари и одобрение на собственото им изтегляне
- **Тежест:** critical · **Експлоатируемост:** паролата на един админ (Filament логин е само парола) или злонамерен вътрешен човек · **Радиус:** неограничен — «Захрани сметка» приема произволна сума срещу произволен pending DEP код, а «Одобри» теглене е същият човек
- **Статус:** VERIFIED
- **Къде:** [AdminPanelProvider.php:24-73](../../../app/Providers/Filament/AdminPanelProvider.php) — `->login()` без втори фактор; `AdminTrustedIp`/`SendAdminLoginAlert` само **алармират** (routes/web.php:31-43 — signed линк маркира IP като доверен), не блокират; «Захрани сметка» ([DepositRequestResource.php:158-286](../../../app/Filament/Resources/DepositRequestResource.php)) — сума `minValue(1)`, `maxValue(Money::MAX)` = 9 999 999 999,99 €, `bank_reference` свободен текст, проверяван само за уникалност; `DepositService::approve` ([DepositService.php:109-176](../../../app/Services/DepositService.php)) кредитира без втори подпис; «Начисли бонус» до 10 000 € на клик с 2-минутен replay guard ([UserResource.php:264-316](../../../app/Filament/Resources/UserResource.php)); «Одобри» теглене ([WithdrawalRequestResource.php:58-60](../../../app/Filament/Resources/WithdrawalRequestResource.php)) без праг и без втори одобряващ.
- **Възпроизвеждане:** атакуващият регистрира инвеститор (mule), взема DEP код, качва KYC (или админът си го одобрява сам — [UserResource.php:151-157](../../../app/Filament/Resources/UserResource.php)); като админ: «Захрани сметка» с кода, сума 200 000 €, bank_reference «FT2026090100001»; като инвеститор: заявка за теглене; като админ: «Одобри». В леджъра всичко е «валидно»; реконсилиацията в 03:00 минава (леджър = портфейл). Единственият сигнал: Telegram 🟠 «Голям депозит потвърден» (>5 000 €) и «Голямо теглене одобрено» (>1 000 €) — към канал, който същият админ може да чете.
- **Допълнителни отслабвания на админ credential-а:** админ акаунт може да се логне и през `POST /api/login` ([AuthController.php:88-100](../../../app/Http/Controllers/Api/AuthController.php) — без проверка на роля; сесията важи и за Filament, защото guard-ът е един) — втора повърхност за отгатване с лимит само по (email|IP); ресетът на админ парола минава през публичния forgot-password поток без алармa; няма UI за създаване/деактивиране на админ (offboarding = SQL); Filament логинът предлага «запомни ме» по подразбиране (recaller cookie надживява 120-минутната сесия).
- **Поправка:** (а) 2FA за админ панела (Filament има готови пакети) — най-евтина и най-ефективна; (б) four-eyes: кредитиране > X €, бонус > Y €, теглене > Z € изискват втори админ (maker ≠ checker, записан в колона); (в) роли: `finance` (одобрява тегления) ≠ `compliance` (KYC) ≠ `ops` (кредитира депозити); (г) банкова реконсилиация, която маркира депозити без съпоставен реален превод (backlog O1); (д) Telegram/имейл за **всяко** кредитиране към другите админи (както е за бонуса — [UserResource.php:329-347](../../../app/Filament/Resources/UserResource.php)) — днес депозитите нямат такъв «внутрешен контрол».
- **Усилие:** S (а) / M (б, в) / M (г)

## SEC-03 — Разделение на задълженията (maker-checker): нито едно парично действие не изисква втори човек
- **Тежест:** high · **Експлоатируемост:** един админ акаунт · **Радиус:** според действието (виж таблицата)
- **Статус:** VERIFIED

| Действие | Вход | Втори одобряващ | Праг | Доказателство кой | Външен сигнал |
|---|---|---|---|---|---|
| Кредитиране на депозит с произволна сума | DepositRequestResource.php:158-286 | няма | няма (до 9,99 млрд.) | `admin_note` текст + audit_logs | Telegram само > 5 000 € |
| Бонус до 10 000 € | UserResource.php:211-377 | няма | UI 10 000 € (fat-finger) | `bonus_grants.granted_by` ✅ | имейл до другите админи + Telegram 🟡 ✅ |
| Одобрение на теглене към произволен IBAN | WithdrawalRequestResource.php:58-60 | няма | няма | `admin_note` текст | Telegram само ≥ 1 000 € |
| «Обработено» (преводът е пуснат) | :65-73 | няма | — | нищо структурно | няма |
| Редакция на всички полета на кредит с инвестиции | EditLoan.php:118-145 | няма | — | audit_logs | няма (клиентско решение 2026-08-10) |
| «Пусни плащане сега» | LoanResource.php:436-466 | няма | — | audit_logs (Transaction) | няма |
| Предсрочно/частично погасяване | LoanResource.php:707-760 | няма | — | `loan_early_closures.executed_by` ✅, loan_events ✅ | Telegram 🟠 ✅ |
| Buyback execute | BuybackQueue.php:199-258 | няма | — | loan_events ✅ | Telegram 🟠 ✅ |
| Kill switches, grace period, такса | PlatformSettingResource / FeesPage | няма | — | audit_logs ✅ | няма |
| KYC одобрение | UserResource.php:151-157 | няма | — | audit_logs | няма |
| Смяна на роля / създаване на админ | **няма UI** (само seed/tinker) — положително | — | — | — | — |

- **Какво се чупи в пари:** всяка от горните е «едно кликване = пари». За ECSP/PI/EMI и за застраховател това е дисквалифицираща липса на контрол; за реалната операция днес (двама-трима админи) означава, че една компрометирана сесия е достатъчна.
- **Поправка:** таблица `pending_admin_approvals` с maker/checker за действия над праг; полета `approved_by`; Filament policy по роли (backlog R1, R8).
- **Усилие:** M

## SEC-04 — Няма механизъм за замразяване на акаунт или отнемане на KYC
- **Тежест:** medium · **Експлоатируемост:** следствие при всеки инцидент (ATO, AML сигнал) · **Радиус:** времето до ръчна намеса по DB
- **Статус:** VERIFIED
- **Къде:** grep `frozen|suspended|blocked|banned` → нищо в `users`/миграциите; KYC действията са видими само за `submitted/in_review` ([UserResource.php:145-164](../../../app/Filament/Resources/UserResource.php)) — `approved` е еднопосочно; `WithdrawalService::approve` не проверява потребителя ([WithdrawalService.php:58-126](../../../app/Services/WithdrawalService.php)); `EnsureKycApproved` проверява само `kyc_status === 'approved'` ([EnsureKycApproved.php:22](../../../app/Http/Middleware/EnsureKycApproved.php)).
- **Какво се чупи в пари:** при сигнал «клиентът е измамник» операторът няма бутон, който да спре инвестиции и тегления — единствено `UPDATE users SET kyc_status='rejected'` в DB (без audit trail от Filament) и надежда, че одобряващият ще види това.
- **Поправка:** `users.frozen_at/frozen_by/frozen_reason` + middleware + проверка в `WithdrawalService::approve`, `InvestmentService::invest`; действие «Отнеми KYC».
- **Усилие:** S

## SEC-05 — Signed «доверен IP» линк може да се използва от всеки, който го притежава
- **Тежест:** low · **Експлоатируемост:** препратен/изтекъл имейл с алармата · **Радиус:** заглушаване на алармите за админ логин от IP на атакуващия
- **Статус:** VERIFIED
- **Къде:** [routes/web.php:31-43](../../../routes/web.php) — само `signed` middleware, без `auth`, валиден 7 дни (по коментара), без ограничение на еднократност.
- **Поправка:** `auth` + `isAdmin` + еднократно използване (маркиране на signature като консумирана).
- **Усилие:** S

## SEC-06 — Ограничения на скоростта: без velocity контроли, публични лимити по IP
- **Тежест:** medium · **Експлоатируемост:** без специални условия · **Радиус:** оперативен шум; при ATO — скорост на изтегляне
- **Статус:** VERIFIED
- **Къде:** [routes/api.php:24-29](../../../routes/api.php) — register/login/forgot/reset 10/мин **по IP** (ротация на IP = масови регистрации, mail-bombing през `SendPasswordResetEmail`); withdrawal 5/мин **по потребител** = до 7 200 заявки/ден без дневен/месечен паричен лимит; invest 10/мин; KYC upload 6/мин по 3×10 MB с HEIC конверсия ([ProfileController.php:95-101](../../../app/Http/Controllers/Api/ProfileController.php)); contract-preview 20/мин dompdf; няма лимит «първо теглене не по-рано от N дни след регистрация/KYC».
- **Поправка:** дневен/месечен лимит за теглене (platform setting), cooling period за нови акаунти, глобален лимит за регистрации/час (вече има «повишен брой регистрации» аларма — да стане и спирачка).
- **Бележки:** публичният `/api/register` издава кои имейли имат акаунт (422 «вече е зает» от `unique:users`, [RegisterRequest.php:65](../../../app/Http/Requests/RegisterRequest.php)) — обезсилва константното време на forgot-password и дава списък с цели за credential stuffing; същият ендпойнт може умишлено да задейства «burst» маркера и да **заглуши** всички админ известия за нови регистрации за час ([SendInvestorRegisteredAlert.php:150-160](../../../app/Listeners/SendInvestorRegisteredAlert.php)) — полезно на атакуващ, който регистрира mule акаунт; `/api/health/scheduler` публикува kill-switch състояния и брояч на провалени изплащания ([SchedulerHealthController.php:92-136](../../../app/Http/Controllers/Api/SchedulerHealthController.php)) — оперативна информация за всеки.
- **Усилие:** S

## SEC-07 — Лични данни: одитният лог пази пътища към документи за самоличност и имейл; няма retention
- **Тежест:** low · **Експлоатируемост:** достъп до `audit_logs` (админ/DB) · **Радиус:** GDPR
- **Статус:** VERIFIED
- **Къде:** `User` е `Auditable`; `submitKyc` пише `kyc_*_path` с `forceFill` ([ProfileController.php:231-236](../../../app/Http/Controllers/Api/ProfileController.php)) → `audit_logs.new_values` съдържа пътищата (списъкът за редакция не ги включва — [Auditable.php:44-48](../../../app/Traits/Auditable.php)); `email` също не се редактира, а `audit_logs` е неизменим и без retention; `AccountDeletionService` анонимизира потребителя, но не докосва `audit_logs` (правилно за одит, но пътищата към вече изтрити файлове и старият имейл остават завинаги); `Log::info('Account deletion requested', ['email' => …])` ([AccountDeletionService.php:107-111](../../../app/Services/AccountDeletionService.php)).
- **Бележки:** `logAudit` взема `getOriginal()`, който **декриптира** `encrypted` cast-овете — всяка криптирана колона извън фиксирания списък би попаднала в явен текст в неизменимия лог (днес списъкът покрива `Borrower` и IBAN-ите; LegalEntityProfile/BeneficialOwner не са Auditable, така че засега няма теч); KYC файловете се сервират с `response()->file()` без `Cache-Control: no-store` ([routes/web.php:68](../../../routes/web.php)) — браузърът на админа може да ги кешира на диск.
- **Поправка:** редакция на `kyc_*_path`, `email`, `bank_reference`, `eik`, `legal_name` в `logAudit`; `no-store` за документите; retention решение (CLAUDE.md отворено).
- **Усилие:** S

## SEC-08 — Имена на инвеститори в Telegram и в опашката
- **Тежест:** low · **Експлоатируемост:** достъп до Telegram канала / таблицата `jobs` · **Радиус:** конфиденциалност
- **Статус:** VERIFIED
- **Къде:** [LoanController.php:300-304](../../../app/Http/Controllers/Api/LoanController.php) — «{име} инвестира {сума} € в кредит #…» към Telegram; [UserResource.php:353-357](../../../app/Filament/Resources/UserResource.php) — «{админ} начисли бонус … на {име}»; `WithdrawalRequestedAdminNotification` носи `investorName` като сериализирано свойство в `jobs` (plaintext). Депозит/теглене алармите носят `user_id` + `iban_suffix` (приемливо).
- **Поправка:** `user_id`/инициали в Telegram; имената зад auth.
- **Усилие:** S

## SEC-09 — Само клиентски проверки
- **Тежест:** low–medium · **Статус:** VERIFIED
- **Къде:**
  - `expected_interest_rate` е `nullable` ([InvestRequest.php:34](../../../app/Http/Requests/InvestRequest.php); [LoanController.php:226-228](../../../app/Http/Controllers/Api/LoanController.php)) → директно API извикване без него прескача quote-vs-commit гарда (инвеститорът рискува сам себе си — медиум само защото договорът се сключва при клика).
  - Телефонният gate за заварени акаунти е само в SPA (документирано в CLAUDE.md).
  - `/profile/ibans` и `/deposit` не са зад `kyc`/`consent.current` ([routes/api.php:96-97, 117-119](../../../routes/api.php)) — непроверен потребител може да подготви IBAN-и и код; парите обаче не могат да излязат без KYC (теглене е зад `kyc`).
  - Всичко останало (мин/макс суми, теглим баланс, такса, статус) се повторно проверява на сървъра — ✅.
  - Нормализация на IBAN само в SPA: `ValidIban` нормализира вътрешно за проверката, но записва суровия вход — при директно API извикване IBAN с интервали/малки букви се съхранява така ([ProfileController.php:343-346](../../../app/Http/Controllers/Api/ProfileController.php); [WithdrawalController.php:35](../../../app/Http/Controllers/Api/WithdrawalController.php)); рискът е при ръчното преписване за превода (SEC-11).
  - «Сумата трябва да надвишава таксата» е само в SPA ([WithdrawalPage.vue:46, 109](../../../resources/js/views/WithdrawalPage.vue)); сървърът приема заявката, резервира парите и я отхвърля едва при одобрение с `ValidationException`, която Filament действието не улавя ([WithdrawalRequestResource.php:60](../../../app/Filament/Resources/WithdrawalRequestResource.php)) — парите стоят резервирани до ръчен отказ.
- **Поправка:** `expected_interest_rate` задължителен за офертни инвестиции; `/profile/ibans` зад `kyc`; нормализация на IBAN в FormRequest; проверка «нето > 0» в `createRequest`.
- **Усилие:** S

## SEC-10 — Кредитиране на депозит без съпоставяне с банково извлечение
- **Тежест:** high · **Експлоатируемост:** грешка или злоумисъл на един админ · **Радиус:** сумата в полето
- **Статус:** VERIFIED
- **Къде:** [DepositRequestResource.php:198-206, 259-265](../../../app/Filament/Resources/DepositRequestResource.php) — `amount` и `bank_reference` са ръчен вход; [DepositService.php:117-122, 147-157](../../../app/Services/DepositService.php) — единствената проверка на референцията е уникалност.
- **Какво се чупи в пари:** виж SEC-02 и PAY-19: няма нищо, което да доказва, че зад кредитираните 5 000 € стои реален превод от 5 000 €.
- **Поправка:** backlog O1 (импорт на извлечение, съпоставка), четириочен принцип над праг.
- **Усилие:** M

## SEC-11 — Пълният IBAN на тегленето не се вижда никъде в приложението: реалният превод изисква достъп до базата
- **Тежест:** high (операционно и за сигурността) · **Експлоатируемост:** — · **Радиус:** всяко теглене
- **Статус:** VERIFIED
- **Къде:** [WithdrawalRequestResource.php:38](../../../app/Filament/Resources/WithdrawalRequestResource.php) показва `maskedIban()`; grep по `app/Filament`, `app/Notifications`, `app/Mail`, `resources/views` за пълния `iban` → няма нито едно място, което да го рендерира; колоната е `encrypted` ([WithdrawalRequest.php:30](../../../app/Models/WithdrawalRequest.php)).
- **Какво се чупи:** за да пусне превода, човекът трябва да вземе IBAN-а извън приложението — `tinker`, DB конзола, или да го поиска от инвеститора. Това означава (а) рутинен достъп на оператора до продукционната база с ключа за декриптиране (без одит кой какво е прочел), (б) риск от грешно преписан IBAN, (в) социално инженерство («пратете ми IBAN-а по имейл»). Не е бъг в кода, а липсваща операционна функция, която **принуждава** небезопасен процес.
- **Поправка:** действие «Покажи IBAN за превод» само за одобрени заявки, с повторно въвеждане на паролата на админа, запис в `audit_logs` кой го е разкрил и кога, и копиране в clipboard без показване в списъка.
- **Усилие:** S

## SEC-12 — Лични данни към Telegram и в необработените изключения
- **Тежест:** medium · **Експлоатируемост:** достъп до Telegram канала / лога · **Радиус:** GDPR, профилиране на инвеститори
- **Статус:** VERIFIED
- **Къде:** [SendInvestorRegisteredAlert.php:161-169](../../../app/Listeners/SendInvestorRegisteredAlert.php) — име + имейл на всеки нов инвеститор към Telegram; [LoanController.php:300-304](../../../app/Http/Controllers/Api/LoanController.php), [UserResource.php:353-357](../../../app/Filament/Resources/UserResource.php) — имена; [bootstrap/app.php:161-169](../../../bootstrap/app.php) — `get_class($e).': '.$e->getMessage()` (до 800 знака) към Telegram за всяко 5xx: `QueryException` съобщенията съдържат SQL с подставени bindings (имейли, телефони, суми, шифротекст).
- **Поправка:** `user_id`/инициали вместо име+имейл; за изключенията — само клас + `file:line`, без `getMessage()` за `QueryException`.
- **Усилие:** S

## SEC-13 — Изтриването по GDPR не докосва фирмения профил и действителните собственици
- **Тежест:** medium · **Експлоатируемост:** — · **Радиус:** GDPR (чл. 17)
- **Статус:** VERIFIED
- **Къде:** [AccountDeletionService.php:129-150](../../../app/Services/AccountDeletionService.php) — анонимизира `users`, трие `saved_ibans`, `consent_records`, `notifications`, `push_subscriptions`, `wallets`; **не** докосва `legal_entity_profiles` (ЕИК/ДДС/ЕГН на представител, адрес) и `beneficial_owners` (UBO, PEP флаг). Самото анонимизиращо `save()` минава през `Auditable::updated` ([Auditable.php:24-32](../../../app/Traits/Auditable.php)), което записва **старите** `name`/`email` в неизменимия `audit_logs` — т.е. «изтриването» пише личните данни на още едно място, откъдето вече нищо не може да ги махне; `laravel.log` получава имейла и IP-то ([AccountDeletionService.php:107-111](../../../app/Services/AccountDeletionService.php)).
- **Поправка:** анонимизация на двете таблици в същата транзакция; `email`/`name` в списъка за редакция на `logAudit` (или `saveQuietly` + изричен одитен запис «anonymized»); retention решение за логовете.
- **Усилие:** S

## SEC-16 — Самообслужваното изтриване унищожава KYC документи и съгласия веднага — без AML задържане
- **Тежест:** medium (регулаторно) · **Статус:** VERIFIED за кода; SUSPECTED за правното задължение (ЗМИП — срок за съхранение на документите за комплексна проверка; за юрист)
- **Къде:** [AccountDeletionService.php:144, 155-160](../../../app/Services/AccountDeletionService.php) — `consent_records` се трият, KYC файловете се изтриват от диска след commit; няма retention hold, няма проверка «имало ли е транзакции».
- **Какво се чупи:** клиент, който е внасял/теглил пари, може да изтрие сам доказателствата за самоличността си и съгласията си; при последваща AML проверка платформата не може да покаже кого е верифицирала. Обратното на PAY-23/SEC-13: тук се трие прекалено много.
- **Поправка:** «мек» режим: файловете се преместват в задържан контейнер с часовник (X години след последната транзакция), достъпен само за compliance; `consent_records` се пазят анонимизирано (тип, версия, дата, IP).
- **Усилие:** S

## SEC-17 — Бекъпът покрива само базата; KYC директорията и ключът за криптиране не са част от възстановяването
- **Тежест:** medium · **Статус:** VERIFIED (скриптът) · SUSPECTED (дали кронът изобщо е инсталиран — CLAUDE.md го поставя под въпрос)
- **Къде:** [scripts/ops/local-backup.sh:5, 100](../../../scripts/ops/local-backup.sh) — само `mysqldump → gzip → openssl`; никакво споменаване на `storage/app/private`; [docs/runbooks/backup-restore-bg.md](../../../docs/runbooks/backup-restore-bg.md) не споменава `APP_KEY` (grep), а всички IBAN-и, ЕГН/ЕИК, договорни снапшоти са `encrypted` с него.
- **Какво се чупи:** при загуба на диска всички документи за самоличност изчезват (AML/ECSP доказателства), а при възстановяване на нов хост без стария `APP_KEY` всички криптирани колони са нечетими — включително IBAN-ите на чакащите тегления. Уточнение от панела: самата **ротация** на ключа не чупи `encrypted` cast-овете (Laravel поддържа `APP_PREVIOUS_KEYS`), но няма процедура, няма команда за пре-криптиране и няма custody на ключа. По доклада на критика (SUSPECTED — не отворих редовете) архивите стоят на същия хост като базата, а паролата за криптирането им е в същия `.env` като DB паролата.
- **Поправка:** архив на `kyc-documents/` в същия pipeline; `APP_KEY` в отделен, документиран secret store; off-site копие на архивите; restore drill (backlog R6/R7).
- **Усилие:** S

## SEC-18 — Telegram bot токенът може да попадне в лога при мрежова грешка
- **Тежест:** low · **Статус:** SUSPECTED (зависи от текста на изключението на HTTP клиента; URL-ът съдържа токена)
- **Къде:** [TelegramService.php:79-82](../../../app/Services/TelegramService.php) — `Log::warning('Telegram exception', ['error' => $e->getMessage()])`; `ConnectionException` съобщенията на Guzzle/cURL включват заявения URL (`https://api.telegram.org/bot<token>/sendMessage`).
- **Поправка:** маскиране на токена в съобщението (`str_replace($token, '***', …)`) преди лог.
- **Усилие:** S

## SEC-19 — Линкът за частен кредит е вечен и неотменим
- **Тежест:** low · **Статус:** VERIFIED
- **Къде:** [Loan.php:565-579](../../../app/Models/Loan.php) — токенът се генерира веднъж, `grantAccessTo` е `firstOrCreate` завинаги; [LoanResource.php:150-169](../../../app/Filament/Resources/LoanResource.php) — само «покажи линк», без ротация/отмяна; `loan_grants` няма админ UI.
- **Какво се чупи:** препратен линк дава траен достъп (и възможност за инвестиция) на всеки верифициран акаунт; админът не вижда кой е получил достъп и не може да го спре.
- **Поправка:** «Нов линк» (ротация) + списък и отмяна на grants + изтичане.
- **Усилие:** S

## SEC-20 — `Secure` флагът на сесийното cookie зависи от env без стойност по подразбиране
- **Тежест:** low · **Статус:** SUSPECTED (`.env` умишлено не е отварян)
- **Къде:** [config/session.php:172](../../../config/session.php) — `'secure' => env('SESSION_SECURE_COOKIE')` (null → не е Secure); `http_only` и `same_site=lax` са с подразбиране.
- **Поправка:** `SESSION_SECURE_COOKIE=true` в прод (и `.env.example`), или `env(..., true)`.
- **Усилие:** S

---

## Находки от кръга «критици за пълнота» (потвърдени лично)

## SEC-21 — Верифицираното име може да се сменя свободно след одобрен KYC
- **Тежест:** medium · **Експлоатируемост:** сесия/парола · **Радиус:** улеснява SEC-01 (името се напасва към титуляра на IBAN-а на атакуващия)
- **Статус:** VERIFIED
- **Къде:** [ProfileController.php:35-43](../../../app/Http/Controllers/Api/ProfileController.php) — `update($request->only('name','phone'))`; [UpdateProfileRequest.php:25](../../../app/Http/Requests/UpdateProfileRequest.php) — само формат; маршрутът е зад `investor`, не зад `kyc` ([routes/api.php:109](../../../routes/api.php)). Обратният принцип е приложен за `legal_name/eik` (не се редактират — [UpdateCompanyProfileRequest.php:52-61](../../../app/Http/Requests/UpdateCompanyProfileRequest.php)).
- **Какво се чупи:** одобряващият, имейлите към админите и договорните снапшоти четат **текущото** име; след KYC то е «истина за самоличност» и не бива да се пипа без повторна проверка.
- **Поправка:** `name` заключено при `kyc_status = approved`; смяна само през админ действие с одит.
- **Усилие:** S

## SEC-22 — Самообслужваното изтриване е «довършващ ход» при превзет акаунт
- **Тежест:** medium · **Експлоатируемост:** парола · **Радиус:** унищожаване на доказателства + невъзможност за възстановяване
- **Статус:** VERIFIED
- **Къде:** [AccountDeletionService.php:27-33](../../../app/Services/AccountDeletionService.php) — само парола; :129-140 — имейлът се презаписва с `deleted_{id}@…`; [ProfileController.php:367-383](../../../app/Http/Controllers/Api/ProfileController.php) — без известие към собственика, без потвърждение по имейл, без период на изчакване.
- **Какво се чупи:** атакуващият с парола (SEC-01) може след тегленето да изтрие акаунта; собственикът губи достъп до имейла на записа и не може да докаже, че акаунтът е бил негов; KYC файловете и съгласията изчезват (SEC-16).
- **Поправка:** потвърждение по имейл + 7-дневен «мек» период + известие «не съм аз».
- **Усилие:** S

## SEC-23 — Име на инвеститор, рендерирано неескейпнато в Filament toast
- **Тежест:** low · **Експлоатируемост:** инвеститор задава име с HTML · **Радиус:** «жив линк» в админ панела (фишинг към админа)
- **Статус:** VERIFIED
- **Къде:** [DepositRequestResource.php:269](../../../app/Filament/Resources/DepositRequestResource.php) — `"Сметката на {$deposit->user->name} е захранена…"` без `e()`; кодът познава опасността и ескейпва на три други места ([WithdrawalController.php:81](../../../app/Http/Controllers/Api/WithdrawalController.php), [UserResource.php:365](../../../app/Filament/Resources/UserResource.php), ProfileController.php:287). Filament санира `<script>`, но пропуска `<a href>`.
- **Поправка:** `e()` навсякъде, където име влиза в известие.
- **Усилие:** S

## SEC-24 — Юридически лица се допускат до пари без представител/ДСС/произход на средствата
- **Тежест:** high (регулаторно — ЗМИП) · **Статус:** VERIFIED за кода; правната квалификация — за юрист
- **Къде:** [AuthController.php:50-55](../../../app/Http/Controllers/Api/AuthController.php) — при регистрация само `legal_name` + `eik`; [UpdateCompanyProfileRequest.php:52-61](../../../app/Http/Requests/UpdateCompanyProfileRequest.php) — само адрес/контакти; grep за писачи на `beneficial_owners`, `representative_egn`, `is_pep`, `source_of_funds` в `app/` → **нито един**. Таблиците `legal_entity_profiles`/`beneficial_owners` съществуват (модели с криптирани полета), но нищо не ги попълва.
- **Какво се чупи:** фирмен акаунт минава KYC (документи на човека, който качва), депозира, инвестира и тегли без идентифициран действителен собственик, без ПЕП проверка и без декларация за произход на средствата — коментарът «post-registration KYC workflow» в кода описва процес, който не съществува.
- **Поправка:** UBO/представител/ПЕП/произход като задължителна стъпка преди `kyc_status = approved` за `legal_entity`; Filament ресурс за преглед.
- **Усилие:** M

## SEC-25 — Достъпът на персонала до документи за самоличност и договори не се логва
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** [routes/web.php:46-69, 74-87](../../../routes/web.php) и [InvestmentContractController.php:29-44](../../../app/Http/Controllers/Api/InvestmentContractController.php) връщат файла без запис; `Auditable` покрива само create/update/delete ([Auditable.php:18-37](../../../app/Traits/Auditable.php)).
- **Какво се чупи:** GDPR/AML одитор пита «кой е гледал личната карта на X» — няма отговор.
- **Поправка:** `AuditLog` ред `viewed` при всяко сервиране (user, model, ip).
- **Усилие:** S

## SEC-26 — KYC решението не записва какво е верифицирано и не прави скрининг
- **Тежест:** medium (регулаторно) · **Статус:** VERIFIED
- **Къде:** [UserResource.php:387-398](../../../app/Filament/Resources/UserResource.php) — одобрението е само `kyc_status = approved`; `users` няма дата на раждане, гражданство, номер/валидност на документ ([User.php:29-38](../../../app/Models/User.php)); нищо не проверява санкционни/ПЕП списъци.
- **Какво се чупи:** платформата не може да покаже *какво* е проверила, не може да засече изтекъл документ и не може да отговори на регулаторен запрос по документ.
- **Поправка:** структурни KYC полета + дата на валидност + скрининг (ръчен чеклист като минимум).
- **Усилие:** M

## SEC-27 — Няма мониторинг на транзакциите (AML)
- **Тежест:** medium (регулаторно) · **Статус:** VERIFIED
- **Къде:** [DepositRequestResource.php:198-206](../../../app/Filament/Resources/DepositRequestResource.php) — единствената проверка е 1 ≤ сума ≤ max; grep за прагове/необичайни модели/ДАНС/структуриране → само алармата за неуспешни логини.
- **Какво се чупи:** няма кумулативни прагове, декларация над законовия праг, флаг за необичаен модел или регистър на случаи.
- **Поправка:** правила по праг/скорост + регистър «за преглед» (може да е Filament ресурс).
- **Усилие:** M

## SEC-28 — Приетият текст на условията не може да се възпроизведе; живите условия съдържат непопълнени полета
- **Тежест:** medium · **Статус:** VERIFIED
- **Къде:** `ConsentRecord` пази тип/версия/IP/UA/време ([AuthController.php:56-75](../../../app/Http/Controllers/Api/AuthController.php)); текстът живее само във Vue компоненти без сървърен архив/хеш; [TermsPage.vue:20-41](../../../resources/js/views/legal/TermsPage.vue) и [PrivacyPage.vue:20-37](../../../resources/js/views/legal/PrivacyPage.vue) съдържат `[АДРЕС — ПОПЪЛВА СЕ]`, `[ИМЕ НА УПРАВИТЕЛ — ПОПЪЛВА СЕ]`, `[ОФИЦИАЛЕН ИМЕЙЛ — ПОПЪЛВА СЕ]`.
- **Какво се чупи:** «инвеститорът прие v1.2» не може да бъде доказано с текст; и текстът, който днес се приема, идентифицира оператора с placeholder-и.
- **Поправка:** сървърен архив на всяка версия (markdown/PDF + sha256) и попълване на реквизитите преди пускане.
- **Усилие:** S

## SEC-29 — Оригинаторът няма правна идентичност в системата
- **Тежест:** medium (регулаторно) · **Статус:** VERIFIED
- **Къде:** [Originator.php:15-22](../../../app/Models/Originator.php) — `name, description, website, buyback*, logo_path`; без ЕИК, лиценз/регистрационен номер, договор за цесия/рамково споразумение, контакт; `Originator` не е `Auditable` (buyback покритието се сменя без следа).
- **Какво се чупи:** страната, чиято лицензия легитимира кредитите и чието изкупуване е единствената защита на инвеститорите, е запис без идентификация; промяна на `buyback_coverage` от `plus_interest` към `principal_only` не оставя кой/кога.
- **Поправка:** ЕИК/лиценз/договор полета + `Auditable`.
- **Усилие:** S

**Свързани, без отделни ID:** няма инвесторски извлечения/експорт (backlog R4), няма регистър на жалби (R10), едната роля «admin» покрива и одитор/счетоводител (SEC-03, R8).

## SEC-14 — Парола-оракули без ограничение на скоростта в автентикирана сесия
- **Тежест:** low · **Експлоатируемост:** открадната сесия (cookie) без парола · **Радиус:** ескалация от сесия до пълен контрол (смяна на парола → добавяне на IBAN → теглене)
- **Статус:** VERIFIED
- **Къде:** [routes/api.php:111, 120](../../../routes/api.php) — `PUT /profile/password` (`current_password` правило) и `POST /profile/delete` (`Hash::check`) са в групата `investor` без `throttle`.
- **Поправка:** `throttle:5,1` + `RateLimiter` по потребител + известие при неуспешни опити.
- **Усилие:** S

## SEC-15 — Регулаторна готовност (ECSP): липсват защитите за непрофесионални инвеститори
- **Тежест:** medium (регулаторно) · **Статус:** VERIFIED за липсата в кода; правната квалификация е за юрист
- **Къде:** [InvestmentService.php:183-213](../../../app/Services/InvestmentService.php) — единствените правила са минимум 50 €, статус и капацитет; няма период за размисъл (ECSP чл. 22), тест за знания/симулация на загуба (чл. 21), лимити/предупреждения за непрофесионални инвеститори, нито поле за категоризация; click-wrap е неотменим ([InvestmentContract.php](../../../app/Models/InvestmentContract.php)).
- **Структурен въпрос за юрист (SUSPECTED):** договорът, който платформата сключва при всяка инвестиция ([resources/views/contracts/investment-v1.blade.php:144-172, 210-218](../../../resources/views/contracts/investment-v1.blade.php)), прави **ВАМА АСЕТ ЕООД заемател** на парите на инвеститора с целево предназначение — финансиране на „НАЗАЕМ.БГ“ ООД — и предвижда цесия на вземания само като *изпълнение вместо плащане* по чл. 3. Това е заем към платформата, а не прехвърляне на вземания към инвеститора, както е описан моделът в заданието на одита. Кодът е коректен спрямо документа; дали документът е коректен спрямо ECSP/ЗКИ/ЗПУПС (набиране на възстановими средства от публиката) — не е въпрос на код и трябва да се реши преди първото евро.
- **Поправка:** backlog R1–R9; правно становище върху структурата на договора.
- **Усилие:** L

---

## Матрица тежест / експлоатируемост / радиус

| ID | Тежест | Експлоатируемост | Радиус в пари |
|---|---|---|---|
| SEC-01 | critical | една открадната парола на инвеститор | целият теглим баланс на жертвата |
| SEC-02 | critical | една открадната парола на админ / insider | неограничен |
| SEC-03 | high | един админ акаунт | според действието |
| SEC-10 | high | една грешка/злоумисъл на админ | сумата в полето |
| SEC-11 | high | — (принуден небезопасен процес) | всяко теглене |
| SEC-04 | medium | последствие при инцидент | време до ръчна DB намеса |
| SEC-06 | medium | без условия | оперативен; скорост при ATO |
| SEC-12 | medium | достъп до Telegram/лог | GDPR |
| SEC-13 | medium | — | GDPR |
| SEC-15 | medium (рег.) | — | лиценз |
| SEC-16 | medium (рег.) | — | AML доказателства |
| SEC-17 | medium | загуба на диск/хост | всички криптирани данни |
| SEC-24 | high (рег.) | — | лиценз / ЗМИП |
| SEC-21 | medium | парола | улеснява SEC-01 |
| SEC-22 | medium | парола | доказателства, възстановяване |
| SEC-25 | medium | — | GDPR/AML одит |
| SEC-26 | medium (рег.) | — | KYC доказуемост |
| SEC-27 | medium (рег.) | — | AML |
| SEC-28 | medium | — | договорна доказуемост |
| SEC-29 | medium (рег.) | — | защита на инвеститорите |
| SEC-23 | low | име с HTML | фишинг към админ |
| SEC-14 | low | открадната сесия | ескалация |
| SEC-18 | low | достъп до лога | компрометиран бот |
| SEC-19 | low | препратен линк | достъп до частен кредит |
| SEC-20 | low (SUSPECTED) | MITM без TLS | сесия |
| SEC-09 | low–medium | директно API | самонараняване / подготовка |
| SEC-05 | low | препратен имейл | заглушаване на аларма |
| SEC-07 | low | достъп до audit_logs | GDPR |
| SEC-08 | low | достъп до Telegram/jobs | конфиденциалност |
