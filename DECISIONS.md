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

## F4-01: Fees — virtual-ledger accounting, no platform-wallet bucket

- **Date:** 2026-04-24
- **Decision:** Withdrawal fees (and any future fee category) are
  recorded as `Transaction::TYPE_FEE` rows on the INVESTOR's account.
  The fee transaction debits the investor's `available` wallet bucket.
  There is NO corresponding credit to a platform-owned wallet — the
  fee simply "leaves the ledger" from the investor's perspective.
- **Real-world mapping:**
  - Admin verifies the withdrawal externally, keeps the fee portion in
    the business bank account, wires the NET amount (`requested -
    fee`) to the investor's IBAN.
  - Platform ledger records two transactions in the same DB transaction:
    `TYPE_WITHDRAWAL` (full amount out of reserved bucket) +
    `TYPE_FEE` (fee out of available bucket).
  - Admin's bank statement is the source of truth for accumulated
    platform fee revenue — reconciliation reports sum `TYPE_FEE` rows
    per period for cross-checking only.
- **Rationale:**
  - No dedicated "platform wallet" model in v1. A platform bucket
    would require a new Wallet row with `user_id = null` (breaking the
    NOT NULL FK), OR a dedicated owner-user account — either shape is
    an invasive schema change for a single-tenant ledger.
  - Virtual-ledger consistency: the platform is already an accounting
    mirror of admin's bank account (see HANDOFF_F4.md §1 "Virtual
    money model"). Fees follow the same pattern — the ledger records
    the debit; the real money sits in admin's bank.
  - Reconciliation is still possible: `SELECT SUM(amount) FROM
    transactions WHERE type = 'fee' AND created_at BETWEEN ?` gives
    the period fee accrual for audit vs. bank statement.
- **Implementation shape (F4 Step 2+):**
  - `Transaction::TYPE_FEE` is the ledger marker (constant exists
    since F1; F4 makes it live).
  - `WalletService::debit()` is the entry point — already type-agnostic
    so no signature change; F4 Step 2 introduces a thin `FeeService`
    that reads platform_settings, calculates the flat amount, and
    calls `WalletService::debit(..., Transaction::TYPE_FEE, ...)`
    inside the caller's DB::transaction.
  - `reference` column on the fee transaction links to the parent
    (e.g. `withdrawal_request:{id}:fee`) for cross-referencing in
    reconciliation and support workflows.
- **Feature-flag shape (per Q8 — per-category flags, not master):**
  - `fees_withdrawal_enabled` (bool, default false) — master toggle
    for the withdrawal category only.
  - `fees_withdrawal_amount` (float, default 2.50, CHECK 0–100).
  - Future categories (origination, service, late, early-repayment,
    inactivity) each get their own `fees_<category>_enabled` +
    parameter rows in their own migrations. No single master flag —
    per-category toggles allow gradual adoption without all-or-nothing.
- **Public copy:**
  - FAQ (`FaqSection.vue`) + chatbot (`ChatbotWidget.vue`) were
    amended in commit `f301c19` to "Платформата в момента не
    начислява такси" — accurate for the default-off state.
  - When `fees_withdrawal_enabled` flips to true, the copy must be
    updated to describe the concrete fee (e.g. "2.50 € при теглене").
    The FeesPage admin form carries a reminder banner to that effect.
- **Trigger conditions for revisiting:**
  - Multi-tenant expansion (distinct platform operators per tenant) —
    would require per-tenant fee accounting, at which point a
    platform-wallet model becomes worth the schema cost.
  - Regulatory requirement to show fee income as its own ledger
    account (some jurisdictions require fiduciary vs. operating
    account separation at the ledger level).
  - Reconciliation workflow friction — if the admin reports difficulty
    cross-checking fee accruals vs. bank statements, add a dedicated
    `platform_wallets` table in v1.1.
- **Owner of follow-up:** Backend lead (on any of the above triggers).
- **Effort estimate to introduce platform wallet:** 2-3 days —
  new model + migration (nullable user_id with CHECK, OR distinct
  platform_id column), new `TYPE_FEE` semantics (debit investor +
  credit platform), reconciliation query updates, test coverage.

---

## F4-02: Filament Page over Resource for singleton fee config

- **Date:** 2026-04-24
- **Decision:** The admin-facing fee configuration surface is a
  Filament **Page** (`app/Filament/Pages/FeesPage.php`), not a
  Filament **Resource**. Uses `platform_settings` rows as the
  backing store — no dedicated `fees` table and no `Fee` model.
