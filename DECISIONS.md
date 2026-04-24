# Security Decisions Log

A running log of security-related architectural decisions, including
deferrals, trade-offs, and the compensating controls put in place.
Append new entries at the bottom; never edit prior entries (record
remediations as a new entry that references the old one).

---

## 2FA on Filament admin panel — DEFERRED to v1.1

- **Date:** 2026-04-16
- **Decision:** 2FA / MFA will not be implemented for the admin panel in v1.
- **Finding addressed:** [HIGH-1 in AUDIT_REPORT_PHASE1.md](AUDIT_REPORT_PHASE1.md)
- **Rationale:**
  - The admin team is currently very small (≤ 2 people).
  - Picking and integrating a 2FA flow (TOTP via filament-breezy / a custom
    Fortify integration / WebAuthn) cleanly with Filament 5.4 requires more
    than a one-line config change — schema migration, recovery codes,
    setup flow on first login, "trust this device" handling.
  - The blast radius of a compromised admin account is mitigated by the
    compensating controls below; a leaked password without the second
    channel will trigger an email alert that the admin can act on.
- **Compensating controls in place (v1):**
  - **Email alert on every admin login** (`42aa178`): Subject distinguishes
    known IP from new IP; new-IP emails carry a "trust this IP" signed link.
    Failed logins do not generate alerts (attackers cannot inbox-spam).
    Rate-limited so a legit burst of 11+ logins/hour from one IP collapses
    to two emails (initial + consolidated). Listener in
    `app/Listeners/SendAdminLoginAlert.php`.
  - **Strong password policy** enforced via `Password::defaults()` in
    `AppServiceProvider`: 8+ chars, mixed case, numbers, symbols.
  - **Audit logging** of every admin action via the `Auditable` trait on
    User, Wallet, Transaction, Investment, Loan, Borrower (all PII /
    financial models). PII fields redacted in stored audit values.
  - **Session hardening**: `SESSION_SECURE_COOKIE=true`, `SameSite=strict`,
    encrypted (`SESSION_ENCRYPT=true`), JSON serialisation (no PHP gadget
    chain risk).
  - **Login throttle** (5 attempts per email+IP per minute via
    `LoginRequest::ensureIsNotRateLimited`).
- **Trigger conditions for revisiting (any of):**
  - Admin team grows beyond 2 people.
  - Any external pen-test or auditor finding flags 2FA absence.
  - First reported security incident touching an admin account.
  - Before the v1.1 release, regardless of the above.
- **Owner of follow-up:** Backend lead.
- **Effort estimate:** 1-2 day spike to evaluate
  `stephenjude/filament-two-factor-authentication` vs. a Fortify-based
  build, then 2-3 days to ship including recovery codes, setup flow,
  and tests.

---

## CSP roll-out — Report-Only first, enforce after production soak

- **Date:** 2026-04-16
- **Decision:** Ship the CSP in `Content-Security-Policy-Report-Only`
  mode (commit `367d9c8`) instead of enforcing immediately.
- **Finding addressed:** [MED-4 in AUDIT_REPORT_PHASE1.md](AUDIT_REPORT_PHASE1.md)
- **Rationale:** A strict policy that breaks Filament's inline-script
  injection would lock admins out of the panel — operations disaster.
  Report-Only lets browsers log violations to console + `report_uri`
  (config: `CSP_REPORT_URI`) without breaking pages. Promotion to the
  enforcing `presets` array happens once production logs show zero
  legitimate violations across:
  - login + logout
  - Filament admin panel (every resource, every action)
  - Vue SPA navigation, dashboard, portfolio
  - KYC upload flow
  - investment + withdrawal flows
- **Promotion procedure:** Move `App\Support\CspPolicy::class` from
  `report_only_presets` to `presets` in `config/csp.php` and redeploy.
- **Owner of follow-up:** Frontend lead + backend lead jointly.
- **Trigger:** After 7 consecutive days of zero CSP violation reports
  in production logs.

---

## Admin role consolidation for v1 (Phase F1)

- **Date:** 2026-04-23
- **Decision:** All admin-side capabilities (Filament panel access,
  PlatformSettingResource read/write, loan operations, KYC approval,
  withdrawal approval, repayment posting) are gated on a single
  `users.role = 'admin'` value. No "super admin" / "support" / "viewer"
  separation in v1.
- **Why:** The Phase F1 spec asked for "Edit-ваемо за super admin" on
  the Settings page. We do not have a super-admin role in the schema,
  and adding one in F1 would have meant: a new role enum value, new
  policy methods on every Resource, role assignment UI, migration of
  existing admin users — all unrelated to the late-detection feature
  being built.
