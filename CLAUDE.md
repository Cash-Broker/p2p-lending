# CLAUDE.md — P2P Lending Platform Instructions

## Role
You are a senior fintech architect and developer with deep experience in P2P lending platforms (Mintos, Bondora, PeerBerry level). This is a REAL financial platform handling REAL money. Every decision must be production-grade. No shortcuts, no "we'll fix later", no prototype-quality code.

## Project Overview
P2P / marketplace lending platform. Investors fund loans originated by licensed financial institutions (originators). The platform is the intermediary.

### Flow:
- Investor registers → KYC verification → deposits via bank transfer (admin confirms manually)
- Platform displays loans from originators
- Investor selects loans and invests
- Borrower is anonymous to investor (full profile for admin, anonymized for investor)
- Repayments entered manually by admin → distributed proportionally to investors
- Investor tracks returns, portfolio, transactions

### Users:
- **Investor** — registers, deposits, invests, tracks portfolio
- **Admin** — manages loans, approves deposits/withdrawals, enters repayments (Filament)
- **Borrower** — NOT a user, exists only as data (full + anonymized profile)

## Tech Stack
- **Backend:** Laravel 13
- **Admin:** Filament 3
- **Frontend:** Vue 3 + Vite + Tailwind CSS + Pinia + Vue Router
- **Database:** MySQL
- **Auth:** Laravel Sanctum (SPA)
- **API:** REST (Laravel → Vue)

## Code Standards — MANDATORY

### Architecture:
- Separation of concerns: Controller → Service → Model. NEVER put business logic in controllers
- Services for ALL business logic (WalletService, InvestmentService, RepaymentService, etc.)
- Form Requests for EVERY endpoint with input
- API Resources for EVERY response — never return raw models
- Policies for authorization
- Events + Listeners for side effects (notifications, logging)

### Production Quality:
- Write code as if it deploys to production TODAY
- Error handling everywhere — never leave empty try/catch
- Log errors with context: Log::error('Investment failed', ['user_id' => $id, 'loan_id' => $loanId, 'amount' => $amount])
- Consistent API responses: { data, message, errors } with correct HTTP status codes (200, 201, 400, 401, 403, 404, 422, 500)
- N+1 query prevention: ALWAYS eager load relationships
- Pagination on ALL list endpoints
- Database indexes on foreign keys and frequently queried columns
- Rate limiting on sensitive endpoints (login, register, invest)
- Input sanitization and validation on every endpoint

### Financial Logic — CRITICAL:
- NEVER use float for money — decimal(12,2) in DB, bcmath in PHP
- NEVER allow negative wallet balance
- EVERY money movement creates a transaction record — NO EXCEPTIONS
- Transactions are IMMUTABLE — never update, never delete
- Use DB::transaction() + lockForUpdate() for ALL wallet operations
- Wallet balances: available, reserved, invested, earned (4 buckets)
  - Deposit (admin approve): available += amount
  - Invest: available -= amount, invested += amount
  - Withdrawal request: available -= amount, reserved += amount (no transaction yet)
  - Withdrawal approve: reserved -= amount (transaction record created)
  - Withdrawal reject: reserved -= amount, available += amount (release)
  - Repayment (principal): invested -= amount, available += amount
  - Repayment (interest): available += amount, earned += amount
  - DB CHECK constraints enforce all four >= 0
- Repayments distribute proportionally: investor's share = (investor_amount / total_funded) * repayment_amount
- Always split repayments into principal and interest

### Loan Lifecycle:
- State machine in `Loan::ALLOWED_TRANSITIONS`:
  - draft → published → funding → funded → active → repaid
  - active ↔ late, late → default → repaid (recovery + escalation)
- Late automation: see "Phase F1 — Late/Default Automation" below.
- Default: NO automation in v1. Admin must manually transition late → default.
  Buyback flag exists on `originators` but no honour-flow yet (Phase F2).
- Amortization (annuity): `AmortizationService` generates schedule on
  funded → active. Investor rate = `loans.interest_rate`; borrower rate
  = `loans.interest_rate_annual` (originator spread, metadata only).

### Security:
- Auth middleware on ALL non-public routes
- Encrypt sensitive data (personal_id / EGN)
- CSRF protection
- XSS prevention
- SQL injection prevention (use Eloquent, never raw user input in queries)
- Sensitive actions require KYC approval check
- Hide full borrower data from investors — ALWAYS use anonymized profile

### Testing — MANDATORY:
- Write tests AFTER every task
- Feature tests for API endpoints (actingAs user)
- Unit tests for services
- Test happy path + ALL edge cases for financial operations:
  - Insufficient balance
  - Unauthorized access
  - Invalid KYC status
  - Concurrent operations (race conditions)
  - Overfunding (invest more than loan needs)
  - Duplicate operations
