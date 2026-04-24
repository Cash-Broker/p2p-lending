# Phase 3 — Business Logic & Lifecycle Audit

**Branch:** `feature/phase3-business-logic-audit` (from `main` at `8edc3ac`)
**Base commit:** `8edc3ac` (Phase 2 merged — 455 passed + 4 skipped main, 60 audit)
**Session start:** 2026-04-24
**Scope:** business rules coherence + lifecycle completeness. NOT math (Phase 2 done), NOT security (Phase 1 done).

**Status:** 🟡 WIP — Step 0 done, Step 1 in progress.

---

## Step table

| Step | Description | Status |
|---|---|---|
| 0 | Branch + scaffold this doc | ✅ |
| 1 | State machine integrity audit | 🟡 in progress |
| 2 | Authorization boundaries audit | ⏸ |
| 3 | Lifecycle completeness audit | ⏸ |
| 4 | Audit-trail coverage audit | ⏸ |
| 5 | Race-condition + data-integrity audit | ⏸ |
| 6 | Findings + fixes + finalize | ⏸ |

---

## §1 State machine integrity (Step 1)

### 1.1 `Loan::ALLOWED_TRANSITIONS` enumeration

```
draft        → [published]                                               (1)
published    → [draft, funding]                                          (2)
funding      → [funded]                                                  (1)
funded       → [active]                                                  (1)
active       → [late, repaid]                                            (2)
late         → [active, default, repaid, bought_back]                    (4)
default      → [repaid, bought_back]                                     (2)
repaid       → []                                     (terminal)         (0)
bought_back  → []                                     (terminal)         (0)
```

Total allowed transitions: **13** (not 15 — I miscounted initially).
Total state pairs (excluding self-transitions): 9 × 8 = 72.
Total forbidden transitions to verify: **72 − 13 = 59**.

### 1.2 Enforcement layers discovered

1. **`Loan::transitionTo()`** — explicit API, throws `InvalidArgumentException` on invalid transition.
2. **`booted()` `static::updating`** — catches any `->save()` that dirties the status column, even via `fill()` or direct property assignment. Throws `LogicException`.
3. **DB layer** — no CHECK constraint on `loans.status` enum; raw SQL `UPDATE` bypasses all app-level defense.

### 1.3 Side effects attached to transitions

- `funded → active` — triggers `AmortizationService::generateSchedule($loan)` inside the `DB::transaction` wrapping the status change. Coupling is intentional: schedule generation only makes sense at activation time.
- No side effects on other transitions (confirmed by reading `transitionTo` source).

### 1.4 Services that internally transition