- **Rationale:**
  - A Resource requires a backing Eloquent model. With fee config
    stored as 2 rows in the existing `platform_settings` table,
    the Resource options were:
    - (a) A sham single-row "FeeConfig" model mapping onto platform_
      settings rows via custom accessors — hacky.
    - (b) `PlatformSetting` Resource scoped to fee keys only —
      duplicates the existing `PlatformSettingResource` with a
      narrower filter.
    Both fight Filament's design.
  - Filament `Page` is the idiomatic primitive for singleton config
    screens. Precedent in this codebase: `BuybackQueue.php`,
    `ProcessRepayment.php`.
  - User outcome identical: "Такси" navigation item under **Финанси**,
    purpose-built form fields, admin-only access.
- **Audit trail unchanged:** `PlatformSetting` has the `Auditable`
  trait; every save routes through `update()` → writes to
  `audit_logs` with old/new value, admin id, IP, UA.
- **Trade-off:** slightly more boilerplate than a Resource (own
  Blade view + form setup), but avoids a sham model. A dedicated
  `fees` table becomes worth the cost only when per-tenant or
  per-originator fee overrides land — then FeeResource on the new
  model replaces the Page.
- **Trigger conditions for revisiting:**
  - Multi-tenant expansion — per-tenant fee schedules need a real
    table.
  - Per-originator fee overrides — same.
  - Fee history / versioning — if operators need to know "what was
    the fee amount on 2027-03-15" a `fees_history` or time-indexed
    table becomes necessary; at that point a Resource on the history
    model fits naturally.
- **Owner of follow-up:** Backend lead (on triggers above).

---

## F4-03: /api/fees/config response shape — nested by category

- **Date:** 2026-04-24
- **Decision:** The public `GET /api/fees/config` endpoint returns
  a nested-by-category JSON payload:
  ```json
  {
    "withdrawal": {
      "enabled": false,
      "amount": "2.50"
    }
  }
  ```
  NOT a flat top-level `{enabled, amount}`.
- **Rationale:**
  - v1 wires only the withdrawal category. v1.1+ will add
    origination / service / late / early-repayment / inactivity.
    Nested shape accommodates them with `{enabled, amount}` per
    category added as a new top-level key — ZERO breaking change
    for existing SPA consumers.
  - A flat top-level shape would force v1.1 to EITHER:
    - Version the endpoint (`/v2/fees/config`) — churn.
    - Break v1 consumers — no.
    - Rename fields awkwardly (`withdrawal_enabled`,
      `origination_enabled`, …) — loses grouping, hurts
      readability.
  - Amount is a STRING (normalised 2-decimal via `number_format`),
    not a float — keeps the wire format exactly matching what
    bcmath produces server-side.
- **No API Resource wrapper:** payload is derived from
  `platform_settings` rows, not models. Using a Resource for a
  non-model payload adds ceremony without value. Mirrors
  `SchedulerHealthController` direct `JsonResponse` precedent.
- **Public access:** no auth. Fee schedule is public-ish info
  already advertised on the landing FAQ + chatbot. Rate-limited
  60/min to deflect abuse. Matches `/api/health/scheduler` policy.
- **Contract guard:** `FeesConfigApiTest::test_amount_is_always_
  returned_as_normalised_two_decimal_string` pins the format —
  stored "5" surfaces as "5.00" on the wire. If this test fails,
  SPA's `parseFloat + toFixed` could break.
- **Trigger conditions for revisiting:**
  - If the platform ever introduces category-level sub-configuration
    (e.g. per-originator withdrawal fees), the per-category block
    would grow fields (`{enabled, amount, overrides}`) — additive,
    still no breaking change.
  - If wire-format floats become desirable (unlikely in a bcmath
    codebase), would require a `/v2` endpoint.
- **Effort estimate to add a category:** ~30 minutes per category —
  `FeeService::CATEGORIES` entry + 2 platform_settings rows +
  one line in `FeeController::__invoke`.

---

## F5-01: APR calculation — nominal pass-through in v1, IRR path deferred

- **Date:** 2026-04-24
- **Decision:** Phase F5's `APRCalculatorService::calculate()` returns
  `number_format((float) $loan->interest_rate_annual, 2, '.', '')` —
  a pass-through of the stored nominal borrower rate, formatted to
  two decimals. No IRR solver, no approximation formula, no mid-term
  recalculation.
