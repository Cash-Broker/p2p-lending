# Phase F1 — Late/Default Automation: Implementation Report

**Branch:** `feature/late-default-automation` (9 commits ahead of `main`)
**Base commit:** `f10aeb2` (post Phase 1 + Phase B)
**Final commit:** `117ad35` (Step 7 tests; Step 8 docs commit follows)
**DB backup:** `backup_before_f1.sql` (146 KB, taken before any change)
**Decisions confirmed by client:** grace period = 10 days, NO penalty interest in v1, buyback automation deferred to Phase F2.

---

## Completion summary

| Step | Description | Commit |
|---|---|---|
| 0 | Discovery (current state, spec mismatches, decisions) | (this doc) |
| 1 | 5 migrations: `platform_settings`, `platform_metrics`, loans columns, schedule columns, `loan_events` (with 4 CHECK + 2 triggers) | `ed40099` |
| 2 | Models (`PlatformSetting`, `PlatformMetric`, `LoanEvent`) + services (`LateDetectionService`, `LoanStatusUpdaterService`) | `acd95d8` |
| 3 | Command `loans:process-late` (`--dry-run/--loan/--detail/--force`) + health endpoint `/api/health/scheduler` | `8cbdd72` |
| 3f | Schedule registration (`dailyAt('03:30')`) + `appendOutputTo` log + `recovery_skipped_default` metric | `12eb599` |
| 4 | Filament admin: `LoanHealthOverview` widget, `PlatformSettingResource`, `LoanResource` extensions, `LoanEventsRelationManager`, `AmortizationSchedulesRelationManager` extensions | `e1b8a3e` |
| 5a | API: `PortfolioController` extensions (withMax, late counts), `LoanResource`/`AmortizationScheduleResource` field additions, new `/api/loans/{loan}/events` endpoint with `LoanPolicy::viewEvents` + `LoanEventResource` whitelist sanitiser | `091fa45` |
| 5b | Vue: `PortfolioPage.vue` banner + days_overdue badge + tooltips, `InvestmentDetailPage.vue` timeline section + `DECISIONS.md` admin role consolidation entry | `f4ae667` |
| 6 | `LoanWentLateNotification` (ShouldQueue, mail+database, by-late-period dedupe) + Bulgarian markdown email template + command integration | `8a1fd0a` |
| 7 | 53 new tests across 8 files (including 2 contract-guard tests pinning the dedupe ISO format) | `117ad35` |
| 8 | This doc + CLAUDE.md F1 section + README operational note | (next commit) |

## Test coverage

| Metric | Phase 1 baseline | After F1 | Delta |
|---|---|---|---|
| Tests | 252 | **305** | +53 |
| Assertions | 623 | **780** | +157 |
| Files | 17 in tests/ | 25 in tests/ | +8 (incl. new subdirs) |
| Regressions | — | **0** | — |

New test files:

| File | Tests | Focus |
|---|---|---|
| `tests/Feature/Notifications/LoanWentLateNotificationTest.php` | 10 | Contract guards (became_late_at ISO), rate-limit (3 scenarios), email body BG, no PII, ShouldQueue |
| `tests/Feature/Loans/ProcessLateLoansCommandTest.php` | 7 | Happy path, idempotency, dry-run zero-writes, --loan, --force, cache lock, metrics |
| `tests/Feature/Api/LoanEventsApiTest.php` | 5 | 200/403 by position, whitelist sanitiser with adversarial keys, no triggered_by_user_id leak, pagination |
| `tests/Feature/Api/SchedulerHealthEndpointTest.php` | 6 | 4 health states, HTTP 503 critical, no-auth, late_check_enabled reflected |
| `tests/Unit/Loans/LateDetectionServiceTest.php` | 8 | Grace boundaries (0/9/10/29/30), paid not marked, already-late skipped, loan filter, snapshot idempotency |
| `tests/Unit/Loans/LoanStatusUpdaterServiceTest.php` | 6 | 3 user-spec recovery scenarios + R1 history guard + default safeguard + days_late_at_transition |
| `tests/Unit/Models/PlatformSettingTest.php` | 6 | Type coercion (int/bool/json), default fallback, Auditable writes |
| `tests/Unit/Models/LoanEventTest.php` | 5 | App-level update/delete throws, DB trigger blocks raw SQL, CHECK rejects bad event_type + self-transition |