| Service | Transitions called |
|---|---|
| `InvestmentService::invest` | `published → funding`, `funding → funded` (auto-cascade since Phase 2 Finding #1 fix) |
| `BuybackExecutionService::execute` | `late → bought_back`, `default → bought_back` |
| `EarlyRepaymentExecutionService::execute` | `active → repaid`, `late → repaid`, `default → repaid` |
| Filament `LoanResource` actions | `publish` (draft → published), `unpublish` (published → draft, only if funded=0), `activate` (funded → active) |
| Filament `LoanResource` form Select | admin-driven (shows `current + ALLOWED_TRANSITIONS[current]` options) |
| F1 cron `LoanStatusUpdaterService` | `active → late`, `late → active`, `late → repaid` (see CLAUDE.md F1) |

### 1.5 Initial findings (before test matrix run)

#### P3-F1 (LOW, cosmetic) — `bought_back` label missing from Filament `$allLabels`

[app/Filament/Resources/LoanResource.php:52-56](app/Filament/Resources/LoanResource.php:52-56) — the `$allLabels` lookup in the Status Select form field defines labels for 8 statuses (draft, published, funding, funded, active, late, default, repaid) but is MISSING `bought_back`. For a `late` loan, `ALLOWED_TRANSITIONS[late]` includes `bought_back`; the form iterates that list and looks up a label that doesn't exist. The fallback `$allLabels[$status] ?? $status` renders the literal key `"bought_back"` (untranslated, English) in the Bulgarian-UI dropdown.

**Impact:** cosmetic — admin sees `bought_back` instead of `Изкупен обратно` (or similar BG label) in one Select option. No functional failure. Form still saves correctly.

**Fix:** add `'bought_back' => 'Изкупен обратно'` to `$allLabels`.

#### P3-F2 (MEDIUM, lifecycle gap) — FUNDING loans have no abandon path

`ALLOWED_TRANSITIONS[funding]` = `[funded]` only. Once a loan receives its first investment and transitions `published → funding`, there is NO path back to `draft` or a cancelled state. If the loan never fully funds (remaining amount never attracts more investors), it stays in FUNDING forever.

**Impact:**
- Admin cannot cancel a partially-funded loan without writing raw SQL.
- Partial investors' funds are locked in `wallet.invested` with no path to recover them.
- Not a CRITICAL bug — no money is lost; admin could manually process partial repayments + originator-honoured buyback to close out. But the workflow is undocumented and relies on off-platform coordination.

**Evidence in code:**
- `LoanResource::unpublish` action visibility: `STATUS_PUBLISHED && funded_amount <= 0`. Deliberately not offered for FUNDING. Comment/decision unclear why.
- No "abandon" or "cancel" action anywhere in the codebase.

**Recommendations (v1.1+):**
- Option A: add `funding → draft` transition IF `funded_amount == 0` (trivial fix: admin can unpublish a funding loan that lost all investors, e.g. via withdrawals that were rejected).
- Option B: add a new `cancelled` status with an investor-refund execution service (mirrors buyback pattern — one-button cancel + pro-rata refund to investors). Bigger change.
- Option C: document the off-platform workflow (buyback with originator or manual repayment) as the expected recovery path.

Severity MEDIUM because: no money loss, but real-world operational pain if a FUNDING loan stalls.

#### P3-F3 (LOW, documentation) — Raw SQL bypass of transition guard

Enforcement is in `Model::updating` event (Eloquent). `DB::update()` / raw queries bypass the event entirely. No DB CHECK constraint on `loans.status` enum values either — only the string column exists.

**Impact:** an admin running raw SQL (or a future migration / seeder) could leave a loan in a state the model wouldn't permit (e.g. `draft → repaid` directly).

**Current state:** no known tooling or process does this; risk is purely "could happen if an admin goes off-procedure". Low.

**Mitigation option for v1.1:** add a DB CHECK constraint enforcing status transitions (requires a `last_status` column + trigger OR a status_history table). Complex for the value. More likely: document the constraint in a `database/schema/README.md`.

### 1.6 Test matrix plan

To exhaustively verify §1.1:

```
tests/Audit/Phase3StateMachineMatrixTest.php
  test_every_allowed_transition_succeeds        — 13 data-provider cases
  test_every_forbidden_transition_throws        — 59 data-provider cases
  test_terminal_states_reject_all_transitions   — 2 × 8 = 16 cases
  test_transition_via_fill_hits_updating_hook   — mass-assignment bypass attempt
  test_direct_property_assignment_caught        — direct $loan->status = X
  test_raw_db_update_NOT_caught                 — documents known gap (expected pass with dirty state)
```

Target: ~90 data-provider cases in one file; ~5 seconds runtime (lightweight — no external deps).

Post-matrix, additional scenarios for the §1 Other concerns (idempotency double-click, concurrent transitions) go in a separate test file if needed.

---

## §2–§5 — deferred until Step 1 complete

Populated in later steps.

---

## §6 Findings summary (WIP)

| # | Severity | Area | Status |
|---|---|---|---|
| P3-F1 | LOW | Filament `$allLabels` missing `bought_back` key | discovered Step 1; to bundle in Step 6 |
| P3-F2 | MEDIUM | FUNDING loan had no abandon path | **FIXED** Step 1 — Option A shipped. Partial-funded manual procedure documented in CLAUDE.md; `cancelled` status = v1.1 per DECISIONS.md P3-01 |
| P3-F3 | LOW | Raw SQL bypasses transition guard | documented gap; test `test_raw_db_update_bypasses_transition_guard_documents_known_gap` asserts the gap so future closure forces an audit review |
| P3-F4 | MEDIUM | `INVESTOR_VISIBLE_STATUSES` excluded DEFAULT — investor with position got 403 | **FIXED** (commit `0e4dd7a`). DEFAULT added; change-detector flipped from 403 to 200; also asserts /api/portfolio consistency. |
| P3-F5 | MEDIUM | `active → repaid` had no automation — cleanly-completing loans stuck ACTIVE | **FIXED** (this commit). Option 1 shipped: `LoanStatusUpdaterService::autoRepayCompletedLoans` + `loans:process-late` wire-up + new `last_late_check_auto_repaid` metric. 7 new tests pin the behaviour. DECISIONS.md P3-02 + CLAUDE.md F1 section updated. |
| P3-F6 | LOW-MEDIUM | FUNDED loan requires manual admin activation, no stale-loan alert | NEW Step 3. Documented. v1.1 observability dashboard. |
| P3-F7 | LOW | Withdrawal requests no auto-expiration / stale alert | NEW Step 3. Documented. v1.1 observability. |
| P3-F8 | LOW | InvestmentService transitions don't emit `loan_events` (investor timeline incomplete) | NEW Step 4. Change-detector test in place. v1.1 ~30 min. |
| P3-F9 | LOW | Filament admin publish/unpublish/activate don't emit `loan_events` | NEW Step 4. Change-detector test in place. v1.1 ~90 min. |

### §1 state machine — CLOSED

Matrix test results post-fix: **81 passed (276 assertions, 17s)**.
Covers 72 transition pairs + 2 terminal-state rejection sweeps + 4
enforcement-layer contract tests + 2 side-effect contract tests
(`funded → active` generates schedule; `late → active` does NOT).

Main test suite post-P3-F2 fix: **455 passed + 4 skipped, 0 regressions**.

---

## §2 Authorization boundaries (Step 2 — done)

### 2.1 Policy classes — reviewed 7 files

| Policy | View gate | Create gate | Notes |
|---|---|---|---|
| `LoanPolicy` | admin OR status in `INVESTOR_VISIBLE_STATUSES` | admin | **viewEvents** extra method gates on `user owns investment(s) in loan`. **BUT** `INVESTOR_VISIBLE_STATUSES` excludes `default` — see P3-F4. |
| `WalletPolicy` | own OR admin | — | clean |
| `InvestmentPolicy` | own OR admin | investor | clean |
| `TransactionPolicy` | own OR admin | — | controller filters by user_id |
| `DepositRequestPolicy` | own OR admin | investor | clean |
| `WithdrawalRequestPolicy` | own OR admin | investor | clean |
| `SavedIbanPolicy` | own only (no admin) | investor | admin deliberately excluded from IBAN visibility — by design |

### 2.2 Filament admin panel gating

Panel-level gate: `User::canAccessPanel()` returns `role === 'admin'`. Any non-admin hitting `/admin` gets rejected at the panel boundary before any resource or policy check. Confirmed via test `test_investor_cannot_access_filament_admin_panel`.

Individual Filament resources (LoanResource, BorrowerResource, UserResource, OriginatorResource, DepositRequestResource, WithdrawalRequestResource) do NOT define `canViewAny()` — and do not need to, because the panel gate already rejects non-admins.

Exceptions with explicit gates:
- `PlatformSettingResource` — canViewAny/canCreate/canDelete all gate on `isAdmin()`. Redundant given panel gate, but harmless defense-in-depth.
- `AuditLogResource` — canCreate returns false (read-only resource).
- `TransactionResource` — canCreate returns false (transactions are append-only).
- `FeesPage` — canAccess gates on `isAdmin()` again.

### 2.3 API authorization consistency

Swept all `app/Http/Controllers/Api/` for `$this->authorize(...)` calls:

| Endpoint | Gate | Notes |
|---|---|---|
| `GET /api/loans` | none (filters FUNDABLE_STATUSES in query) | LIST of publicly-listable loans — no per-record policy needed |
| `GET /api/loans/{loan}` | `authorize('view', $loan)` | ✓ |
| `GET /api/loans/{loan}/events` | `authorize('viewEvents', $loan)` | ✓ |
| `GET /api/transactions` | `authorize('viewAny', Transaction)` + user_id scope | ✓ |
| `GET /api/withdrawal` + `/history` | `authorize('viewAny', WithdrawalRequest)` / `Wallet` + user_id scope | ✓ |
| `GET /api/portfolio` + `/summary` | `authorize('viewAny', Investment)` + user_id scope | ✓ |
| `GET /api/dashboard` | `authorize('viewAny', Investment)` + user_id scope | ✓ |
| `DELETE /api/profile/ibans/{iban}` | `authorize('delete', $iban)` | ✓ |
| `POST /api/loans/{loan}/invest` | no authorize, middleware('kyc') + InvestmentService validation | ✓ |
| `GET /api/fees/config` | public, throttled | ✓ |
| `GET /api/health/scheduler` | public, throttled | ✓ |

Pattern: `authorize('viewAny', X)` + controller-side `->where('user_id', $request->user()->id)` scoping. The `viewAny` policy returns `true` for any authenticated user; actual row-level gating happens in the query. Consistent + correct across the 8 investor-facing controllers.

### 2.4 Borrower PII containment

`app/Models/Borrower.php`:
- `personal_id`, `address`, `phone` — cast as `encrypted`. Raw values invisible even via `$borrower->toArray()`.
- No API Resource class exists for `Borrower` (only `BorrowerAnonymizedProfileResource` is exposed).

`app/Http/Resources/LoanResource.php`:
- No `borrower_id` field.
- No direct Borrower serialisation.
- Only the anonymised profile (risk_class, region, loan_purpose, collateral_type, age_group).

`app/Http/Resources/BorrowerAnonymizedProfileResource.php`:
- 5 bounded fields. No PII.

Tests `test_loan_show_api_does_not_expose_borrower_pii` + `test_loans_index_api_does_not_expose_borrower_pii` assert the serialised JSON contains no `"borrower_id"`, `"personal_id"`, `"address"`, `"phone"`, or `"full_name"` strings anywhere in the payload. Both pass.

### 2.5 Findings

#### P3-F4 — MEDIUM — investor with position in DEFAULT loan receives 403

An investor who funded a loan that transitioned to DEFAULT (admin-manual per F1) cannot view the loan's detail page. `LoanPolicy::view` uses `INVESTOR_VISIBLE_STATUSES`, which lists 7 statuses but **excludes `default`**:

```php
const INVESTOR_VISIBLE_STATUSES = [
    STATUS_PUBLISHED, STATUS_FUNDING, STATUS_FUNDED,
    STATUS_ACTIVE, STATUS_LATE, STATUS_REPAID, STATUS_BOUGHT_BACK,
];
// missing: STATUS_DEFAULT
```

**Why it's a bug:** `/api/portfolio` (Investment-based query) returns the loan in the investor's list without status filtering. Clicking through to `/api/loans/{id}` returns 403. Investor sees a loan they're funding, tries to drill in, hits a wall. Confusing UX; no malicious bypass, just inconsistency.

**Evidence:** test `test_DEFAULT_loan_details_currently_rejected_for_position_holder_P3_F4` currently asserts `assertForbidden()` — documents the current (broken) state as a change-detector. When fix lands, flip to `assertOk()`.

**Fix (~1 minute):** append `self::STATUS_DEFAULT` to `INVESTOR_VISIBLE_STATUSES`. No other code depends on DEFAULT being excluded (verified by grep).

**Severity rationale:** MEDIUM — visible to investors, annoying UX, no money loss, no security risk. Per audit policy MEDIUM fixes in Step 5 pass.

### 2.6 Step 2 test coverage

`tests/Audit/Phase3AuthorizationTest.php` — 12 tests, 32 assertions, ~20s:

- 3 cross-user access prevention (investment / withdrawal / transaction)
- 2 loan-events policy coverage (insider allowed, outsider denied)
- 1 P3-F4 change-detector (currently expects 403)
- 2 borrower PII containment (show + index)
- 2 public endpoint shape (fees/config + health/scheduler)
- 2 Filament panel admin-only gating

**No new HIGH or CRITICAL findings in authorization.**

---

## §3 Lifecycle completeness (Step 3 — done)

### 3.1 Stuck-state analysis

Probed every non-terminal state for an exit-path guarantee. Three gaps surfaced:

#### P3-F5 — MEDIUM — `active → repaid` has no automation

An `ACTIVE` loan with all schedules paid stays `ACTIVE` forever. No code anywhere transitions `active → repaid` when the last installment completes. F1's `LoanStatusUpdaterService` only handles `active → late`, `late → active`, `late → repaid`. F3 early-repayment and F2 buyback both transition to terminal states, but for NORMALLY-completing loans there is no closure logic.

**Impact:** every cleanly-completing loan sits `ACTIVE` forever until admin manually transitions via the Filament form `status` Select. At 200+ loans/year, this is meaningful operational burden.

**Fix paths (ranked by effort):**
1. **Extend F1 `LoanStatusUpdaterService` + cron.** Add a third pass: iterate `active` loans, check if all schedules are paid, transition `active → repaid`. ~2 hours; already has the rule R1 tiebreaker pattern that does this for `late` loans.
2. **Hook onto `RepaymentService::processRepayment`.** After marking the schedule paid, check if any unpaid remain; if zero, `transitionTo(REPAID)`. Risk: wallet-invariant side effects if this throws mid-transaction. ~1 hour + careful testing.
3. **Admin dashboard reminder.** Daily "N loans fully paid, awaiting close" counter. No auto-close. Operational only. ~1 hour.

**Recommendation:** Option 1 — extend the existing cron. Matches the F1/F2 pattern of scheduled cleanup. Less risky than #2 (cron failures don't block live repayments).

**Regression test:** `test_P3_F5_active_loan_stays_active_even_after_all_schedules_paid` documents the current (broken) state. When Option 1 ships, this test's final assertion flips from `STATUS_ACTIVE` to `STATUS_REPAID`.

#### P3-F6 — LOW-MEDIUM — FUNDED loan requires manual admin activation (no reminder)

A loan reaches FUNDED with investors' money in their `invested` bucket but NO amortization schedule (only generated on `funded → active`). Admin must click "Активирай" in Filament for the loan to become ACTIVE and repayments to start. If admin forgets, the loan sits FUNDED indefinitely.

**Why NOT an outright bug:** admin activation is a deliberate gate — verify loan agreement signed, borrower identity confirmed, etc. Automating this would be a security/compliance regression.

**Gap:** no stale-FUNDED alert. An admin on vacation could leave funded loans in limbo for weeks.

**Fix (v1.1+):** admin dashboard widget showing "N loans FUNDED > 3 days ago". Optional email alert. ~1-2 hours.

#### P3-F7 — LOW — Withdrawal requests no auto-expiration

Investor submits withdrawal → `pending`, funds in `reserved`. Admin must approve/reject. No auto-expiration, no investor-side cancellation, no "pending > 3 days" alert.

**Acceptable at v1 scale** (single-admin, SLA < 1 business day). Phase 4 observability audit should add a pending-age counter.

### 3.2 FK integrity — confirmed RESTRICT semantics

- `investments.loan_id` → FK RESTRICT verified via `test_cannot_delete_loan_with_investments_fk_restrict`.
- `investments.user_id` → FK RESTRICT verified via `test_cannot_delete_user_with_investments_fk_restrict`.
- `depositrequests.user_id`, `withdrawalrequests.user_id` — same `->constrained()` default (RESTRICT).

**`AccountDeletionService`** enforces application-level invariants BEFORE reaching the FK layer: invested > 0 OR available > 0 OR reserved > 0 OR pending requests exist → ValidationException. For users that do pass these checks, it ANONYMISES rather than deletes, preserving all FK relations.

Verified: `test_account_anonymization_preserves_fk_integrity`.

### 3.3 KYC lifecycle

User registration flow: `pending` → submits → `submitted` → admin reviews → `approved | rejected`. No auto-approve/reject timeout.

`test_user_stuck_at_kyc_submitted_cannot_invest` confirms the `kyc` middleware blocks investments for unverified users. Not a bug — standard KYC flow. Phase 4 observability could add stale-KYC counter.

### 3.4 Step 3 coverage

`tests/Audit/Phase3LifecycleTest.php` — 8 tests, 14 assertions, ~12s:

- 2 P3-F5 documentation tests (stuck state + admin workaround)
- 1 P3-F6 documentation test (FUNDED without schedule)
- 1 P3-F7 documentation test (stale pending withdrawal)
- 3 FK integrity tests (loan/user delete restrict, anonymisation preserves FK)
- 1 KYC gate test

**No CRITICAL or HIGH findings in lifecycle.**

---

## §4 Audit trail coverage (Step 4 — done)

### 4.1 Auditable trait inventory

`app/Traits/Auditable.php` hooks Eloquent `created` / `updated` / `deleted` events. Applied to 9 models: Borrower, DepositRequest, Investment, Loan, PlatformSetting, Transaction, User, Wallet, WithdrawalRequest. Every change writes to `audit_logs` with `user_id` (`auth()->id()` — null for cron/system), `action`, `model_type`, `model_id`, `old_values`, `new_values`, `ip_address`, `user_agent`.

**Sensitive-field redaction**: password, remember_token, personal_id, iban, full_name, address, phone are replaced with `[REDACTED]` in old/new values before writing. Verified by `test_audit_log_redacts_sensitive_fields`.

### 4.2 Loan event writer inventory (investor-visible timeline)

| Writer | Event types |
|---|---|
| `LoanStatusUpdaterService::markLoanLate` | `went_late` |
| `LoanStatusUpdaterService::maybeRecoverLoan` | `recovered_from_late` |
| `LoanStatusUpdaterService::maybeAutoRepayLoan` (P3-F5 fix) | `status_changed` (auto-repay) |
| `DetectBuybackEligible` cron | `buyback_triggered` |
| `BuybackExecutionService::execute` | `buyback_completed` |
| `EarlyRepaymentExecutionService::execute` | `early_repayment_completed` |

`LoanEventResource` sanitises metadata via opt-in whitelist (`PUBLIC_METADATA_KEYS`) — unwhitelisted keys dropped silently. Admin-only keys (`triggered_by_user_id`, `executed_by_admin_id`, `late_schedule_count`, `originator_id`) intentionally excluded.

### 4.3 Loan transition audit gaps

#### P3-F8 — LOW — `InvestmentService` transitions have no `loan_events` row

`InvestmentService::invest` auto-transitions `published → funding` (first investment) and `funding → funded` (fully funded). **Only `audit_logs` is written** (via the Auditable trait on Loan); NO `loan_events` row.

**Impact:** investor-visible lifecycle timeline (`/api/loans/{id}/events`) does not show "funding started" or "fully funded" events. Investors infer from `funded_amount` reaching `loan.amount`. LOW severity — no data loss, UX completeness gap.

**Fix (v1.1, ~30 min):** emit `LoanEvent(status_changed, published→funding, system)` and `LoanEvent(status_changed, funding→funded, system)` inside `InvestmentService::invest`, after each `transitionTo`. Reuse existing `TYPE_STATUS_CHANGED` event type.

**Change-detector test:** `test_P3_F8_investment_service_transitions_do_NOT_emit_loan_event` currently asserts the gap. Flips on v1.1 closure.

#### P3-F9 — LOW — Filament admin `publish`/`unpublish`/`activate` have no `loan_events` row

Same pattern as P3-F8 but for the Filament admin-triggered transitions. Admin clicks "Публикувай" → loan goes `draft → published` — audit_logs gets a row (admin user_id), but `loan_events` stays empty. Same for `unpublish` and `activate`.

**Impact:** investor timeline silent on publish/unpublish/activate. Admin-facing audit_logs captures everything, so compliance is intact; only the investor-side narrative is incomplete.

**Fix (v1.1, ~30 min per action, 3 actions):** emit `LoanEvent(status_changed, X→Y, admin, triggered_by_user_id=auth()->id())` in each Filament action closure.

**Change-detector test:** `test_P3_F9_filament_admin_transitions_do_NOT_emit_loan_event` pins the current gap.

### 4.4 Admin login / logout logging

[app/Listeners/SendAdminLoginAlert.php](app/Listeners/SendAdminLoginAlert.php) — listens for `Login` event, emails admin on every login with "known IP" vs "new IP" distinction + trust-this-IP signed link. Rate-limited (11+ logins/hour collapses to 2 emails). Documented in DECISIONS.md 2FA-deferral entry.

Logout / session events — no dedicated listener; session cleanup relies on Laravel defaults. Not a gap for v1 scale.

### 4.5 Wallet mutations → transaction rows (ledger integrity)

Every `WalletService` mutation that moves money creates a `Transaction` row with the appropriate `type` (deposit/withdrawal/investment/repayment_principal/repayment_interest/buyback_principal/buyback_interest/early_repayment_principal/early_repayment_interest/fee). `Transaction` rows are immutable (append-only triggers + model override throws `LogicException` on update/delete).

**Exceptions (not a finding, documented):**
- `WalletService::reserve` / `releaseReservation` — move funds between available and reserved buckets. **No transaction row.** Reservation state tracked via `WithdrawalRequest.status`. Acceptable because reserved is a "hold" not a ledger event; the Transaction row is written at approval time (`debitReserved`).
- This is consistent with the virtual-ledger model (DECISIONS.md F4-01).

### 4.6 Configuration change auditing

`PlatformSetting` uses the Auditable trait. Every `set()` / direct `update()` call writes to `audit_logs`. Verified by `test_platform_setting_update_writes_audit_log`.

### 4.7 Buyback dismiss / reactivate auditing

`Loan` uses Auditable. Dismiss fields (`buyback_dismissed_at`, `buyback_dismissed_reason`, `buyback_dismissed_by`) are tracked via model updates → `audit_logs` captures old + new. Reactivate clears all three — same mechanism.

**Compounded coverage:** the `buyback_dismissed_by` FK (RESTRICT on users.id) ensures the acting admin is identifiable even years later from the `Loan` row alone, without needing `audit_logs` at all.

### 4.8 Step 4 test coverage

`tests/Audit/Phase3AuditTrailTest.php` — 7 tests, 14 assertions, ~9s:

- 4 positive coverage (PlatformSetting, Wallet, Loan transition, User password redaction)
- 2 change-detector negatives (P3-F8 InvestmentService, P3-F9 Filament)
- 1 AuditLog immutability

### 4.9 Step 4 findings summary

| # | Severity | Area |
|---|---|---|
| P3-F8 | LOW | InvestmentService transitions lack `loan_events` — investor timeline incomplete |
| P3-F9 | LOW | Filament admin transitions (publish/unpublish/activate) lack `loan_events` — investor timeline incomplete |

No CRITICAL, no HIGH, no MEDIUM in Step 4. Both LOW findings are completeness gaps (compliance is intact via `audit_logs`); fix is low-effort v1.1 (~1.5 hours total).

---

## §5 Final fix pass + merge prep (Step 5 — done)

### 5.1 P3-F1 one-line label fix

`app/Filament/Resources/LoanResource.php` — added `'bought_back' => 'Изкупен обратно'` to the `$allLabels` dictionary in the Status Select form. For a `late` loan, the Select now offers `bought_back` as a proper Bulgarian label instead of the raw status key. Cosmetic only; no functional change.

### 5.2 Final audit test count

Phase 3 added 5 test files:

| File | Tests | Focus |
|---|---|---|
| `tests/Audit/Phase3StateMachineMatrixTest.php` | 81 | Exhaustive 72-pair transition matrix + terminal rejection + enforcement layers + P3-F2 abandon guard |
| `tests/Audit/Phase3AuthorizationTest.php` | 12 | Cross-user data access, PII containment, public endpoint shape, Filament panel admin-only |
| `tests/Audit/Phase3LifecycleTest.php` | 8 | Stuck-state probes (P3-F5/F6/F7 documentation), FK integrity, KYC lifecycle |
| `tests/Audit/Phase3AutoRepayTest.php` | 7 | P3-F5 auto-repay regression guards |
| `tests/Audit/Phase3AuditTrailTest.php` | 7 | Auditable trait positive coverage + P3-F8/F9 change-detectors + AuditLog immutability |

**Total Phase 3 tests:** 115. **Audit suite total:** 175 (60 Phase 2 + 115 Phase 3).

### 5.3 Main suite regression check

```
php artisan test --exclude-testsuite=Audit
Tests: 4 skipped, 455 passed (1300 assertions)
```

Zero regressions across F1–F5 baseline through all Phase 3 work.

---

## §6 Final findings summary

| # | Sev | Area | Status | Commit |
|---|---|---|---|---|
| P3-F1 | LOW | Filament `$allLabels` missing `bought_back` key | **FIXED** Step 5 | (this commit) |
| P3-F2 | MEDIUM | FUNDING had no abandon path; partial investors could be stranded | **FIXED** Step 1 + CLAUDE.md manual procedure + DECISIONS.md P3-01 v1.1 ticket for automated `cancelled` status | `c325e39` (model + resource) / `0e4dd7a` (bundled Loan.php) |
| P3-F3 | LOW | Raw SQL bypasses `booted()` transition guard | Documented gap + change-detector test; DB CHECK = v1.1 consideration | `c325e39` |
| P3-F4 | MEDIUM | `INVESTOR_VISIBLE_STATUSES` excluded DEFAULT — holding investor got 403 | **FIXED** Step 2 + change-detector flipped from 403 to 200 with /api/portfolio consistency assert | `0e4dd7a` |
| P3-F5 | MEDIUM | `active → repaid` had no automation — every cleanly-completing loan stuck ACTIVE | **FIXED** Step 3 — new `LoanStatusUpdaterService::autoRepayCompletedLoans` pass in `loans:process-late` cron + `last_late_check_auto_repaid` metric + DECISIONS.md P3-02 | `0822f16` |
| P3-F6 | LOW-MED | FUNDED requires manual activation, no stale-loan alert | Documented; v1.1 observability dashboard candidate | — |
| P3-F7 | LOW | Withdrawal pending never expires / no stale alert | Documented; v1.1 observability | — |
| P3-F8 | LOW | `InvestmentService` transitions (published→funding, funding→funded) don't emit `loan_events` | Documented + change-detector; v1.1 fix ~30 min | — |
| P3-F9 | LOW | Filament `publish`/`unpublish`/`activate` don't emit `loan_events` | Documented + change-detector; v1.1 fix ~90 min | — |

**Severity tally:**
- CRITICAL: **0**
- HIGH: **0**
- MEDIUM: **3** (P3-F2, P3-F4, P3-F5) — **ALL FIXED** in-phase
- LOW-MEDIUM: **1** (P3-F6) — deferred to v1.1
- LOW: **5** (P3-F1 fixed, P3-F3/F7/F8/F9 deferred or documented)

**In-phase fixes:** 4 (P3-F1, P3-F2, P3-F4, P3-F5).
**v1.1 deferred:** 5 (P3-F3 doc-only, P3-F6, P3-F7, P3-F8, P3-F9).

Matches the pre-audit expectation (0 CRITICAL, 0-1 HIGH, 1-2 MEDIUM, 1-3 LOW) at the MEDIUM upper bound and slightly exceeds LOW count — expected for a broad lifecycle + audit-trail sweep.

---

## §7 v1.1 follow-up commitments

Consolidated from Phase 3 findings for the v1.1 planning document:

1. **[MEDIUM] Automated loan `cancelled` status + `CancelRefundExecutionService`** (P3-F2 extended).
   Mirrors F2 buyback pattern. Enables clean abandon of partial-funded loans with pro-rata refund. 1–2 days.
   DECISIONS.md P3-01 trigger conditions.

2. **[LOW-MED] Stale-lifecycle dashboard** (P3-F6, P3-F7).
   Filament admin widget surfacing counters:
   - Loans in `funded` > 3 days (P3-F6)
   - Withdrawal requests pending > 3 days (P3-F7)
   - `last_late_check_auto_repaid` monthly delta (P3-F5 observability)
   Phase 4 observability audit candidate. ~2 hours.

3. **[LOW] Emit `loan_events` on all transitions** (P3-F8, P3-F9).
   - InvestmentService: 2 transitions, ~30 min
   - Filament publish/unpublish/activate: 3 transitions, ~90 min
   - Whitelist `published_at`, `funded_at` metadata keys in LoanEventResource
   Total: ~2 hours to close both findings.

4. **[LOW] DB CHECK constraint on `loans.status`** (P3-F3 optional).
   Would close the raw-SQL bypass gap. Non-trivial (requires either a status-history table or trigger-based CHECK). Defer unless a raw-SQL incident surfaces.

---

## §8 Pre-deploy checklist

Phase 3 merge adds:

1. **No new migration** — all changes are code-level. `php artisan migrate:status` unchanged.
2. **No composer/npm changes** — skip `composer install` / `npm run build`.
3. **New platform_metric key** — `last_late_check_auto_repaid`. Populated on first `loans:process-late` cron run post-deploy. External `/api/health/scheduler` monitoring picks it up automatically (flat field under last_run_stats if Phase 4 surfaces it there; for now, it's internal).
4. **Cron behaviour change** — `loans:process-late` now runs 3 passes instead of 2. Expect slightly longer runtime (negligible — auto-repay query is a single SELECT per active loan). Dry-run + --loan=ID flags honoured.
5. **Manual verification post-deploy:**
   - Filament: open a `late` loan in edit — Status Select dropdown now shows "Изкупен обратно" (not `bought_back`).
   - Filament: find a `default` status loan — admin-only, should still be visible.
   - Investor UI: if any test-account holds a position in a `default` loan (unlikely in prod), confirm loan detail page loads (P3-F4).
   - Cron: run `php artisan loans:process-late --dry-run --detail` — output line `auto-repaid: 0 completed active loan(s)` should appear after the existing transitions line.
6. **No public API shape change** — Vue SPA continues to render identically.

---

## §9 Step completion order

```
c325e39  audit(phase3): complete P3-F2 surface — LoanResource unpublish extension + state machine matrix test
0822f16  fix(f1): extend LoanStatusUpdaterService with auto-repay for completed active loans (P3-F5)
0e4dd7a  fix(loan): add default status to INVESTOR_VISIBLE_STATUSES
<this>   audit(phase3): finalize - label fix + audit report complete + DECISIONS pass
```

Base: `8edc3ac` (Phase 2 final).

---

## §10 Executive summary

Phase 3 surfaced **9 findings** across state machine integrity, authorization boundaries, lifecycle completeness, and audit trail coverage. Zero CRITICAL, zero HIGH — the F1–F5 audit discipline paid off. **All 3 MEDIUM findings fixed in-phase**; the 5 LOW + 1 LOW-MED either closed or routed to v1.1 with clear trigger conditions.

**Biggest operational win:** P3-F5 auto-repay. Cleanly-completing loans now self-close via the daily cron instead of waiting for admin manual intervention. At 200–500 loans/year, this removes a persistent admin-attention requirement. Mirrors the existing F1 rule R1 tiebreaker pattern — well-understood, well-tested.

**Biggest audit-posture observation:** the platform has STRONG defense-in-depth throughout — 3-layer transition enforcement (`transitionTo`/`booted` hook/model-level canTransitionTo), 9 models with Auditable trait, Transaction append-only + DB triggers, LoanEventResource opt-in whitelist, Filament panel admin-only gate, FK RESTRICT everywhere, AccountDeletionService anonymising rather than deleting. The LOW findings are completeness gaps, not security holes.

**v1.1 focus** should be the observability dashboard (P3-F6 + P3-F7 + clamp-frequency from Phase 2) — a single Filament widget surfacing stale-state counters would close 3 findings at once and support the operator's day-to-day monitoring.

**Phase 3 closed.** Ready for Phase 4 (Infrastructure Audit).

---

*End of Phase 3 Business Logic & Lifecycle Audit report.*