- **Rationale:**
  - **Mathematical correctness under no-fee regime.** For a vanilla
    annuity loan with no borrower-side fees, the nominal rate IS the
    EU CCD APR by definition. The APR is the Xa that solves
    `Σ Ck × (1+X)^(-tk) = Σ Dl × (1+X)^(-sl)` (Directive 2008/48/EC
    Annex I); when fees are zero, the solution collapses to X =
    nominal rate. Pass-through is therefore exact, not an
    approximation.
  - **F4 scope confirms the no-fee regime.** F4 shipped
    withdrawal-fee infrastructure only — investor-side, charged at
    admin-approval time on withdrawals. No borrower-side fees exist
    today. Origination / service / late / inactivity fee categories
    are placeholder-ready in `FeeService::CATEGORIES` but not
    implemented.
  - **Handoff-formula correction.** The client's F5 brief proposed
    `APR = (Total Cost − Principal) / Principal × 12 / term_months
    × 100`. This is the *simple flat rate* formula, not APR. For a
    10 000 € / 10% / 12-month annuity it returns 5.50% vs the true
    APR of 10.00% — roughly half the nominal rate, because flat
    ignores amortizing balance. Using that formula would display
    legally-non-compliant ГПР values. Nominal pass-through avoids
    the error entirely.
- **Upgrade path — IRR solver when borrower-side fees activate:**
  - Signature stays: `calculate(Loan $loan): ?string`. Callers never
    change.
  - Internals switch to Newton-Raphson on the EU CCD equation.
    bcmath-based (scale ≥ 10 for rate iterations; final answer
    rounded to 2 decimals).
  - Trigger: any new row in `FeeService::CATEGORIES` that represents
    a borrower-side fee (origination, service, late, inactivity).
  - Effort estimate: 1 day for solver + 0.5 day for test fixtures
    (known APR targets from regulator example calculators).
- **Dual-display decision (Q1c):**
  - Both "Доходност" (investor yield from `interest_rate`) AND "ГПР"
    (borrower cost from `interest_rate_annual`) are shown to
    investors on the loan detail page. Distinct labels; distinct
    columns; distinct helper text.
  - Rationale: "Доходност" has been the investor-facing rate since
    F1 and investors have mental anchors around it. Replacing it
    with "ГПР" breaks expectations. ADDING "ГПР" as a second
    transparency data point preserves the existing signal AND
    discloses the borrower's cost for due diligence — aligned with
    EU CCD philosophy even though this platform has no borrower UI.
  - F1-L6 activation: `interest_rate_annual` was "metadata only"
    pre-F5. F5 makes it load-bearing. The column is `NOT NULL
    decimal(5,2)` at the DB layer, so no production row can be
    missing. A defensive null/zero guard in APRCalculatorService
    returns null → UI renders "—" instead of "0.00%".
- **Admin-only marge visibility:**
  - Filament LoanResource admin detail shows `(interest_rate_annual
    − interest_rate)` as "Марж" — the originator's spread per loan.
    Surfaces profit-margin visibility for the operator ("клиентката")
    without exposing it to investors.
  - Rationale: the operator needs to see spread at a glance for
    pricing decisions; investors don't need it and it could invite
    irrelevant bargaining. Admin-only matches the existing
    AuditLogResource admin-only discipline.
  - Displayed as a read-only Placeholder (not a form field — it's
    derived, never stored).
- **Precision:** 2 decimals. Matches the `decimal:2` cast on both
  `interest_rate` and `interest_rate_annual`. Matches EU CCD
  disclosure norms (common-practice for consumer-credit disclosure
  documents). Storing 2 decimals on the wire avoids float-
  roundtripping errors between PHP and JS.
- **Live recalculation (no snapshot column):**
  - APR computed on-the-fly via `$loan->apr()`. Memoized
    per-instance (cheap now, future-proofs the IRR case).
  - No `apr_at_activation` column. Rationale: APR is deterministic
    given loan parameters — snapshot would only make sense if
    regulation required "APR as disclosed on day X" preservation.
    This platform has no such requirement today. Revisit if Bulgarian
    CCD enforcement adds preservation obligations.