- **Compensating controls in place:**
  - Every PlatformSetting save creates an `audit_logs` row via the
    Auditable trait — who, what, when, IP, user-agent.
  - Email-on-admin-login alert (Phase 1 fix `42aa178`) — every Filament
    login fires an email with known/new IP distinction; settings changes
    by an unexpected admin are visible the same day.
  - Settings have minimal range — `grace_period_days ∈ 0..30` is enforced
    at form, model, AND DB CHECK levels; toggling `late_check_enabled`
    only pauses automation (manual override available via
    `php artisan loans:process-late --force`).
- **Trigger conditions for revisiting (any of):**
  - Admin team grows beyond 3 people.
  - Compliance asks for a "support read-only" role.
  - Before v1.1 release, regardless of the above.
- **Effort estimate:** 1 day (enum + migration + Resource gates) once
  the role taxonomy is decided.

---

## F3: No new loan status for early-repaid (marker-timestamp pattern)

- **Date:** 2026-04-23
- **Decision:** Early-repaid loans reach `status='repaid'` (existing
  terminal state) with `loans.early_repaid_at` as a nullable timestamp
  distinguishing them from normally-completed ones. NO new enum value
  (no `STATUS_EARLY_REPAID`). Mirror of F2's `bought_back` pattern where
  a new status was needed because it has semantically different
  consequences; F3 does NOT introduce a new status because the terminal
  outcome from investor / borrower perspective is identical to a
  normally-closed loan (loan fully paid, money returned).
- **Rationale:**
  - State-machine simplicity: no new transitions, no UI status-badge
    label update across 4 surfaces (Filament table/form, Vue
    PortfolioPage, Vue InvestmentDetailPage, notification copy).
  - Reuse existing `active/late/default → repaid` transitions without
    modification.
  - Timestamp markers are sufficient for distinguishing outcomes:
    `early_repaid_at IS NOT NULL` → closed by borrower early;
    `early_repaid_at IS NULL AND status = 'repaid'` → closed via
    scheduled completion.
  - Idempotency + state validation surface both cases with
    differentiated error messages (see
    `EarlyRepaymentExecutionService::execute`).
- **Compensating controls:**
  - `EarlyRepaymentExecutionService` has TWO rejection paths for
    `status='repaid'`:
    * `early_repaid_at !== null` → `EarlyRepaymentAlreadyExecuted
      Exception` (benign idempotency hit → warning toast).
    * `early_repaid_at === null` → `InvalidArgumentException`
      ("already repaid through scheduled completion; cannot early-
      repay a finalised loan").
  - Filament visibility gate on the row action hides it when
    `early_repaid_at !== null` (no double-execute UI path).
  - Tests pin both branches
    (`EarlyRepaymentExecutionServiceTest::test_idempotency_*` and
    `::test_repaid_through_normal_completion_*`).
- **Trigger conditions for revisiting:**
  - If investor UX demands aggregating "early-paid" separately from
    "normally-paid" in portfolio views (currently both fall under the
    single "Изплатен" bucket).
  - If regulators require the distinction in financial statements.
- **Effort estimate to migrate to a new status:** ~2 days — enum
  value, new ALLOWED_TRANSITIONS entry, update 4+ UI surfaces.

---

## F3: Single-UPDATE batching pattern (forceFill + transitionTo)

- **Date:** 2026-04-23
- **Decision:** When an execution service needs to stamp multiple
  columns alongside a status transition (e.g. `early_repaid_at` +
  `early_repayment_amount` + `status`), the service calls
  `$loan->forceFill([col1, col2])` to set the dirty state, then
  `$loan->transitionTo(NEW_STATUS)` which invokes `save()` internally
  — ALL dirty attributes persist in ONE UPDATE statement. Same pattern
  that F2's `BuybackExecutionService` established (`bought_back_at` +
  `status`).
- **Rationale:**
  - **ONE `audit_logs` row per business event** — `Auditable` trait
    snapshots diff on each save. Splitting into two saves (one for the
    column, one for the status) would create two audit rows with
    partially overlapping diffs, misleading forensic replay.
  - **Atomic state snapshot** — admin reading the loan between the two
    saves could see an inconsistent intermediate state
    (`early_repaid_at` set but `status` still `active`). One UPDATE
    eliminates that window.
  - **Performance** — one UPDATE vs two is a minor win but real.