## Known limitations (deliberate; F2/F3/F4 hooks)

| # | Limitation | Hook for future phase |
|---|---|---|
| L1 | No auto `late → default` transition. Admin must manually transition via Filament. | F2 will consume `recovery_skipped_default` metric and the `loan_events.event_type IN ('went_default', 'buyback_triggered', 'buyback_completed')` enum slots (already migrated in F1). |
| L2 | `originators.buyback` boolean has no honour-flow. Investors see "Buyback гаранция: Yes" with no mechanism. | F2 buyback automation. Email template intentionally omits buyback promises. |
| L3 | No penalty interest. Per client decision (recorded in CLAUDE.md). | Out of scope for v1. |
| L4 | No early-repayment rebate. Admin can input arbitrary repayment amounts; no auto-recompute of remaining schedule. | F3 (`early_repayment_*` enum slots reserved). |
| L5 | No origination/service/early-repayment fees. `Transaction::TYPE_FEE` enum exists, never written. | F4 (`fee_applied` enum slot reserved). |
| L6 | `interest_rate_annual` (borrower rate) is metadata-only, not used in any computation. | Originator-spread accounting future feature. |
| L7 | Notifications have no investor opt-out. | Post-launch preferences feature. |
| L8 | Filament settings page gated on existing `role=admin` (no super-admin role yet). | See `DECISIONS.md` "Admin role consolidation for v1". |
| L9 | `LoanEventsRelationManager` metadata column is plain text + tooltip. No syntax-highlighted JSON viewer. | Cosmetic, defer. |
| L10 | Vue UI not visually verified (Vite manifest absent in worktree). Manual browser test required before production. | Standard pre-deploy checklist. |
| L11 | `APP_TIMEZONE` not set in `.env.example`. Production must set `Europe/Sofia` explicitly. | README operational note flags this. |
| L12 | No system-cron documentation. The Laravel scheduler doesn't fire without an OS-level cron (`* * * * * php artisan schedule:run`). | README operational note covers this. |

## Operational pre-deploy checklist

1. `composer install` — F1 added no new packages but check `composer.lock` matches.
2. `php artisan migrate` — verifies all 5 F1 migrations apply cleanly.
3. Set `APP_TIMEZONE=Europe/Sofia` in production `.env`.
4. Configure system cron: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
5. Configure queue worker (Supervisor or systemd). Required for notifications. Without it, `LoanWentLateNotification` jobs accumulate in `jobs` table and never deliver.
6. Verify with `php artisan loans:process-late --dry-run --detail` against a backdated test loan.
7. Curl `/api/health/scheduler` from outside the cluster — should return `200 healthy` after first scheduled run.
8. Vue/Vite: `npm install && npm run build`. Verify `public/build/manifest.json` exists.
9. Manual browser test: visit `/portfolio` (with a late loan in the test DB) and `/admin` (Filament dashboard widget + LoanEventsRelationManager).

## Step completion order (for replay / forensic review)

```
ed40099 → acd95d8 → 8cbdd72 → 12eb599 → e1b8a3e → 091fa45 → f4ae667 → 8a1fd0a → 117ad35 → (Step 8 docs)
```

---

## Step 0 — Current State

This report covers what exists today, what is missing, and any spec ↔ codebase mismatches the maintainer should be aware of before Step 1 (migrations) runs.

---

### 1. Loan state machine — what exists

[app/Models/Loan.php](app/Models/Loan.php) defines:

```
const STATUSES = [draft, published, funding, funded, active, late, default, repaid]
const ALLOWED_TRANSITIONS = [
    draft     → [published]
    published → [draft, funding]
    funding   → [funded]
    funded    → [active]
    active    → [late, repaid]            ← we will use this
    late      → [active, default, repaid] ← we will use this for recovery + escalation
    default   → [repaid]
    repaid    → []
]
```

**`Loan::transitionTo($newStatus)`** is the single entry point — it validates against `ALLOWED_TRANSITIONS`, runs inside a `DB::transaction`, calls `forceFill(['status' => …])->save()`, and (only on funded → active) generates the amortization schedule.

✅ The transitions we need (`active → late`, `late → active` recovery, `late → default`) are already permitted. We can reuse `transitionTo()` and don't need to add new transitions.

⚠️ **Spec ↔ code mismatch (cosmetic):** The user's brief mentions statuses `pending, active, funded, repaying, completed, defaulted, late`. The actual enum is `draft, published, funding, funded, active, late, default, repaid`. F1 will use the actual names — most importantly `repaid` (not `completed`), `default` (not `defaulted`), `active` (not `repaying`).

---

### 2. Schedule items — what exists

[database/migrations/…_create_amortization_schedules_table.php](database/migrations/2026_03_29_100004_create_amortization_schedules_table.php):

```sql
CREATE TABLE amortization_schedules (
    id, loan_id, due_date,
    principal DECIMAL(10,2), interest DECIMAL(10,2), total DECIMAL(10,2),
    status VARCHAR DEFAULT 'pending',     -- enum: pending, paid, late, default
    paid_at TIMESTAMP NULL,
    timestamps,
    INDEX (loan_id, due_date)
)
```

**Status writes today:** Only `pending` (creation) and `paid` (`RepaymentService::processRepayment()` marks the matching row paid when admin selects it from the dropdown). The values `'late'` and `'default'` are part of the enum **but are never written by any code** — confirmed via `grep`.

⚠️ **Spec ↔ code:** Spec calls the table `schedule_items` / `loan_schedule`; actual name is `amortization_schedules`. F1 will keep the actual name. The Bulgarian Filament UI label is "Погасителен план" — same concept.

---

### 3. Email infrastructure — what exists

- **Driver:** `MAIL_MAILER=smtp` (Mailtrap sandbox in local), production should use real SMTP.
- **Queue:** `QUEUE_CONNECTION=database` in `.env`; `sync` in `phpunit.xml`. The `jobs` table exists. **A queue worker (`php artisan queue:work`) must be running in production** for any `ShouldQueue` mail to actually send.
- **Two patterns are already in use:**
  1. **Notifications (Notifiable trait):** [app/Notifications/](app/Notifications/) — 8 existing classes (`DepositApprovedNotification`, `LoanStatusChangedNotification`, `RepaymentReceivedNotification`, etc.). All `via: ['mail', 'database']` so they appear in the in-app inbox AND go to email. **Already-existing `LoanStatusChangedNotification` is a perfect pattern but its body is generic** — the spec asks for a richer email (overdue amount, days overdue, "originator is collecting" copy). Decision in F1: build a dedicated `LoanWentLateNotification` rather than overload the generic one.
  2. **Mailables (`Mail::to(...)->queue(new XMail)`):** [app/Mail/AdminLoginAlertMail.php](app/Mail/AdminLoginAlertMail.php) (Phase 1). Used for non-user mail (admin alerts) where we don't want the message to land in the user's notification inbox.

**Decision for F1:** Use the **Notification** pattern for investor "loan went late" mail — investors should also see this in their in-app inbox.

✅ Notification table already exists ([2026_03_29_092948_create_notifications_table.php](database/migrations/2026_03_29_092948_create_notifications_table.php)) — no new migration needed for the notification storage.

---

### 4. Cron / scheduler — what exists

Laravel 11+ style scheduling in `bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule): void {
    $schedule->command('ledger:reconcile --notify')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->runInBackground();
})
```