- **Trigger conditions for revisiting:**
  - First borrower-side fee activates in `FeeService::CATEGORIES` —
    switch to IRR solver.
  - Regulator demands APR-at-disclosure preservation — add
    `apr_at_activation` column + snapshot on the `funded → active`
    transition.
  - Originator spread becomes a negotiated quantity per loan
    (rather than platform-set) — marge display moves from admin-
    only to originator-visible.
- **Owner of follow-up:** Backend lead (on triggers above).

---

## P2-01: Pro-rata last-investor-remainder drift — clamping patch for v1

- **Date:** 2026-04-24
- **Decision:** `WalletService::creditAvailableFromInvested` clamps
  `wallet.invested` at `0.00` when pro-rata drift would push it below
  zero. The full requested amount still credits to `wallet.available`
  normally. A `Log::warning` fires on every clamp event + two
  `platform_metrics` counters (`last_prorata_clamp_fired_at`,
  `prorata_clamps_total`) update for ops visibility. Surfaced by
  Phase 2 Financial Correctness Audit (see AUDIT_REPORT_PHASE2.md
  §3 Finding #2).
- **Root cause.** The last-investor-remainder pro-rata pattern —
  shared by `RepaymentService`, `BuybackCalculationService`,
  `EarlyRepaymentCalculationService` — guarantees `Σ distributions ==
  installment_total` per-installment exactly. It does NOT guarantee
  `Σ per-investor distributions across ALL installments ==
  investor.invested`. Non-last investors receive `floor(share)`
  (systematically under-distributing); the last investor
  systematically over-absorbs to preserve the per-installment total.
  Over N installments, the last investor's cumulative principal
  returned exceeds their original invested amount by 1–2 stotinki.
  The wallet UPDATE then pushes `invested` below zero, violating the
  `chk_wallets_invested_non_negative` DB CHECK — which rolls back the
  ENTIRE `DB::transaction`, leaving the repayment/buyback/early-
  repayment unable to complete.
- **Historical evidence.** The bug was already biting F3 tests
  pre-audit (`storage/logs/laravel.log` 2026-04-24 04:03:25 and
  04:04:49: "Early repayment execute failed unexpectedly ...
  `invested = -0.02`"). Phase 2 was the first systematic discovery
  + fix.
- **Why clamp rather than proper fix for v1:**
  - **Scale.** v1 launch target is 50–100 investors, 200–500 loans
    first year. Realistic clamp rate projection: ~5–10 events/month
    (multi-investor loans with uneven ratios ≈ 30% × 200 active
    loans × ~1 clamp per lifecycle ÷ 12 months ≈ 5). At 0.02 EUR
    per event → ~0.10–0.20 EUR/month of platform-side drift.
    Pessimistic upper bound (200 events/month → 4 EUR/month) still
    materially insignificant at launch scale.
  - **Timeline.** Proper fix (cumulative-aware pro-rata) is a 2–3
    day redesign of three services' distribution logic. Clamp is a
    ~20-line localised patch that unblocks production immediately.
  - **Reversibility.** Clamp is additive. When v1.1 replaces it
    with cumulative-aware distribution, clamp can stay as defence-
    in-depth or be removed — no migration, no data-fix.
  - **Money conservation at aggregate.** Platform money IS
    conserved: every clamp offsets one investor's over-credit
    against another investor's under-credit within the same loan.
    Individual wallets drift ≤ 0.02 EUR; platform aggregate is
    preserved within that tolerance.
- **Trade-offs accepted:**
  - **Per-investor drift.** Individual wallets may carry ±0.02 EUR
    discrepancies between transaction-reconstructed balance and
    stored wallet balance after a clamp fires. Detectable via
    reconciliation scripts; not money loss, but visible to any
    sufficiently-zoomed audit.
  - **Cumulative platform drift.** Each clamp event adds ≤ 0.02
    EUR to the `Σ wallets` == `Σ deposits − withdrawals − fees +
    interest-in` invariant. Phase 2 audit sets scenario tolerance
    at 0.50 EUR; realistic monthly projection at v1 scale is
    < 0.20 EUR (pessimistic < 5 EUR).
  - **Reconciliation complexity.** Any wallet-vs-transactions
    reconciliation script must mirror the clamp logic to avoid
    false-positive alerts.
- **Compensating controls (all implemented in this commit):**
  - Enhanced `Log::warning` on every clamp event with `user_id`,
    `loan_id` (parsed from `reference` when available),
    `requested_amount`, `drift_absorbed`, `reconciliation_id`
    (UUID per event) for audit trail.
  - `PlatformMetric::record('last_prorata_clamp_fired_at', now())`
    — timestamp for "is this still happening?" monitoring.
  - `PlatformMetric::record('prorata_clamps_total', count)` —
    monotonic counter incremented per clamp; monthly delta
    computable from archived metric snapshots. Read-modify-write
    is acceptable at v1 scale (single-admin manual workflow, no
    concurrent writers).
  - Regression tests assert the new clamped behaviour
    (`BuybackExecutionServiceTest`, `EarlyRepaymentExecutionServiceTest`).
  - Phase 2 audit suite (`--testsuite=Audit`) re-run gate on any
    change to pro-rata logic (contract test).
- **v1.1 upgrade path — cumulative-aware pro-rata:**
  - Track per-investor cumulative distribution within each loan.
  - At the LAST unpaid installment, compute each investor's final
    principal share as `(investor.invested − already_returned)`
    rather than pro-rata floor. Guarantees
    `Σ per-investor == invested` exactly.
  - Alternative: Hamilton's largest-remainder method — distribute
    floor shares to everyone, then give the remaining pennies to
    investors with the largest fractional residues (also
    eliminates drift structurally).
  - Either approach eliminates the drift source; clamp logic can
    be removed or kept as defence-in-depth.
  - **Effort estimate:** 2–3 days (algorithm + test fixtures
    extension + clamp removal + regression tests + AUDIT_REPORT
    update).
- **Trigger conditions for revisiting (any ONE brings v1.1 forward):**
  - `prorata_clamps_total` growth > 1 event per week in production.
  - Any investor complaint about balance not matching transaction
    history (clamp leaking into user-visible UX).
  - Scale crosses 500 loans OR 200 investors (drift becomes
    materially visible at year-end reconciliation).
  - Regulator audit question about the 0.02 EUR discrepancy.
  - Any reconciliation-script false-positive attributable to
    unbounded clamp accumulation (ops burden).
- **Owner of follow-up:** Backend lead. External ticket to file:
  "Pro-rata redesign (cumulative-aware distribution) — replace
  P2-01 clamp with structural fix" — referenced by this P2-01
  entry and AUDIT_REPORT_PHASE2.md §8 v1.1 follow-up commitments.

---

## P3-01: FUNDING loan abandon — v1 manual, v1.1 automated `cancelled` status

- **Date:** 2026-04-24
- **Decision:** v1 ships Option A (minimal fix): `funding → draft`
  transition allowed when `funded_amount == 0`, blocked when > 0.
  Partial-funded abandon remains a documented manual procedure
  (CLAUDE.md "Partial-funded loan abandon"). v1.1 implements a
  dedicated `cancelled` status + pro-rata refund execution service
  (Option B from the Phase 3 audit findings).
- **Rationale:**
  - **v1 scale.** Estimated ≤ 1 partial-funded stall per quarter
    at launch volume (50–100 investors, 200–500 loans/year).
    Manual per-investor refund through Filament Транзакции is
    tedious but bounded and auditable.
  - **v1.1 trigger conditions (any ONE brings the ticket forward):**
    - Any real-world partial-funded stall case.
    - Scale crosses 500 loans — accumulated edge cases become
      material operational load.
    - Regulator question about refund workflow auditability.
  - **Effort estimate for v1.1 Option B:** 1–2 developer days.
    Scope: new `Loan::STATUS_CANCELLED` state (new ALLOWED entry
    from funding), `CancelRefundExecutionService` mirroring
    `BuybackExecutionService` (pro-rata refund with
    last-investor-remainder), Filament action + modal, investor
    notification, tests, CLAUDE.md update.
- **Compensating controls in v1:**
  - Model-level guard (`booted()` updating hook) throws
    `LogicException` if anyone tries `funding → draft` with
    `funded_amount > 0`. Admin cannot skip the refund step even
    via raw Filament form.
  - Filament "Спри" (unpublish) action visibility extended to
    include FUNDING with `funded_amount == 0` — immediate clean-up
    path for the common zero-funded-after-rejected-withdrawal case.
  - Documented manual procedure in CLAUDE.md step-by-step.
  - Regression tests assert both the happy path (0 funded → draft
    succeeds) and the guard (>0 funded → throws) in
    `Phase3StateMachineMatrixTest`.
- **Trade-offs accepted:**
  - **Admin burden.** Manual per-investor refund entries are
    tedious for scenarios with ≥ 5 investors. Infrequent at v1
    scale.
  - **Audit-trail shape.** Refunds appear as
    `TYPE_REPAYMENT_PRINCIPAL` with a distinguishing reference
    suffix (`:refund:`), NOT a dedicated `TYPE_REFUND` — re-uses
    the existing type to avoid a migration. Reconciliation
    scripts can filter by reference.
  - **Reversibility.** Once admin runs raw SQL on
    `funded_amount`, there's no automated rollback. Mitigated by
    documenting the exact sequence.
- **Owner of follow-up:** Backend lead. Ticket to file externally:
  "Phase 3 v1.1: cancelled status + CancelRefundExecutionService".
  Referenced by this entry and AUDIT_REPORT_PHASE3.md.

---

## P3-02: Auto-close cleanly-completing active loans

- **Date:** 2026-04-24
- **Decision:** `LoanStatusUpdaterService` gains
  `autoRepayCompletedLoans()` — a third pass in the daily
  `loans:process-late` cron (03:30). Iterates `status=active` loans,
  transitions any whose schedules are all `paid` to `repaid`.
  Mirrors the late-recovery rule R1 tiebreaker pattern and writes a
  `loan_event(status_changed, active→repaid, triggered_by=system)`
  with `metadata.auto_transitioned=true` and
  `metadata.transition_reason='all_schedules_paid'` for audit.
- **Root cause (Phase 3 P3-F5):** F1's late automation only handles
  `active → late`, `late → active`, `late → repaid`. For loans that
  complete cleanly WITHOUT ever going late, there is no
  `active → repaid` logic anywhere. Every normally-completing loan
  sits ACTIVE forever until admin manually transitions via the
  Filament status Select. At 200–500 loans/year at v1 scale, this is
  real operational burden.
- **Why cron rather than hook onto RepaymentService:**
  - **Transaction isolation.** Cron failure on a single loan does not
    block live repayments. Hooking into `RepaymentService` would
    couple cleanup to the critical path.
  - **Pattern reuse.** F1 rule R1 tiebreaker already transitions
    `late → repaid` when all schedules paid at recovery moment.
    Extending the existing cron with a parallel pass is consistent
    and low-risk.
  - **Audit trail.** Cron runs emit `loan_events` with
    `triggered_by=system`, distinguishing automatic closes from
    manual admin closes. RepaymentService-triggered closures would
    mask this distinction unless extra metadata is carried through.
- **Safeguards (belt + braces):**
  - `lockForUpdate` on the loan row — prevents race with manual
    admin actions or concurrent cron runs.
  - Re-check `status === active` inside the transaction.
  - Reject if `schedule_count == 0` (F3-L2 data-inconsistency
    edge — active-without-schedule; logs a warning so ops see it).
  - Reject if any schedule is NOT in `paid` status (defensive
    against `late` / `default` / `pending` leaking through).
- **Dry-run support:** honours the existing `--dry-run` wrapper on
  `loans:process-late`. `--loan=ID` restricts the pass to a single
  target. `--detail` emits per-loan output.
- **Observability:** new `platform_metrics` counter
  `last_late_check_auto_repaid` on every cron run. External
  monitoring via `/api/health/scheduler` picks it up at the next
  audit of the health endpoint response shape (Phase 4 candidate).
- **Trade-offs accepted:**
  - **Cron-delayed close.** A loan's final repayment at 15:00 on
    day X closes officially at 03:30 on day X+1. Acceptable
    latency — investor's money already landed in wallet on the
    repayment; status flip is cosmetic/reporting. If this ever
    matters for UX, hook option remains available.
  - **Uses generic `status_changed` event type.** No dedicated
    enum slot for "auto-close on completion"; fallback is
    semantically correct (all state changes without a specific
    event type go through this). If analytics need to distinguish
    auto-close events from other status changes, filter on
    `metadata.auto_transitioned=true AND metadata.transition_reason
    ='all_schedules_paid'`.
- **Trigger conditions for revisiting:**
  - If auto-close latency ever causes a support ticket, hook into
    `RepaymentService::processRepayment` instead (inline close on
    last-installment mark-paid).
  - If `last_late_check_auto_repaid > 20/day` sustained — volume
    indicates this is a high-frequency operation worth optimising.
  - If a dedicated `TYPE_LOAN_COMPLETED` event type becomes
    useful for reporting dashboards — add via migration.
- **Owner of follow-up:** Backend lead.

---