- **Compensating controls:**
  - Empirical test pins the pattern: `EarlyRepayment
    ExecutionServiceTest::test_single_update_query_for_early_repaid_at
    _and_amount_and_status` asserts exactly ONE UPDATE on `loans`
    containing all three column names. Parallel test exists for F2 at
    `BuybackExecutionServiceTest::test_single_update_query_for_bought
    _back_at_and_status`. A refactor that inadvertently splits the save
    will fail one of these tests before hitting production.
- **Trigger conditions for revisiting:**
  - If a service needs to invoke side effects that require the status
    to be committed FIRST (then a second UPDATE stamps columns). In
    that case, use two explicit saves with the rationale documented.
- **Implementation note:** `Loan::transitionTo()` calls
  `$this->forceFill(['status' => $newStatus])->save()` internally
  (see `app/Models/Loan.php:123-124`). Laravel's `save()` persists ALL
  currently-dirty attributes, so any `forceFill` CALLED BEFORE
  `transitionTo` adds its fields to the same UPDATE.

---

## F3: Early repayment — schedule-boundary interest (NO day-count accrual)

- **Date:** 2026-04-23
- **Decision:** Early-repayment total is computed as
  `outstanding_principal + sum(unpaid scheduled.interest through the
  current installment boundary)`. No mid-month day-count accrual, no
  precision beyond scheduled-installment granularity.
  Borrower pays via a single admin-triggered action; amount is
  computed fresh at Execute-click.
- **Rationale:**
  - Consistency: mirrors F2 buyback's "scheduled interest only"
    calculation. Same `Σ unpaid schedule.interest` pattern; shared
    code paths via parallel service structure.
  - Codebase has zero day-count math today (no deposits/savings with
    daily accrual, amortization is 30-day-month schedule-based).
    Introducing day-count for one feature = new math domain + new
    risk surface + new tests.
  - Risk minimisation: familiar math > precise math for v1. Avoids
    leap-year, DST, time-zone edge cases.
  - Borrower overpayment vs "ideal" day-count is at worst ~half a
    scheduled installment's interest (~5 EUR average per case).
    Overpayment flows through to investors as extra distribution —
    not lost, not retained by the platform.
  - v1.1 upgrade path is clean: adding day-count later would not
    require architectural rewrite, just a new calc strategy + migration
    for an `interest_policy` column if per-loan config is desired.
- **Business-facing T&C language:**
  > "При предсрочно погасяване, кредитополучателят плаща главница
  > + цялата лихва до следващата планирана вноска."
- **Compensating controls:**
  - Admin verifies the amount in the Filament confirmation modal
    BEFORE clicking Execute (fresh calc at modal open, not cached).
  - `loan_events(early_repayment_completed)` metadata records the
    exact calculation inputs (outstanding_principal, scheduled_interest_
    through_boundary) for audit replay.
  - `loans.early_repayment_amount` denormalised column stores the
    total at execution time — supports admin reports without joining
    event metadata.
  - Tests cover: on-time close (happy path), late-loan close (past
    unpaid installment interest included), default-status close
    (rare but state-machine-allowed — no-op from F3 perspective,
    same calc as active).
- **Trigger conditions for revisiting (any of):**
  - Regulators require daily interest accrual precision.
  - Per-loan `interest_policy` becomes a product requirement.
  - Significant complaint volume from borrowers about the
    "installment-boundary overpayment".
- **Effort estimate to switch to day-count:** 2-3 days —
  `EarlyRepaymentCalculationService` becomes strategy-pattern,
  add `30/360` (or `actual/365`) strategy, one platform_settings
  row for convention choice, test coverage extension.

---

## F2: bought_back is a TERMINAL state

- **Date:** 2026-04-23
- **Decision:** `Loan::STATUS_BOUGHT_BACK` has ZERO outgoing transitions
  in `Loan::ALLOWED_TRANSITIONS`. Once a loan is bought back, it cannot
  return to `repaid`, `active`, or any other state.
- **Rationale:**
  - Investors have been paid out in full (principal + whatever interest
    the coverage type covers); the platform owes them nothing further.
  - The originator's post-buyback collection effort — whether the
    borrower ultimately pays, restructures, or defaults externally — is
    OFF-PLATFORM. Any funds recovered by the originator after buyback
    stay with the originator; investors have already received their
    compensation.
  - A `bought_back → repaid` path would blur the semantic for investors
    reading loan history ("did the originator or the borrower pay me?"),
    and would require splitting already-distributed funds into
    "buyback advance" vs "final repayment" — out of scope for v1.
- **Compensating controls:**
  - `INVESTOR_VISIBLE_STATUSES` includes `bought_back` so the terminal
    outcome surfaces in portfolio views.
  - `LoanBoughtBackNotification` tells the investor explicitly that
    the originator honoured buyback, distinguishing from
    `RepaymentReceivedNotification` (borrower paid).