Single command today: `ledger:reconcile`. F1 adds `loans:process-late` daily at **03:30** (after the reconciliation command finishes — the spec asked for "after nightly reconciliation"). Both with `->withoutOverlapping()` to prevent double-runs.

⚠️ **Operational gap:** There is no setup documented for actually running the scheduler in production (no `* * * * * php artisan schedule:run`-style crontab note). Both `ledger:reconcile` and our new `loans:process-late` will silently never fire if no system cron is configured. F1 will add a manual-trigger option to the new command so support can fire it on demand if the cron breaks, AND will add the "warn if not run for 48h" check to the new command. Actually wiring the cron itself is operational, not code — flagged for ops handoff.

✅ Existing `ReconcileLedger` is a good template for our new command (`handle()` returns Command::FAILURE on issue, sends alert email, etc.).

---

### 5. Filament admin — what exists

- **Panel:** `/admin`, registered in [AdminPanelProvider](app/Providers/Filament/AdminPanelProvider.php) with `discoverPages()` + `discoverWidgets()`. Auto-discovery means dropping a class in `app/Filament/Pages/` or `…/Widgets/` is enough — no manual registration.
- **Existing widgets** ([app/Filament/Widgets/](app/Filament/Widgets/)):
  - `StatsOverview` — 5 stats (investors, invested €, active loans, pending deposits, pending withdrawals). **Currently shows "Активни кредити" but not late/default counts.** F1 will extend this widget OR add a sibling `LoanHealthOverview` widget — extending is cleaner.
  - `InvestmentChart` — 6-month bar chart.
- **Existing Resources:** AuditLogResource, BorrowerResource, DepositRequestResource, LoanResource, OriginatorResource, TransactionResource, UserResource, WithdrawalRequestResource. **No SettingResource yet.**
- **Existing Pages:** only `ProcessRepayment.php`. F1 adds nothing here (will add a Filament *Resource* for settings, not a Page).
- **LoanResource table** already has a status filter and badge column with colors (green for funded/active, yellow for late, red for default, gray for repaid). F1 will add: filter by `is_late` quick toggle, sortable `days_late` column, "View late schedules" action.
- **AmortizationSchedulesRelationManager** under LoanResource — already shows status badges with `'danger'` color for `'late'` and `'default'`. So the moment we start writing those status values, the UI will paint them correctly **without any UI change for that view**.

⚠️ **Role gap:** Spec says "Edit-ваемо за super admin" for Settings page. The current `users.role` enum has only `'investor'` and `'admin'` — no super-admin. F1 will gate Settings on `role === 'admin'` (same as the rest of Filament) and flag this as a finding for the maintainer to decide if super-admin separation is needed before Phase F2.

---

### 6. Vue / API — what exists

- **PortfolioController** ([app/Http/Controllers/Api/PortfolioController.php](app/Http/Controllers/Api/PortfolioController.php)):
  - `GET /api/portfolio` — paginated list of investments with their loan + originator
  - `GET /api/portfolio/summary` — totals + breakdown by status (already groups loans by `active/funding/funded`, `late`, `default`, `repaid`)
- The summary response already contains `late_amount` and `default_amount` — **the API surface is partly ready**. F1 will add `days_overdue` per loan to the per-investment payload, and ensure the late count is bumped automatically as the new command runs.
- **LoanResource (API)** already includes `amortization_schedule` array with `principal/interest/total/status/due_date/paid_at`. F1 needs to expose `days_late` and `became_late_at` as new fields.
- **Vue page:** [resources/js/views/PortfolioPage.vue](resources/js/views/PortfolioPage.vue) already exists. F1 will add late/defaulted sections + status badges + days-overdue display. **Borrower anonymisation is already enforced** by `LoanResource` API resource which never exposes `borrower_id` or any PII.

✅ The investor API already supports anonymisation — F1 just adds new fields to the existing flow, no new exposure surface.