- Use RefreshDatabase trait
- Use factories for test data

### DRY & Clean Code:
- If something repeats 2+ times — extract it
- Meaningful variable and method names
- Comment complex business logic
- PSR-12 coding style
- Laravel naming conventions

## Project Structure
```
app/
  Models/           — Eloquent models with relationships
  Services/         — Business logic (WalletService, InvestmentService, etc.)
  Http/
    Controllers/
      Api/          — API controllers (thin — delegate to services)
    Requests/       — Form Request validations
    Resources/      — API Resources for response formatting
  Notifications/    — Laravel notification classes
  Policies/         — Authorization policies
  Events/           — Domain events
  Listeners/        — Event listeners
database/
  migrations/
  seeders/
  factories/
resources/
  js/               — Vue.js frontend
    views/          — Page components
    components/     — Reusable components
    layouts/        — Layout components (AppLayout, etc.)
    stores/         — Pinia stores
    api/            — Axios API calls
    composables/    — Vue composables
    router/         — Vue Router config
tests/
  Feature/          — Feature tests (API, integration)
  Unit/             — Unit tests (services, models)
```

## Phase F1 — Late/Default Automation

### Tables (F1 migrations)
- `platform_settings` — key/value configuration store with `type` column
  for round-tripping (int/float/string/bool/json). Read via
  `PlatformSetting::get($key, $default)` — returns the typed value.
  Auditable. Currently seeded:
    - `grace_period_days` = 10 — see [DECISIONS.md](DECISIONS.md).
      DB CHECK enforces `0..30` range (defense in depth).
    - `late_check_enabled` = true — kill switch for the cron.
- `platform_metrics` — observed state written by automated jobs.
  Read via `PlatformMetric::record($key, $value)` /
  `PlatformMetric::read($key)` / `::measuredAt($key)`. NOT auditable.
- `loan_events` — append-only lifecycle log per loan. Triple-layer
  immutability:
    1. App layer: `LoanEvent::update()` and `delete()` throw
       `LogicException` (friendly error before MySQL fires).
    2. DB triggers `prevent_loan_event_update` / `_delete` —
       `SIGNAL SQLSTATE '45000'` blocks raw SQL too.
    3. CHECK constraints — event_type enum, triggered_by enum,
       triggered_by_user_id consistency, status_pair (both null OR
       both set + different — forbids self-transitions).
  Event-type enum is PRE-EXPANDED for F2/F3/F4 (buyback_*,
  early_repayment_*, fee_applied) so future phases don't migrate
  the column.
- `loans.last_late_check_at`, `loans.became_late_at` — set/cleared
  by the late-detection command.
- `amortization_schedules.became_late_at`, `days_late` — `days_late`
  is a SNAPSHOT updated daily (not computed on read); CHECK enforces >= 0.

### Late detection mechanism
Command `loans:process-late` (see "Commands" below) runs daily 03:30
via `bootstrap/app.php` schedule, after `ledger:reconcile` (03:00).

Pipeline per run:
1. `LateDetectionService::detectNewlyLateSchedules($today)` — scans
   loans in `[active, late]` for schedules with
   `due_date <= today - grace_period_days` AND `status='pending'`,
   marks them `status='late'`, stamps `became_late_at`, `days_late`.
2. `LateDetectionService::refreshDaysLateSnapshots($today)` — updates
   `days_late` on already-late schedules (idempotent: no-op writes
   skipped).
3. `LoanStatusUpdaterService::transitionLoansAfterLateCheck()`:
    - active loans with any `status='late'` schedule → transition
      to `late`, write `went_late` event with metadata
      `{late_schedule_count, days_late_at_transition}`.
    - late loans evaluated for recovery (rule R1 below).
4. For each newly-late loan: `LoanWentLateNotification` queued for
   every distinct investor (sum of their multiple positions, if any).
5. Metrics written to `platform_metrics`.

### Recovery rule R1 (late → active OR late → repaid)
ALL three conditions must hold:
1. **History guard:** loan has at least one schedule with
   `became_late_at IS NOT NULL` (loan was actually marked late by
   our automation, not admin-set without history).
2. **No current late items:** no schedule has `status='late'`.
3. **Past-late items truly paid:** for every schedule with
   `became_late_at IS NOT NULL`, `paid_at IS NOT NULL` AND
   `paid_at >= became_late_at` (paid AFTER becoming late, not a
   data-fix backfill).

**Tiebreaker:** if `paid_count == total_schedule_count`, transition
is `late → repaid`; otherwise `late → active`.

`recovered_from_late` event metadata: `{previous_became_late_at,
paid_schedule_count, total_schedule_count, transitioned_to}`.

