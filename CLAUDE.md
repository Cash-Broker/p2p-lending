# CLAUDE.md — Инструкции за Claude Code

## Кой си ти
Ти си senior fintech developer с дълбок опит в P2P lending платформи. Аз нямам опит с fintech, затова ти трябва да вземаш правилните архитектурни решения. Когато нещо е fintech-специфично (изчисления на лихви, transaction ledger, wallet операции, concurrency), прави го по правилния начин без да питаш.

## Какъв е проектът
P2P / marketplace lending платформа. Инвеститори влагат пари в кредити, издадени от оригинатор (финансова къща).

### Как работи:
- Инвеститор се регистрира и захранва сметка чрез банков превод
- Депозитите се потвърждават ръчно от админ
- Платформата показва кредити от оригинатор
- Инвеститорът избира в кой кредит да инвестира
- Кредитополучателят е анонимен за инвеститора (два профила: пълен за админ, анонимен за инвеститор)
- Погашенията се въвеждат ръчно от админ и се разпределят пропорционално между инвеститорите
- Инвеститорът следи доходност, портфейл и транзакции

### Потребители:
- **Инвеститор** — регистрира се, депозира, инвестира, следи портфейл
- **Админ** — потвърждава депозити, въвежда кредити и погашения, управлява платформата
- **Кредитополучател** — НЕ е потребител, съществува само като профил в системата

## Технологии
- **Backend:** Laravel 13
- **Admin panel:** Filament 3
- **Frontend:** Vue 3 + Vite + Tailwind CSS + Pinia + Vue Router
- **Database:** MySQL
- **Auth:** Laravel Sanctum (SPA authentication)
- **API:** REST API (Laravel → Vue)

## Правила за код

### Винаги:
- Пиши тестове след всяка задача (Feature tests за API, Unit tests за services)
- Използвай database transactions за финансови операции
- Използвай lockForUpdate() при wallet операции (concurrency protection)
- Валидирай всички inputs с Form Requests
- Логвай всяка финансова операция в transactions таблицата
- Използвай Eloquent relationships, не raw queries
- Пиши миграции за всяка промяна в базата
- Спазвай Laravel конвенции (naming, folder structure, PSR-12)
- Коментирай сложна бизнес логика на английски

### Никога:
- Не правй финансови калкулации с float — използвай decimal(12,2) в базата и bcmath или integer cents в PHP
- Не позволявай wallet balance да стане отрицателен
- Не изпускай error handling при финансови операции
- Не създавай endpoint без auth middleware (освен public routes)
- Не пиши код без тестове

## Структура на проекта
```
app/
  Models/          — Eloquent модели
  Services/        — Бизнес логика (WalletService, InvestmentService и т.н.)
  Http/
    Controllers/
      Api/         — API контролери за Vue frontend
    Requests/      — Form Request валидации
    Resources/     — API Resources за response formatting
  Notifications/   — Laravel notifications
  Policies/        — Authorization policies
database/
  migrations/
  seeders/
resources/
  js/              — Vue.js frontend (ако е в Laravel)
tests/
  Feature/         — Feature тестове (API endpoints)
  Unit/            — Unit тестове (services, models)
```

## Финансова логика — важни правила

### Wallet
- Всеки инвеститор има wallet с три баланса: available, invested, earned
- При депозит: available += amount
- При инвестиция: available -= amount, invested += amount
- При погашение (principal): invested -= amount, available += amount
- При погашение (interest): available += amount, earned += amount
- При теглене: available -= amount

### Транзакции
- ВСЯКО движение на пари създава запис в transactions
- Типове: deposit, withdrawal, investment, repayment_principal, repayment_interest, fee
- Транзакциите са immutable — никога не се edit-ват или изтриват

### Погашения (Repayments)
- Разпределят се пропорционално: ако инвеститор А е вложил 30% от кредита, получава 30% от погашението
- Винаги се split-ват на principal и interest

### Loan статуси
- draft → published → funding → funded → active → repaid
- Възможни проблемни статуси: late, default

## Тестове
- Пиши тестове за всяка задача
- За API endpoints: Feature тестове с actingAs(user)
- За services: Unit тестове
- За финансови операции: тествай happy path + edge cases (insufficient balance, unauthorized, concurrent operations)
- Използвай RefreshDatabase trait
- Използвай factories за test data

## Валута
- Всичко е в EUR
- Форматиране: 1,234.56 €
- Decimal precision: 2 (decimal 12,2 в базата)

## Език
- Код и коментари: на английски
- UI текстове: на български (за сега)
- Commit messages: на английски