---

### 7. What is MISSING and must be created in F1

| Item | Status | Created in step |
|---|---|---|
| `platform_settings` table + model | ❌ does not exist | Step 1 (migration A) |
| `loans.last_late_check_at`, `loans.became_late_at` | ❌ does not exist | Step 1 (migration B) |
| `amortization_schedules.became_late_at`, `days_late` | ❌ does not exist | Step 1 (migration C) |
| `loan_events` table + model | ❌ does not exist | Step 1 (migration D) |
| `App\Services\Loans\LateDetectionService` | ❌ does not exist | Step 2 |
| `App\Services\Loans\LoanStatusUpdaterService` | ❌ does not exist | Step 2 |
| `App\Notifications\LoanWentLateNotification` | ❌ does not exist (related `LoanStatusChangedNotification` exists but is too generic) | Step 6 |
| `App\Console\Commands\Loans\ProcessLateLoans` artisan command | ❌ does not exist | Step 3 |
| Filament `LoanHealthStats` widget (extend existing or add new) | ❌ does not exist | Step 4 |
| Filament `PlatformSettingResource` | ❌ does not exist | Step 4 |
| Vue late/default UI on PortfolioPage | partly — sections need adding | Step 5 |
| Email template `loan-went-late.blade.php` | ❌ does not exist | Step 6 |
| Tests in `tests/Feature/Loans/` | ❌ does not exist | Step 7 |
| Schedule entry in `bootstrap/app.php` | ❌ command does not exist yet | Step 3 |

✅ **Already in place** (no work needed):
- Loan state machine + `transitionTo()`
- Schedule item status enum (`'late'`, `'default'` already declared)
- Notification table + Notifiable trait on User
- Queue + jobs table + `database` connection
- Filament panel with auto-discovery
- API LoanResource + PortfolioController + summary
- LoanResource Filament page already paints `late`/`default` rows correctly
- Audit trail via `Auditable` trait — Loan is already auditable, so `transitionTo` writes to `audit_logs` automatically. We will *additionally* write to a dedicated `loan_events` table because:
  - Audit logs capture *every* model change (noisy)
  - `loan_events` will be a focused, denormalised, append-only **lifecycle log** for the loan (went_late, recovered, buyback_triggered) — the type the operations team wants to see at a glance.

---

### 8. Spec ↔ code clarifications I will adopt unless told otherwise

These are decisions I will make to keep velocity. Each is reversible — if any is wrong, I'll back it out.

| # | Spec wording | Decision |
|---|---|---|
| 1 | "schedule_items / loan_schedule" | Will use existing `amortization_schedules` table. |
| 2 | "loan.status: pending, active, funded, repaying, completed, defaulted, late" | Will use actual enum: `draft, published, funding, funded, active, late, default, repaid`. |
| 3 | "transition from `repaying`" | Will treat `active` as the equivalent of "repaying". The state-machine already has `active → late`. |
| 4 | "super admin" for Settings page | Will gate on existing `role = admin`. Flagged as separate item for maintainer to decide on super-admin role. |
| 5 | "Last late check timestamp on dashboard" | Will pull from `platform_settings.last_late_check_run_at` (set by the command at end of run). |
| 6 | "Email template loan-went-late.blade.php" | Will use the **Notification** pattern (markdown mail), not a raw Mailable, so the in-app inbox + email both work. The actual blade view will still live at the spec'd path. |
| 7 | "Idempotency: running job twice in same day = same result" | Will achieve via `withoutOverlapping()` (file lock for the duration of the run) AND check on each event write that we haven't already logged it for the same `(loan_id, event_type, day)` tuple. |
| 8 | "loans:process-late at 03:00" | Spec text says 03:00 *and* "after nightly reconciliation". `ledger:reconcile` is at 03:00. Two commands at exactly 03:00 may race. F1 will schedule at **03:30** to guarantee ordering. |
| 9 | "Notify investors when their loan goes late" | Will queue notifications inside the command, NOT inline. Idempotency check: don't notify the same investor twice for the same loan-went-late event. |
| 10 | "Penalty interest: NONE for v1" | Confirmed. F1 writes no penalty math, no extra `interest` adjustment, no extra schedule rows. The `days_late` column is purely informational. |

