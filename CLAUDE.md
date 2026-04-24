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

## Phase F2 — Buyback Guarantee

F2 adds the originator buyback honour flow on top of F1's late detection.
**Human-in-the-loop** model: cron detects eligibility, admin executes
from a Filament queue page. No automatic money movement from cron.

### Tables (F2 migrations)

- `originators` — two nullable config columns:
  - `buyback_coverage` VARCHAR(32) — enum-as-string, CHECK enforces
    `principal_only | principal_plus_interest`. NULL → falls back to
    `platform_settings.buyback_default_coverage`.
  - `buyback_trigger_days` SMALLINT UNSIGNED — CHECK enforces `0..365`.
    NULL → falls back to `platform_settings.buyback_default_trigger_days`.
    `0` is a valid value (immediate eligibility); explicit `??`
    coalescing throughout the service code (NOT `?:` or `isset`).
  - The existing `buyback` boolean stays as the master switch —
    eligibility service filters `buyback = true` first.
- `loans` — 5 nullable columns:
  - `buyback_eligible_at` — set by `loans:detect-buyback-eligible`
    cron when a loan crosses its trigger threshold. Cleared by admin
    "Reactivate" (un-dismiss) action. Index for cron + Queue filter.
  - `bought_back_at` — set by `BuybackExecutionService::execute()`
    at admin Execute click. Terminal (never cleared).
  - `buyback_dismissed_at` — set when admin dismisses from the Queue.
    Cron SKIPS dismissed rows (unless `--force`).
  - `buyback_dismissed_reason` VARCHAR(255) — admin note.
  - `buyback_dismissed_by` FK → `users.id`, RESTRICT on delete
    (accountability; mirror of `loan_events.triggered_by_user_id`).
- `platform_settings` — 3 seed rows + 2 CHECKs:
  - `buyback_default_coverage` = `principal_plus_interest`
  - `buyback_default_trigger_days` = `60` (CHECK 0..365 range)
  - `buyback_check_enabled` = `true` (kill switch for the cron only;
    admin can still Execute manually when false)
- `loan_events` — event-type enum ALREADY pre-expanded in F1 migration
  with `buyback_triggered` + `buyback_completed`. No F2 schema change.
- `transactions` — `type` column has NO DB CHECK (string only). F2
  adds `Transaction::TYPE_BUYBACK_PRINCIPAL` + `TYPE_BUYBACK_INTEREST`
  as app-level constants. Transaction immutability triggers from F1
  cover these automatically.

### State machine additions

- `Loan::STATUS_BOUGHT_BACK = 'bought_back'` — **TERMINAL** per Q3
  (investors already paid out; originator's post-buyback collection
  is off-platform, out-of-scope for the platform).
- `ALLOWED_TRANSITIONS` extended:
  - `late    → [active, default, repaid, bought_back]`
  - `default → [repaid, bought_back]` (per DECISIONS.md — rare but
    valid; an admin who manually escalated late→default can still
    receive a buyback agreement)
  - `bought_back → []` (terminal)
- `INVESTOR_VISIBLE_STATUSES` includes `bought_back` so investors see
  the terminal outcome in their portfolio.

### Detection mechanism

Command `loans:detect-buyback-eligible` runs daily at **03:45** via
`bootstrap/app.php` schedule, **AFTER** `loans:process-late` at 03:30.
15-minute gap is a comfortable buffer over F1's typical < 5-min runtime.
If F1 ever grows past 15 min consistently, move F2 to 04:00.

Eligibility query (`BuybackEligibilityService::detectNewlyEligible`):
```sql
WHERE loan.status IN ('late', 'default')
  AND loan.became_late_at IS NOT NULL
  AND loan.buyback_eligible_at IS NULL   -- idempotency key
  AND loan.buyback_dismissed_at IS NULL
  AND loan.bought_back_at IS NULL
  AND originator.buyback = true
  AND loan.became_late_at <= today - resolved(trigger_days)
```
Per-originator trigger override wins; NULL falls back to the platform
default via explicit `??` coalescing.

Pipeline per run:
1. Service returns newly-eligible loans.
2. For each: `BuybackCalculationService::calculateTotal()` computes the
   at-detection snapshot amount. Inside a per-loan transaction with
   `Loan::lockForUpdate()`:
   - Set `buyback_eligible_at = now()`.
   - Write `LoanEvent(buyback_triggered)` with metadata
     `{eligible_at, days_since_became_late, calculated_buyback_amount
     _at_detection, coverage_type, originator_id}`. `from_status` and
     `to_status` are both NULL (both-null branch of the
     `chk_loan_events_status_pair` CHECK — pure decision event, no
     loan transition at detection time).
3. Count loans aging > 3 days still in queue (for the digest's age
   breakdown). Runs AFTER flagging so newly-flagged rows don't leak
   into the "older" bucket.
4. If `newly + older > 0`: dispatch `BuybackEligibleAdminNotification`
   to every `User::role='admin'`. Skipped entirely when both counts
   are 0 (Q21 "no empty daily spam").