- **Trigger conditions for revisiting:**
  - If regulators require treating originator-sourced repayments as
    a separate accounting category requiring reversal paths.
  - If a secondary market feature needs to model "bought-back shares
    being resold".

---

## F2: Manual admin execution model (not automatic buyback)

- **Date:** 2026-04-23
- **Decision:** The daily cron only DETECTS eligibility and alerts
  admins. Actual buyback execution is triggered manually by admin
  click on the Filament Buyback Queue page. No cron-driven money
  movement.
- **Rationale:**
  - Originators are metadata on the platform — no balance tracking,
    no API integration. Admin verifies the originator has actually
    paid (off-platform bank transfer) BEFORE executing.
  - Adding automation would require an `originator_balances` table
    + top-up workflow + reconciliation. Large scope increase for v1.
    The admin-in-the-loop model defers this to v1.1+.
  - The cron's role is to make sure eligible loans don't slip
    through the cracks of manual oversight — it nudges admin with
    a daily digest.
- **Compensating controls:**
  - Daily `loans:detect-buyback-eligible` cron at 03:45 with admin
    email digest keeps the queue visible.
  - Filament Buyback Queue navigation badge surfaces the pending
    count at-a-glance on every admin panel visit.
  - `BuybackExecutionService::execute(int $loanId, int $adminId)`
    records the executing admin in `loan_events.triggered_by_user_id`
    AND in the event metadata as `executed_by_admin_id` —
    accountability trail.
  - Every buyback is wrapped in `DB::transaction` with
    `Loan::lockForUpdate()` — no race condition between two admins
    clicking Execute on the same loan.
- **Trigger conditions for revisiting:**
  - If admin team grows to 5+ and operational overhead becomes
    material, or if originator count grows past ~10.
  - If regulators require SLA on buyback execution time.
- **Effort estimate:** 3-5 days to ship full automation —
  originator_balances schema + admin top-up UI + automated
  balance-check at execution + buyback_check_enabled auto-mode
  toggle.

---

## F2: Buyback coverage — 2 options only (no day-count accrued interest in v1)

- **Date:** 2026-04-23
- **Decision:** `originators.buyback_coverage` enum permits exactly
  two values: `principal_only` or `principal_plus_interest`. No third
  `principal_plus_accrued_interest` option.
- **Rationale:**
  - "Principal + interest" sums the SCHEDULED interest of unpaid
    installments — predictable, deterministic, easy to explain to
    investors.
  - A third option would add day-count accrued interest from last
    payment date to execution date. Requires picking a day-count
    convention (30/360, actual/365, actual/actual — a business
    decision with regulatory implications that has not been made).
  - Fewer code paths to test, simpler calculation service,
    unambiguous coverage labels in investor-facing copy.
- **Compensating controls:**
  - DB CHECK constraint on `originators.buyback_coverage` enforces
    the two-value enum (defense-in-depth against future SQL-injection
    or careless seed edits).
  - Whitelist in `LoanEventResource` exposes `coverage_type` to
    investors so they know exactly which formula was applied.
- **Trigger conditions for revisiting:**
  - If client expands into a jurisdiction where borrower payments
    accrue interest daily and investors demand that precision.
  - If a competitor offers the third option and it becomes a
    marketing differentiator.
- **Effort estimate:** 1 day to add the third option — new enum
  value in migration, calculator branch using a picked day-count
  convention, test coverage.

---

## F2: Dismiss accountability — buyback_dismissed_by FK required

- **Date:** 2026-04-23
- **Decision:** When admin dismisses a loan from the Buyback Queue,
  THREE columns are stamped: `buyback_dismissed_at` (timestamp),
  `buyback_dismissed_reason` (required textarea, 5-255 chars at the
  Filament form level), AND `buyback_dismissed_by` (FK to users.id,
  RESTRICT on delete). All three are cleared on Reactivate.
- **Rationale:**
  - Multiple admins may eventually share the platform. Without a
    dismissed_by signature, a bad-dismiss decision leaves no
    attribution — "anonymous admin action" is unacceptable in a
    financial platform.
  - Requiring a reason forces the admin to think before dismissing
    and leaves a plain-text audit note for the next reviewer.
  - Three columns (not just one flag) lets the UI render a full
    "dismissed on DATE by ADMIN — reason: TEXT" summary without
    cross-table joins.