---

### 9. Risks identified during discovery

These are not blockers, but I want to surface them now:

- **R1 — Recovery transition currently has no `from_status` constraint check.** The spec says "if all items are paid, transition late → repaying". There's no automatic detection in code today. F1 will add this *but* must be careful: a loan whose admin manually marked some installments as `paid` to fix a data error could be auto-recovered to `active` even though the borrower didn't actually pay. **Mitigation:** F1 will only auto-recover if **the schedule item that was previously late was just marked `paid`**, not if it was always paid. We achieve this by checking `paid_at >= became_late_at` semantics.

- **R2 — Time zone of `due_date`.** `due_date` is `date` (no time). "Today − 10 days" depends on the server's PHP timezone. F1 will use `Carbon::today()` (server date at server tz) and document this. The schedule was generated as `addDays(30·i)` from `now()` so all due_dates are coherent with server tz.

- **R3 — The spec mentions buyback hooks for Phase F2.** F1's `loan_events` table includes `buyback_triggered` as a documented event_type so Phase F2 doesn't need to migrate the schema. But F1 doesn't write that event yet.

- **R4 — F1 introduces email volume.** A single late loan with N investors generates N emails. A worst-case run with 50 active loans going late on the same day, each with ~10 investors, = 500 queued emails. Mailtrap dev sandbox handles this fine; production needs a transactional email provider with throughput. Flagged for ops handoff.

- **R5 — Notification opt-out / GDPR.** Investors today can't opt out of any notification. F1 doesn't change that. If post-launch we add a preferences page, this email type should be opt-in-only-for-suppression (i.e. opt-out for less critical, but late loans are material so default ON).

---

### 10. Outstanding clarifications I'd like before Step 1

If you can confirm these, Step 1 will go cleaner. Otherwise I will adopt the default in brackets.

1. **`grace_period_days` editable by admin = boolean toggle for `late_check_enabled`?** Default in spec. Confirmed; will allow 0–30 days range with admin audit log write on change. **[adopting]**

2. **Should `loan_events` include the inverse "recovered_from_late" event?** Spec mentions it. **[adopting yes]**

3. **Days-late counter on `amortization_schedules.days_late` — refresh strategy?** Two options:
   - Update the column daily for **every** late item → easy, modest write traffic.
   - Compute on read (`now() - due_date`) → no write, no stale data, but every read needs the calc.

   I'll **adopt option 1** (daily write, snapshot at command run time) because:
   - the column is shown in admin tables — sortable indexing benefits from a real value,
   - the value is what was observed at the moment the alert went out (audit-friendly),
   - "live current days_late" is `now()::date - due_date` — also queryable cheaply.

4. **Settings audit trail location.** `audit_logs` (existing, auto via `Auditable` trait) — apply trait to the new `PlatformSetting` model. **[adopting]**

5. **Dashboard widget placement.** Spec wants a separate widget for late/default counts. Two options:
   - Extend existing `StatsOverview` to add 2 more stats (it'd become 7 total).
   - Add a sibling `LoanHealthOverview` widget that focuses only on health.

   I'll **adopt option 2** (separate widget) because the existing widget mixes user/loan/financial stats and adding a critical "late count" stat among them would visually bury it. A focused "Loan health" widget gives it weight.

---

## Verdict — Step 0 complete

Discovery finished. No blockers. 4 migrations + 3 services + 1 command + 1 widget + 1 settings resource + 1 notification + 1 mail template + Vue updates + tests. About 25 files total, of which ~7 are tests.

**Awaiting approval to proceed to Step 1 (migrations).**
