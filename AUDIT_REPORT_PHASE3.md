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

## §4–§5 — pending (Steps 4, 5)

_Populated during subsequent steps._