### STATUS_DEFAULT — manual handling (known F1 limitation)
- F1 does NOT auto-transition `late → default`. State machine permits it.
- F1 does NOT auto-mark `amortization_schedules.status='default'`.
- `LoanStatusUpdaterService::maybeRecoverLoan` SAFEGUARD: if any
  schedule has `status='default'`, recovery is SKIPPED + warning logged
  + loan id surfaced in `recovery_skipped_default` bucket /
  `last_late_check_recovery_skipped_default` metric.
- F2 hook point: future buyback automation should consume
  `recovery_skipped_default` ids and transition loans to
  `default`/buyback flow. The `loan_events` enum already includes
  `went_default`, `buyback_triggered`, `buyback_completed` so no
  schema migration is needed.

### Notification dedupe
`LoanWentLateNotification::via()` returns `[]` if a previous
notification exists for the same `(user, loan, became_late_at)` —
"late period" identified by the `became_late_at` snapshot. A loan
that recovers and goes late again gets a NEW timestamp = NEW
notification. Degenerate null `became_late_at` falls back to a
24-hour cooldown.

**Contract guard:** `LoanWentLateNotification::toArray()` MUST keep
`became_late_at` as ISO-8601 string — the dedupe query depends on
exact format match. Test
`tests/Feature/Notifications/LoanWentLateNotificationTest::test_toarray_includes_became_late_at_as_iso_string_for_rate_limit_contract`
pins this.

### Commands

```sh
# Daily run (cron — see README "Operations")
php artisan loans:process-late

# Support flags:
--dry-run      # TRULY read-only — wraps everything in DB::beginTransaction +
               # rollBack at end. No writes anywhere (schedules, loans,
               # loan_events, metrics, notifications). Logs "would notify"
               # lines per investor.
--loan=ID      # Process only this loan id. Combine with --dry-run for
               # safe production debug of a single ticket.
--detail       # Per-loan / per-schedule progress on stdout. (Renamed from
               # --verbose because Laravel reserves -v / --verbose for
               # framework log verbosity.)
--force        # Bypass platform_settings.late_check_enabled. Required only
               # when ops have paused automation (rare — typically during
               # data fix-ups).

# Examples:
php artisan loans:process-late --dry-run --detail --loan=42
php artisan loans:process-late --force --detail
```

Cache lock `loans:process-late` (10 min TTL) prevents two manual
runs from racing. Cron uses `->withoutOverlapping(60)` separately.

### Health endpoint

`GET /api/health/scheduler` — public, no auth, throttle 60/min. For
external monitoring (UptimeRobot, Healthchecks.io, Pingdom).

```json
{
  "status": "healthy" | "warning" | "critical",
  "last_run_at": "2026-04-23T03:30:00+00:00" | null,
  "minutes_since_last_run": 47,
  "expected_interval_minutes": 1440,
  "late_check_enabled": true,
  "last_run_stats": {
    "status": "success",
    "loans_scanned": 50,
    "schedules_marked_late": 3,
    "loans_transitioned_to_late": 2,
    "loans_recovered": 1,
    "recovery_skipped_default": 0,
    "notifications_queued": 17
  }
}
```

Status thresholds:
- `healthy` ≤ 26h since last run (24h + 2h grace)
- `warning` 26–48h (1 missed window)
- `critical` > 48h, OR never run → returns **HTTP 503**

### Timezone convention
- `config('app.timezone')` is the source of truth. Default `UTC`.
- Production should set `APP_TIMEZONE=Europe/Sofia` in `.env` —
  `due_date` is a date-only field, so "today − 10 days" depends on
  the timezone-of-record. F1 services use
  `Carbon::now(config('app.timezone'))->startOfDay()` consistently.
- If you change `APP_TIMEZONE`, run `loans:process-late --dry-run`
  the same day to see what would be re-evaluated.

## Currency
- Everything in EUR
- Format: 1,234.56 €
- Precision: 2 decimal places (decimal 12,2 in DB)

## Language
- Code, comments, commits: English
- UI text: Bulgarian
- API error messages: English (frontend translates)

## Frontend Standards:
- Reusable components for: stat cards, data tables, modals, form inputs, status badges, progress bars
- Loading skeletons while data loads — never blank screen
- Empty states with helpful message and CTA when no data
- Error states — show user-friendly message, not raw error
- Toast notifications for success/error actions
- Responsive: desktop first, but must work on mobile
- Consistent spacing, colors, typography across all pages
- All financial numbers formatted: 1,234.56 €
- Dates formatted: DD.MM.YYYY

## Design Style:
- Clean, modern fintech aesthetic
- Primary: navy (#1B2A4A)
- Accent/Success: green (#22C55E)
- Warning: orange (#F59E0B)
- Danger: red (#EF4444)
- Background: white + light gray sections (#F8FAFC)
- Font: Inter
- Plenty of whitespace
- Subtle shadows and borders, no heavy decoration