- **Compensating controls:**
  - FK RESTRICT on delete mirrors `loan_events.triggered_by_user_id`
    — AccountDeletionService anonymises rather than hard-deletes,
    so RESTRICT never trips in normal flow.
  - Auditable trait on Loan captures the full diff on each
    dismiss/reactivate.
  - Reactivate is idempotent — clearing all three columns returns
    the loan to the active queue; the dismiss history lives in
    `audit_logs` for forensic replay.
- **Trigger conditions for revisiting:**
  - If compliance requires a soft-delete audit trail where dismissed
    rows persist (instead of being cleared on Reactivate).

---

## F2: Scheduler health — worst-of-two with disabled-ignored semantic

- **Date:** 2026-04-23
- **Decision:** `/api/health/scheduler` top-level `status` is the
  WORST of `late_check.status` + `buyback_check.status`, but a
  scheduler with its `*_check_enabled` platform setting set to
  `false` is EXCLUDED from the computation. Both disabled → overall
  `healthy`.
- **Rationale:**
  - Ops may deliberately disable one or both schedulers during data
    fix-ups or during known maintenance windows. A critical status
    during a deliberate pause would generate false-positive pages
    for on-call and erode trust in the monitor.
  - A single-URL single-`status` contract is what UptimeRobot /
    Healthchecks.io / Pingdom expect; splitting into per-scheduler
    endpoints would multiply monitor configs.
  - Preserving F1's flat top-level fields (instead of restructuring
    under a `late` block) means F1-era monitors keep working
    without reconfiguration — backwards-compatible extension.
- **Compensating controls:**
  - Dashboard widget (`LoanHealthOverview`) surfaces BOTH
    `Последна late-проверка` AND `Последна buyback-проверка`
    independently with their own staleness colours, so admins see
    the per-scheduler state at a glance even when the overall is
    healthy-due-to-disabled.
  - 7 test cases in `SchedulerHealthEndpointTest` pin the
    worst-of-two + disabled-ignored behaviour (healthy+critical,
    critical+healthy, both healthy, disabled+healthy, both disabled,
    buyback block structure, backwards-compat flat fields).
- **Trigger conditions for revisiting:**
  - If ops notice "silent disabled scheduler" incidents (someone
    toggled off and forgot), consider adding a separate "disabled"
    status as warning-level for dashboards while keeping the
    healthy signal for pagers.

---

## F2: default → bought_back transition allowed

- **Date:** 2026-04-23
- **Decision:** `Loan::ALLOWED_TRANSITIONS` permits both `late → bought_back`
  AND `default → bought_back`. `bought_back` itself is terminal (no outgoing
  transitions).
- **Rationale:** Manual admin model requires flexibility for late-stage
  originator recovery agreements. An admin may have transitioned a loan
  `late → default` (F1 limitation L1 — `default` is currently only reachable
  via admin Filament action), then weeks later the originator signs a
  buyback agreement. Forcing the admin to go `default → late → bought_back`
  would be artificial and would require extending the state machine with
  `default → late` (which doesn't make semantic sense). Instead, direct
  `default → bought_back` is a rare but valid path.
- **Compensating controls:**
  - The daily `loans:detect-buyback-eligible` cron flags loans in `late`
    AND `default` status (both are valid buyback candidates when they
    have a `became_late_at` history). `bought_back` is reached ONLY via
    admin-click Execute from the Buyback Queue — detection never
    transitions; only surfaces eligibility for admin review.
  - Every transition writes a `LoanEvent` row with `triggered_by='admin'`
    + `triggered_by_user_id` pinpointing the admin who executed.
  - Audit trail via `Auditable` trait on `Loan` captures the full diff.
- **Trigger conditions for revisiting:**
  - If the platform expands into markets where `default` status means
    legal recovery only (buyback impossible by regulation).
  - If compliance requires separating "delinquent" from "written off"
    semantics more strictly.
- **Owner of follow-up:** Backend lead (if the above conditions ever trigger).

---

## SEPA-only IBANs

- **Date:** 2026-04-16
- **Decision:** Reject IBANs from non-SEPA countries (US, AE, SA, TR, …)
  at validation rather than at the bank rails.
- **Finding addressed:** [MED-2 in AUDIT_REPORT_PHASE1.md](AUDIT_REPORT_PHASE1.md)
- **Rationale:** The platform settles in EUR over SEPA. A non-SEPA
  withdrawal is 99%+ either fraud (laundering routing) or a user error.
  Bank-side rejection wastes operational time and clutters forensics;
  failing fast at the API layer keeps the audit trail clean.
- **Trigger to revisit:** New regulated jurisdiction expansion (e.g.
  UK separately, US partnership), at which point the SEPA whitelist in
  `App\Rules\ValidIban::SEPA_COUNTRIES` should be revisited.

---