5. Metrics to `platform_metrics`:
   `last_buyback_check_{run_at, status, loans_newly_eligible,
   notifications_queued, enabled}`.

### Execution mechanism — admin-triggered only

Admin opens **Финанси → Buyback Queue** (Filament page
`App\Filament\Pages\BuybackQueue`). Navigation badge shows pending
count, `warning` color when > 0, invisible when 0. Default sort:
oldest `buyback_eligible_at` first (urgency).

Row actions per loan:
- **Execute** — confirmation modal recomputes the buyback total
  fresh (NOT from the at-detection snapshot in the event — the
  loan's schedule may have changed since cron flagged it). Calls
  `BuybackExecutionService::execute($loanId, auth()->id())`. On
  success, dispatches `LoanBoughtBackNotification` per investor
  (outside the DB transaction — money first, emails second).
- **Dismiss** — required reason textarea, stamps `dismissed_at`,
  `dismissed_reason`, `dismissed_by`. Reversible.
- **Reactivate** — clears the three dismissed_* fields; cron can
  re-flag the loan on the next run.

`BuybackExecutionService::execute()` wraps everything in one
`DB::transaction(function () { ... })`:
1. `Loan::lockForUpdate()` on the target.
2. Idempotency check — `status === 'bought_back'` → throw
   `BuybackAlreadyExecutedException` (Filament catches, surfaces
   a friendly `warning` toast).
3. State machine validation — only `late` or `default` allowed.
4. Dismissed check — `buyback_dismissed_at !== null` rejects with
   a "Reactivate first" message.
5. `BuybackCalculationService::calculateTotal()` — fresh amount.
6. `::distribute()` — pro-rata across investors; mirror of
   RepaymentService "last-investor-remainder" pattern (penny-precise;
   `Σ(investor_share) == total` exactly even for `100/3` → `33.33 +
   33.33 + 33.34`).
7. For each investor: `WalletService::buybackPrincipal()` (invested
   → available, TYPE_BUYBACK_PRINCIPAL transaction) and
   `::buybackInterest()` (available += + earned +=,
   TYPE_BUYBACK_INTEREST transaction).
8. Stamp `bought_back_at` + transition via `transitionTo('bought_back')`.
   **Empirically verified** to emit ONE UPDATE covering both fields
   (single audit_logs row, single state snapshot) — pinned by
   `BuybackExecutionServiceTest::test_single_update_query_for_bought
   _back_at_and_status`.
9. Write `LoanEvent(buyback_completed, late|default → bought_back)`
   with AGGREGATE metadata only — **no per-investor breakdown**
   (privacy: other investors' shares must not leak through the
   public loan timeline API).

Service returns `BuybackResult` (read-only DTO) containing
aggregates + per-investor `distributions` array. The Filament
action uses `distributions` to dispatch investor notifications
AFTER the `DB::transaction` has committed.

### Notifications

**Investor** — `LoanBoughtBackNotification`:
- `implements ShouldQueue`, channels `mail + database`.
- Dedupe: per `(user, loan, bought_back_at)` via
  `whereJsonContains('data->bought_back_at', ISO)`. Since
  `bought_back_at` is terminal-once, this effectively collapses to
  per-`(user, loan)` but the explicit timestamp key is retained for
  F1-pattern symmetry and admin-backfill defense.
- `toArray()` contract pinned: `bought_back_at` MUST be an ISO-8601
  string. Two contract-guard tests (one for ISO format, one for
  null — the null one marked SKIPPED with rationale because the
  constructor type hint forbids null).
- Email template `resources/views/emails/loan-bought-back.blade.php`:
  BG copy, positive framing, 7-row data table (loan id, originator,
  coverage type, received principal, received interest, total bold,
  bought_back_at), CTA to portfolio, educational reassurance
  paragraph. **NO borrower PII**; originator name IS included (public
  marketplace data).

**Admin digest** — `BuybackEligibleAdminNotification`:
- One per cron run (NOT per eligible loan). Dispatched only when
  `newly_eligible_count > 0 OR waiting_more_than_3_days_count > 0`.
- Dedupe: exact `data->run_at` ISO match — protects against queue
  worker retries re-delivering the same job; independent cron runs
  have distinct `run_at` values and both deliver normally.
- Email subject: `[P2P Invest] N нови buyback-eligible кредита —
  Buyback Queue`. Body shows age breakdown + loan IDs of newly-flagged
  + CTA to Queue + workflow reminder paragraph.

### Commands

```sh
# Daily cron (registered in bootstrap/app.php at 03:45).
php artisan loans:detect-buyback-eligible

# Flags (mirror F1 loans:process-late):
--dry-run      # TRULY read-only (DB::beginTransaction + rollBack wrapper).
               # No flags written, no events, no metrics, no emails.
--loan=ID      # Scope to one loan id (debug).
--detail       # Per-loan progress logging. (Renamed from --verbose.)
--force        # Bypass buyback_check_enabled platform setting.
```

Cache lock key `loans:detect-buyback-eligible` (10-min TTL) prevents
two manual runs from overlapping. Scheduler uses
`->withoutOverlapping(60)` separately.

### Health endpoint extension

`GET /api/health/scheduler` now covers BOTH schedulers:

- Legacy F1 flat fields preserved (`last_run_at`, `minutes_since_last_run`,
  `expected_interval_minutes`, `late_check_enabled`, `last_run_stats`).
- New nested `buyback` block with the same shape for the F2 scheduler.
- Top-level `status` = WORST of `late` + `buyback`, with **DISABLED
  schedulers excluded from the computation** (ops rule). Edge cases:
  - Both disabled → overall `healthy` (nothing active; nothing to fail).
  - One disabled + other critical → overall `critical` (the active one
    dominates).
  - Both enabled → normal worst-of-two.
- HTTP 503 iff top-level `status == 'critical'`.

### Dismiss mechanism + accountability

Three columns on `loans` carry the dismissal state:
- `buyback_dismissed_at` timestamp
- `buyback_dismissed_reason` string(255) — required by Filament form
  (not DB) so admins must justify
- `buyback_dismissed_by` FK → users.id, RESTRICT on delete

Cron skips dismissed rows. Reactivate clears all three. The admin
audit is implicit via the FK + Auditable trait on `Loan`.

### Scheduler dependency note

`loans:detect-buyback-eligible` READS `loan.status` that
`loans:process-late` maintains. The 03:30 → 03:45 ordering plus
`->withoutOverlapping(60)` on each command handles serialisation.
If F1 ever runs > 15 min consistently (growth, queue backlog),
move F2 to 04:00 and update this note.

### Idempotency layers

1. **Detection** — `buyback_eligible_at IS NULL` in the eligibility
   query PLUS a TOCTOU re-check inside the per-loan `lockForUpdate`
   in `DetectBuybackEligible::flagLoanAtomically`. A second manual
   run on the same loan no-ops.
2. **Execution** — `Loan::lockForUpdate()` + `status === 'bought_back'`
   check inside `BuybackExecutionService::execute`. A second call
   throws `BuybackAlreadyExecutedException`; the Filament action
   surfaces a friendly "already executed" toast.
3. **Admin digest** — per-`run_at` dedupe in
   `BuybackEligibleAdminNotification::wasRecentlyNotified` — queue
   worker retries of the SAME job don't double-insert into the
   admin inbox.

## Phase F3 — Early Repayment

F3 adds borrower-initiated early loan close-out on top of F1+F2.
**Admin-triggered only** model, same as F2 buyback: borrower contacts
admin externally, wires funds off-platform, admin verifies the transfer
in the business bank account, then clicks Execute in Filament. No cron.
No queue UI. No borrower-facing flow. Smaller scope than F2 (~70%
code clone of F2 buyback).

### Tables (F3 migrations — 1 file)

- `loans` — 2 nullable columns:
  - `early_repaid_at` — timestamp. Set by
    `EarlyRepaymentExecutionService::execute()` at admin Execute click.
    Distinguishes a `repaid` loan that was CLOSED EARLY from one that
    reached `repaid` through scheduled completion. Terminal (never
    cleared).
  - `early_repayment_amount` — DECIMAL(12,2). Denormalised audit value:
    the total distributed at execution. CHECK constraint:
    `IS NULL OR (amount >= 0 AND amount <= 10_000_000)` — lower bound
    is defense-in-depth; upper bound is a psychological sanity alarm
    (any single-loan close above 10M EUR is almost certainly a calc
    bug; platform loan amounts typically < 50k).

- No changes to `loan_events.event_type` — F1 pre-expanded the enum
  with `early_repayment_completed`. F3 writes it without migration.
- `transactions.type` column has NO DB CHECK — F3 adds 2 app-level
  constants (`TYPE_EARLY_REPAYMENT_PRINCIPAL`,
  `TYPE_EARLY_REPAYMENT_INTEREST`) to `Transaction::TYPES`.

### State machine (NO changes)

`Loan::ALLOWED_TRANSITIONS` already permits:
- `active → repaid`
- `late → repaid`
- `default → repaid`

F3 uses these existing paths. The `early_repaid_at` timestamp is the
"was closed early" marker — **NOT a new status**. Both F3-closed loans
and normally-completed loans share `status = 'repaid'`; the timestamp
distinguishes them (set vs null).

### Interest calculation — schedule-boundary (NO day-count accrual)

Per DECISIONS.md "F3: Early repayment — schedule-boundary interest":
```
outstanding_principal = Σ schedule.principal where status IN (pending, late)
unpaid_interest       = Σ schedule.interest where status IN (pending, late)
                         AND due_date <= next_upcoming_schedule.due_date
total                 = principal + interest
```

`next_upcoming_schedule.due_date` is the first unpaid with
`due_date >= today`. Fallback when EVERY unpaid is overdue (no
upcoming): use the LAST unpaid due_date as the boundary — filter
passes all unpaid interest (for a loan being closed after extensive
delinquency).

Intuition: borrower pays outstanding principal PLUS interest for the
CURRENT installment period. Past-due (late) interest is included — the
borrower catches up on missed months. No mid-month day-count accrual
(chosen over day-count for codebase consistency; see DECISIONS.md for
the v1 vs v1.1 rationale).

**Business-facing T&C language (per DECISIONS.md):**
> "При предсрочно погасяване, кредитополучателят плаща главница +
> цялата лихва до следващата планирана вноска."

### Execution mechanism — admin-triggered only

Admin opens **Финанси → Кредити** (Filament LoanResource). Clicks the
row action **"Предсрочно погасяване"** on any loan in
`active | late | default`. Action:

- **Visibility gate** (column-only, no per-row DB subquery):
  ```php
  in_array($r->status, [ACTIVE, LATE, DEFAULT])
      && $r->early_repaid_at === null
      && $r->bought_back_at === null
  ```
  No query on `amortization_schedules` in visibility to avoid N+1 on
  large LoanResource tables. Edge case (loan with no unpaid schedules)
  is caught by the modal's error panel instead.
- **Modal `modalContent`** closure runs `EarlyRepayment
  CalculationService::calculateTotal()` FRESH at open time.
  Renders either:
    - Happy-path breakdown: outstanding, unpaid interest, total,
      investor count, warning about irreversibility +
      bank-transfer-confirmation reminder.
    - Error panel: if calc throws (no unpaid schedules, data
      inconsistency), Blade renders a danger-tinted card with the
      exception message.
- **Action closure** — triple-catch (specific → typed → generic):
  ```php
  try {
      $result = EarlyRepaymentExecutionService::execute($id, auth()->id());
      // Dispatch per-investor EarlyRepaymentReceivedNotification
      // AFTER the DB::transaction committed (money first, emails second).
  } catch (EarlyRepaymentAlreadyExecutedException $e) {
      // Idempotency → warning toast
  } catch (InvalidArgumentException $e) {
      // Wrong status / zero total → danger toast with service message
  } catch (\Throwable $e) {
      // Unexpected → Log::error + generic danger toast
  }
  ```

`EarlyRepaymentExecutionService::execute()` wraps everything in one
`DB::transaction(function () { ... })`:
1. `Loan::lockForUpdate()` on the target.
2. **Differentiated idempotency/state validation:**
   - `status=repaid + early_repaid_at set` →
     `EarlyRepaymentAlreadyExecutedException` ("already early-repaid on
     {date}"). Filament catches for warning toast.
   - `status=repaid + early_repaid_at NULL` → `InvalidArgument`
     ("already repaid through scheduled completion"). Different
     semantic from idempotency — loan finished normally, early-repay
     is not applicable.
   - `status=bought_back` → `InvalidArgument` ("originator owns the
     debt; early repayment N/A").
   - `status NOT IN (active, late, default)` → `InvalidArgument`
     ("not yet activated").
3. `EarlyRepaymentCalculationService::calculateTotal()` — fresh amount.
   Throws `InvalidArgument` on zero unpaid schedules.
4. `::distribute()` — pro-rata with last-investor-remainder (penny-
   precise; Σ(shares) == total exactly).
5. Per investor: `WalletService::earlyRepayPrincipal` (invested →
   available, `TYPE_EARLY_REPAYMENT_PRINCIPAL`) and `::earlyRepayInterest`
   (available += + earned +=, `TYPE_EARLY_REPAYMENT_INTEREST`).
   Reference: `"loan:{id}:early_repayment:user:{user_id}"`.
6. **Single UPDATE** — `forceFill(['early_repaid_at' => now,
   'early_repayment_amount' => calc->total])` then
   `transitionTo(STATUS_REPAID)` — both fields + status persist in
   ONE UPDATE on `loans` (empirically verified by
   `EarlyRepaymentExecutionServiceTest::test_single_update_query_for
   _early_repaid_at_and_amount_and_status`).
7. `LoanEvent(early_repayment_completed, <from> → repaid)` with
   aggregate metadata only — `executed_by_admin_id`, `from_status`,
   `total_amount`, `total_principal`, `total_interest`,
   `investor_count`, `executed_at`. **No per-investor breakdown** —
   privacy (other investors' shares must not leak through the public
   timeline API).

Service returns `EarlyRepaymentResult` (read-only DTO) containing
aggregates + per-investor `distributions`. The Filament action uses
`distributions` to dispatch `EarlyRepaymentReceivedNotification`
per investor AFTER the `DB::transaction` has committed.

### Notifications

**Investor** — `EarlyRepaymentReceivedNotification`:
- `implements ShouldQueue`, channels `mail + database`.
- Constructor snapshot: `Loan $loan, CarbonInterface $executedAt,
  string $investorPrincipal, string $investorInterest,
  string $totalReceived`. No `coverageType` (early repayment has no
  per-originator config).
- Dedupe: per `(user, loan, early_repaid_at)` via
  `whereJsonContains('data->early_repaid_at', ISO)`. Since
  `early_repaid_at` is terminal-once, this effectively collapses to
  per-`(user, loan)` but the explicit timestamp key is retained for
  F1/F2 contract symmetry.
- Contract guard: `toArray()` MUST keep `early_repaid_at` as an
  ISO-8601 string. Pinned by
  `EarlyRepaymentReceivedNotificationTest::test_toarray_includes_
  early_repaid_at_as_iso_string_for_rate_limit_contract`.
- Email template `resources/views/emails/early-repayment-received.blade.php`:
  BG copy, positive framing, 6-row data table (loan id, originator,
  received principal, received interest, total bold, executed date),
  CTA to portfolio, educational paragraph explaining Option B
  behaviour. NO borrower PII; originator name included (public).

### Admin-facing behaviour — NO cron

F3 has **no detection cron**. No `loans:detect-*` command. No scheduler
entry in `bootstrap/app.php`. No health endpoint extension. Admin
verifies the borrower's bank transfer externally and manually
triggers execution.

### Idempotency layers (2, not 3)

1. **Execution** — `Loan::lockForUpdate()` + differentiated status
   check inside `EarlyRepaymentExecutionService::execute`. A second
   call on an already-early-repaid loan throws
   `EarlyRepaymentAlreadyExecutedException`; Filament surfaces a
   friendly "already done" toast.
2. **Notification** — per-(user, loan, early_repaid_at) dedupe in
   `EarlyRepaymentReceivedNotification::wasRecentlyNotified`. Protects
   against queue-worker retries and against degenerate admin-SQL-
   intervention scenarios.

### Known limitations

- **Overpayment up to half a monthly installment** (Option B schedule-
  boundary). Borrower closing mid-month pays interest "до следваща
  планирана вноска" — slightly more than pure day-count accrual would
  charge. ~5 EUR average per early close; the overpayment flows to
  investors as extra distribution (not retained by platform).
- **`loans.activated_at` NOT added.** Day-count alternative (rejected
  for v1) would have needed this. If v1.1 adds day-count, add the
  column then.

## Phase F4 — Fees Infrastructure

F4 adds withdrawal-fee infrastructure on top of F1+F2+F3. Per client
decision Q5, the infrastructure ships **disabled by default**
(`fees_withdrawal_enabled = false`) so existing flows are byte-identical
until an operator explicitly flips it on the Filament admin surface.
Scope is intentionally narrow — only the withdrawal category is wired
in v1; origination, service, late, early-repayment, and inactivity
categories are placeholder-ready but not implemented.

### Tables (F4 migration — 1 file, no new tables)

Extends the F1 `platform_settings` key/value store with 2 rows +
1 CHECK. No standalone `fees` table (single-tenant simplicity per
client Q11).

- `fees_withdrawal_enabled` (bool, default `'false'`) — master
  toggle. When false, `WithdrawalService::approve()` is byte-identical
  to pre-F4 (single `TYPE_WITHDRAWAL` at full amount; no `TYPE_FEE`
  row).
- `fees_withdrawal_amount` (float, default `'2.50'`) — flat EUR
  fee. Defense-in-depth CHECK `chk_fees_withdrawal_amount_range`
  enforces `value REGEXP '^[0-9]+([.][0-9]{1,2})?$'` AND
  `CAST(value AS DECIMAL(6,2)) BETWEEN 0 AND 100`. Pattern mirrors
  F1 `chk_grace_period_days_range` + F2
  `chk_buyback_default_trigger_days_range`. SQLite-skipped (test
  suite only).

Existing scaffold (since F1) that F4 activates:
- `Transaction::TYPE_FEE = 'fee'` — app constant + in `Transaction::TYPES`
  allowlist. F1-era display surfaces (TransactionResource badge,
  AuditLogResource label, TransactionsPage.vue filter, ReconcileLedger
  aggregation) all pass through unchanged — they've been waiting for
  the producer side.
- `LoanEvent::TYPE_FEE_APPLIED = 'fee_applied'` — pre-expanded in the
  F1 `loan_events` CHECK enum. **NOT written by F4** — withdrawal
  fees aren't loan-scoped. Reserved for future per-loan fee
  categories.

### Feature-flag shape — per-category, not master

Per DECISIONS.md F4-01:
- ONE flag per category, not a single master flag.
- v1: `fees_withdrawal_enabled` + `fees_withdrawal_amount`.
- Future categories add their own pair: `fees_origination_enabled` +
  `_amount`, etc. Each gets its own migration + CHECK + FeeService
  wiring.
- Rationale: gradual adoption. Master-flag would force all-or-nothing
  as each fee type is introduced.

### Virtual-ledger accounting — no platform wallet

Per DECISIONS.md F4-01:
- A fee is recorded as a `TYPE_FEE` transaction debiting the
  investor's wallet bucket (specifically `reserved` for withdrawal
  fees — see integration below). NO corresponding credit to a
  platform-owned wallet.
- Real-world: admin keeps the fee portion in the business bank
  account and wires the NET amount to the investor's IBAN
  externally. Platform ledger records the debit; admin's bank
  statement is the source of truth for accrued platform revenue.
- Reconciliation: `SELECT SUM(amount) FROM transactions WHERE type
  = 'fee' AND created_at BETWEEN ?` gives the period fee accrual
  for cross-checking vs. the bank statement.
- Revisit triggers for introducing a `platform_wallets` table:
  multi-tenant expansion, regulator-mandated fiduciary-vs-operating
  separation, or reconciliation friction (F4-01).

### FeeService API (`app/Services/FeeService.php`)

Pure reader, zero side effects.

- `isEnabled(string $category): bool` — reads `platform_settings.
  fees_{category}_enabled`. Missing key → false (fail-safe).
- `isWithdrawalFeeEnabled(): bool` — convenience for the common
  call site.
- `getAmount(string $category): string` — returns normalised
  2-decimal-string EUR amount. Missing/unconfigured → `'0.00'`.
- `getQuote(string $category, string $grossAmount): FeeQuote` —
  builds the quote DTO. Returns `applies=false` when:
  - flag off, OR
  - gross <= 0, OR
  - configured amount <= 0.

Category whitelist via `FeeService::CATEGORIES` — unknown category
throws `InvalidArgumentException` (prevents typos at call sites
from silently resolving to empty config).

`FeeQuote` (`app/Services/FeeQuote.php`) — read-only DTO:
- `applies: bool`, `amount: string` (2-decimal), `category: string`.
- `netAmount(string $gross): string` — returns `gross - amount` via
  `bcsub(..., 2)` if applies; otherwise returns gross unchanged.

### WithdrawalService integration

`WithdrawalService::approve()` has two code paths, both inside a
single `DB::transaction`. The fee lookup happens INSIDE the
transaction so a mid-approve flag flip can't create a
preview/charge TOCTOU.

**Fee-off (default)** — byte-identical to pre-F4. One
`WalletService::debitReserved()` call with `TYPE_WITHDRAWAL` and
reference `withdrawal_request:{id}`.

**Fee-on** — two `debitReserved` calls:

1. `debitReserved(net, TYPE_WITHDRAWAL, "withdrawal_request:{id}")`
   where `net = bcsub(gross, fee, 2)`. Net is what wires to the
   investor's bank.
2. `debitReserved(fee, TYPE_FEE, "withdrawal_request:{id}:fee")`.
   Fee stays with the platform (off-platform in admin's business
   bank account).

Both debits deplete the RESERVED bucket (the investor earmarked the
full gross at request time via `WalletService::reserve()`). Sum
equals gross; `reserved` drops to its pre-request value (0 in the
simple case). `available` is untouched by the approve path in
BOTH flag-on and flag-off scenarios.

**Negative-net guard** — if `bccomp(net, '0', 2) <= 0` (fee >= gross;
edge case when admin enabled the flag AFTER a small request was
created), throws `ValidationException("must exceed fee")`. The
`DB::transaction` rolls back; zero partial commits.

**Idempotency** — guaranteed by the `WithdrawalRequest::where(
'status', 'pending')->firstOrFail()` at the top of `approve()`. A
second call on an already-approved withdrawal throws
`ModelNotFoundException` before any wallet touch. The `:fee`
reference suffix is an audit link, NOT an idempotency key.

### API endpoint — GET /api/fees/config

Public, no auth, throttled 60/min. Shape:
```json
{
  "withdrawal": {
    "enabled": false,
    "amount": "2.50"
  }
}
```

Consumed by `WithdrawalPage.vue` to render the live breakdown
(gross / fee / net). Public because the fee schedule is already
advertised on the landing FAQ + chatbot. Nested-by-category shape
(per DECISIONS.md F4-03) so future categories extend without
breaking existing SPA consumers.

No API Resource wrapper — the payload derives from
`platform_settings`, not models. Mirrors `SchedulerHealthController`
direct `JsonResponse` precedent.

### Admin UI — FeesPage (Filament)

`app/Filament/Pages/FeesPage.php` — navigation
**Финанси → Такси**, admin-only via `canAccess() → isAdmin()`.

**Not a Resource** (DECISIONS.md F4-02) — `platform_settings` is
the backing store; a Resource would need either a sham single-row
model or a scoped `PlatformSetting` resource. Filament Page is the
idiomatic primitive for singleton config screens; BuybackQueue +
ProcessRepayment precedent.

Form sections:
1. **Такса при теглене** — Toggle (`fees_withdrawal_enabled`) +
   TextInput (`fees_withdrawal_amount`, numeric, min 0, max 100,
   step 0.01). Both `live(debounce: 400)`.
2. **Преглед** — user-input `preview_amount` + `Placeholder` with
   live closure rendering "При теглене X € / Такса Y € / Получавате
   Z €". Three branches: disabled state, normal state, amount-≤-fee
   warning. Preview reflects FORM state (unsaved), so the admin
   sees what Save will produce.
3. **Warning banner** — 4-step activation checklist (HTML ordered
   list). Reminds admin to amend public copy (FAQ + chatbot) and
   consider notifying existing investors BEFORE flipping the flag.

Stats + recent-fees block (Blade view below the form):
- All-time + this-month `TYPE_FEE` sum + count (two stat cards).
- Latest 10 `TYPE_FEE` transactions with user eager-loaded.
- Empty state: "Все още няма записани такси. Първата такса..."

Save handler calls `PlatformSetting::set()` for each row. `Auditable`
trait on `PlatformSetting` → every edit writes to `audit_logs`
(old + new value, admin, IP, UA).

### Investor UI — WithdrawalPage breakdown

`resources/js/views/WithdrawalPage.vue`:
- On mount, fetches `/api/fees/config` in `Promise.all` alongside
  `loadHistory()` + `loadIbans()`.
- Graceful degradation: fetch failure → defaults to
  `{enabled: false, amount: '0.00'}` → breakdown hidden entirely.
- Live computed breakdown shown below the amount input AND inside
  the confirmation modal. Fields: Заявявате / Такса / Получавате
  (three rows, `data-testid="fee-breakdown"`).
- `amountBelowFee` computed blocks `openConfirm()` when net would
  be negative — catches the admin-enabled-fee-after-small-request
  edge on the client side before the server hits the
  `ValidationException`.
- Hidden entirely when flag is off (no "0.00 € такса" clutter).

### Commands — none

F4 adds NO scheduled commands. No `loans:process-*` or
`loans:detect-*`. No new cron entries. No new log files. No new
queue workers. Fee application is entirely admin-triggered via
`WithdrawalService::approve()` (which itself is triggered by admin
approval of a pending withdrawal).

### Health endpoint — no changes

F4 does not extend `/api/health/scheduler`. No cron to monitor.

### Idempotency layers — 1

1. **Withdrawal approval** — `WithdrawalRequest::where('status',
   'pending')->firstOrFail()` at the top of `approve()`. Second
   call throws `ModelNotFoundException` before any wallet touch.

No detection cron, no investor notifications, no per-loan fee
events in v1. F4 has fewer idempotency layers than F2 (3) or F3 (2)
as a direct consequence of scope narrowness.

### Known limitations (intentional; v1.1+)

- **Withdrawal-only scope.** Origination / service / late /
  early-repayment / inactivity fees are placeholder-ready
  (`Transaction::TYPE_FEE` + `LoanEvent::TYPE_FEE_APPLIED`) but not
  implemented. Each requires its own migration + `FeeService::
  CATEGORIES` entry + integration point.
- **No platform-wallet model.** Fee revenue lives in admin's bank
  account (off-platform). Platform ledger records the debit only;
  reconciliation is the admin's bank statement vs. `SUM(TYPE_FEE)`
  per period. See DECISIONS.md F4-01 revisit triggers for when a
  `platform_wallets` table becomes worth the schema cost.
- **No browser tests.** Vue breakdown rendering verified manually
  (no Dusk / Vitest in the project). Data-contract coverage is
  PHPUnit-only via `FeesConfigApiTest` — 6 tests pinning the exact
  JSON shape `WithdrawalPage.vue` consumes.
- **Public copy coupling.** When operator flips
  `fees_withdrawal_enabled` to true, FAQ (`FaqSection.vue`) +
  chatbot (`ChatbotWidget.vue`) public copy MUST be amended to
  describe the concrete fee. The FeesPage activation banner warns
  of this but does not enforce. (Copy is currently "безплатно"
  per commit `f301c19`.)

## Phase F5 — APR Display

F5 surfaces the Annual Percentage Rate (Годишен Процент на
Разходите — "ГПР") for each loan across the admin, investor, and
API layers. Pure calculation on top of an existing column — no DB
migration, no state machine change, no cron, no notifications, no
feature flag. Activates the F1-L6 `interest_rate_annual` column
("metadata-only" since F1) and routes it into admin reporting +
investor transparency.

### Purpose — EU CCD compliance + dual-axis transparency

Consumer Credit Directive 2008/48/EC requires borrower-facing
disclosure of the APR. Although this platform has no borrower UI
(borrower is a data model only), the investor-facing display of
the *borrower's* cost of credit serves two ends:
  1. Investor due diligence — transparency about the originator's
     pricing of the underlying loan.
  2. Alignment with the CCD philosophy — regulator-audit trail
     shows the APR was disclosed and computed consistently.

### Formula — nominal pass-through (v1)

```php
APR = number_format((float) $loan->interest_rate_annual, 2, '.', '')
```

For a no-fee annuity loan, the nominal borrower rate IS the EU CCD
APR by definition (the IRR equation `Σ Ck / (1+X)^tk = Σ Dl /
(1+X)^sl` collapses to X = nominal rate when drawdowns = principal
and repayments = scheduled annuity payments). Therefore pass-through
is EXACT, not approximation. See DECISIONS.md F5-01 for the full
proof + worked example of why the simple-flat formula would have
been wrong.

### Upgrade path — IRR solver when borrower-side fees activate

When F4's `FeeService::CATEGORIES` gains any *borrower-side* fee
(origination / service / late / inactivity — all currently
placeholder-ready), swap the service internals to a Newton-Raphson
IRR solver in bcmath. Caller contract (`calculate(Loan): ?string`)
stays unchanged — Vue, Filament, and API consumers keep working
without edits. Estimated effort: 1 day solver + 0.5 day test
fixtures from regulator example calculators.

### Dual display — Доходност + ГПР, distinct semantics

| Audience | "Доходност" | "ГПР" |
|---|---|---|
| Investor | Your yield from this loan (investor return, from `interest_rate`) | Borrower's cost of credit (disclosure, from `interest_rate_annual`) |
| Admin | Same | Same + a third "Марж" row = ГПР − Доходност (originator spread; admin-only) |

Both rates shown side-by-side on the investor marketplace (new
"ГПР" column) and loan detail page (expanded 5-cell stats grid)
with Bulgarian helper copy immediately below:

> **Доходност** — какво печелите вие.
> **ГПР** — какво плаща кредитополучателят (включва лихва и такси).

LandingPage + DashboardPage + PortfolioPage intentionally NOT
touched — ГПР is a loan-detail concept, cluttering summary views
would dilute the transparency signal.

### Admin "Марж" — operator profit-margin visibility

Filament `LoanResource` form adds a read-only "Ставки" section
(visible on edit pages only — `visible(fn (?Loan $record) =>
$record !== null)`). Three Placeholders:
- Доходност — `interest_rate`
- ГПР (APR) — `$loan->apr()`
- Марж — `bcsub(interest_rate_annual, interest_rate, 2)`

`helperText` on the Марж row explicitly says "Само за вътрешен
преглед, не се показва на инвеститорите". Admin-only by gating —
the whole Filament panel is admin-only via `canViewAny()`.

Marge is computed inline (not stored). A future per-originator
tier-pricing feature may want to store snapshots; for now the
derivation is cheap and admin-live is fine.

### Null-safe 3-layer defense

Triggered when `interest_rate_annual` is null or non-positive —
defensive against the F1-L6 "metadata-only" legacy state where
nothing had touched the column. DB column is `NOT NULL
decimal(5,2)` so production can't have null, but zero could slip
through raw SQL.

1. **Service layer** — `APRCalculatorService::calculate()` returns
   `null` for null / zero / negative.
2. **Model layer** — `$loan->apr()` passes the null through; memoed
   (`$aprMemoResolved` flag needed because null is a valid cached
   result).
3. **UI layer** —
   - API: `'apr' => $this->apr()` → JSON `null` on the wire.
   - Vue: `<span v-if="loan.apr">{{ loan.apr }}%</span><span
     v-else class="text-gray-300">—</span>` — never "0.00%".
   - Filament table column: `formatStateUsing(fn ($r) =>
     $r->apr() !== null ? $r->apr() . '%' : '—')`.
   - Filament "Ставки" Placeholders: same fallback.

### Integration points

| Layer | File | Role |
|---|---|---|
| Service | `app/Services/APRCalculatorService.php` | Pure `calculate(Loan): ?string` — nominal pass-through in v1 |
| Model | `app/Models/Loan.php` | `apr(): ?string` — delegates to service, per-instance memo |
| API | `app/Http/Resources/LoanResource.php` | `'apr' => $this->apr()` — null-safe |
| Admin | `app/Filament/Resources/LoanResource.php` | Table column + "Ставки" edit-page section (Доходност / ГПР / Марж) |
| Investor | `resources/js/views/MarketplacePage.vue` | ГПР table column + mobile-card sub-line |
| Investor | `resources/js/views/InvestmentDetailPage.vue` | ГПР stat cell + helper paragraph |

Form validation: `LoanResource::form` made `interest_rate_annual`
`->required() ->minValue(0.01) ->maxValue(999.99)` with explicit
`rules(['numeric', 'min:0.01', 'max:999.99'])`. Prevents new loans
from being created with zero/null ГПР.

### Known limitations (intentional; v1.1+)

- **Nominal pass-through only.** IRR solver is the v1.1 upgrade
  trigger when any borrower-side fee activates (F5-L1). Today's
  math is exact because fees are zero.
- **No `apr_at_activation` snapshot column.** Live recalculation;
  if regulation ever requires "APR as disclosed on day X"
  preservation, add snapshot + set it on `funded → active`
  transition (F5-L2).
- **No Vue browser tests** — same coverage gap as F1/F2/F3/F4.
  Data-contract pinned via `LoanAPRApiTest`; Filament
  render-smoke pinned via `LoanResourceAPRTest`. Manual browser
  QA before production (F5-L3).
- **`interest_rate_annual` = 0 loans render as "—".** Form
  validation now blocks new ones, but existing rows created
  before the `minValue(0.01)` rule may still carry zero. Backfill
  is optional — UI handles it gracefully with the dash.

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
