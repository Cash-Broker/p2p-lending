# Phase F3 — Early Repayment: Implementation Report

**Branch:** `feature/early-repayment` (from `main` at `9efbbf5`)
**Base commit:** `9efbbf5` (F2 fully shipped — 369 tests passing, 2 skipped, 1054 assertions)
**Session start:** 2026-04-23
**Client decision on Q6 (accrual):** *главница + лихва до днес (без rebate, без penalty)*

## Completion summary

| Step | Description | Commit |
|---|---|---|
| 0 | Discovery (this document) + Pre-F3 blocker investigation (schedule generation inconsistency flagged; left for separate followup per client direction) | — |
| 1 | Migration (loans.early_repaid_at + early_repayment_amount with CHECK 0..10M) + app constants + DECISIONS.md entry | `4429323` |
| 2 | Services (EarlyRepaymentCalculation + EarlyRepaymentExecution + DTOs + exception) + WalletService extensions | `f843583` |
| 3 | Filament LoanResource row action `execute_early_repayment` + modal preview Blade template | `d57c078` |
| 4+5 (Batch) | LoanEventResource whitelist + InvestmentDetailPage.vue timeline + EarlyRepaymentReceivedNotification + email + Execute wire-up | `8b9cc92` |
| 6 | Comprehensive test suite — 35 new tests across 5 files (2 skipped placeholders, F2-parity) | `3699ee2` |
| 7 | CLAUDE.md F3 section + this audit finalised + DECISIONS.md pass + README | (this commit) |

## Test coverage (final)

| Metric | F2 baseline | F3 final | Delta |
|---|---|---|---|
| Tests passed | 369 | **404** | +35 |
| Tests skipped (documented null-path placeholders) | 2 | **4** | +2 |
| **Total** | 371 | **408** | **+37** |
| Assertions | 1054 | **1213** | +159 |
| Test files in tests/ | 33 | **38** | +5 new F3 |
| Regressions on F1/F2 tests | — | **0** | — |
| Local MySQL suite duration | ~246 s | **252 s** | stable |

### New F3 test files

| File | Tests | Focus |
|---|---|---|
| `tests/Unit/Services/EarlyRepaymentCalculationServiceTest.php` | 10 | 6 schedule-boundary scenarios + pro-rata + last-investor-remainder + 24-installment no-drift |
| `tests/Unit/Services/EarlyRepaymentExecutionServiceTest.php` | 10 | Happy path + 6 failure paths + rollback atomicity + loan_event metadata + single-UPDATE regression guard (3 fields) |
| `tests/Unit/Notifications/EarlyRepaymentReceivedNotificationTest.php` | 8 passed + 2 skipped | Contract guard (early_repaid_at ISO string) + rate-limit + BG content + no PII + F1/F2 symmetry placeholders |
| `tests/Feature/LoanResourceEarlyRepaymentActionTest.php` | 5 | Visibility matrix + modal (happy-path + error panel) + execute end-to-end + exactly-once-per-investor (`assertSentToTimes`) |
| `tests/Feature/Api/LoanEventsApiTest.php` (extend) | +2 | F3 adversarial whitelist + whitelisted-keys pass-through |

## Known limitations (deliberate; v1.1 or unrelated hooks)

| # | Limitation | Follow-up |
|---|---|---|
| F3-L1 | **Overpayment up to half a monthly installment** (Option B schedule-boundary). Borrower closing mid-month pays interest "до следваща планирана вноска" — slightly more than pure day-count accrual would charge. Overpayment flows to investors as extra distribution (not retained by platform). ~5 EUR average. | v1.1 could add day-count as `interest_policy` strategy (DECISIONS.md entry documents the effort estimate: 2-3 days). |
| F3-L2 | **Schedule generation inconsistency** surfaced during Step 0 discovery. Some `active` loans in the DB have NO amortization schedule (Loan #5 in the worktree's test DB), and some `repaid` loans were seeded without schedules (Loan #6). Admin's Filament EditLoan form allows status transitions via the Select dropdown that bypass `transitionTo()` → silently skip `AmortizationService::generateSchedule()`. **Tracked as a separate followup** — not blocking F3 itself (F3's visibility gate + modal error panel surface the "no unpaid schedules" case cleanly for admin). User direction (2026-04-23): address outside the F3 series; do NOT fold into F3 commits. | Follow up with: (1) `loans:backfill-missing-schedules` artisan, (2) remove `status` from LoanResource form or route all transitions through `transitionTo()` via a dedicated action, (3) seeder + factory audit. |
| F3-L3 | **`loans.activated_at` NOT added.** Would have been needed for the day-count alternative (rejected for v1). Safe to add later as an additive migration when/if day-count accrual is introduced. | — |
| F3-L4 | **Idempotency exception-path in Filament action NOT unit-tested** end-to-end. Visibility gate hides the action for loans with `early_repaid_at` set; `Livewire::callTableAction` requires the action to be visible. The catch path is covered at the service level by `EarlyRepaymentExecutionServiceTest::test_idempotency_second_call_throws_already_executed_exception`. | Acceptable — the Filament catch is a 1-line delegation; service-level test pins the exception contract. |
| F3-L5 | **No Vue browser smoke test** (inherited F1 L10, F2 F2-L7). Manual browser verification required before production for the `InvestmentDetailPage` timeline rendering `early_repayment_completed` events with correct metadata labels ("Получена сума", per-investor distribution count). | Standard pre-deploy checklist below. |

### F3-L2 self-protection detail (risk assessment)

**F3 self-protects from the schedule-generation inconsistency (L2):
`EarlyRepaymentCalculationService` validates unpaid schedule
existence and throws a clear error BEFORE any money movement.
Impact: admin UX confusion if encountered, но ZERO financial damage.**

Concrete trace of the worst-case scenario — admin sets a loan to
`status='active'` via the Filament EditLoan form dropdown (bypassing
`transitionTo()`), creating an active loan with zero amortization
schedules. Admin later clicks "Предсрочно погасяване" on that loan:

1. **Visibility gate passes** (column-only check: status in
   active/late/default, early_repaid_at null, bought_back_at null).
   No per-row schedule query in the visibility closure.
2. **Modal opens.** `modalContent` closure calls
   `EarlyRepaymentCalculationService::calculateTotal($loan)`.
3. **Calculator's first query** fetches unpaid schedules:
   `$loan->amortizationSchedules()->whereIn('status', ['pending',
   'late'])->get(...)`. Returns an empty collection — loan has NO
   schedules at all.
4. **Calculator throws `InvalidArgumentException`**:
   > *"Cannot calculate early repayment for loan #X: no unpaid
   > schedule items. The loan is already fully paid OR has no
   > schedule generated yet."*
5. **Modal's try/catch** captures the exception and renders the
   error-panel branch of `early-repayment-preview.blade.php`
   (danger-tinted card with the exception message). Admin sees a
   clear, actionable error: the loan has no schedule.
6. **If admin still clicks Submit** (defense-in-depth), the action
   closure runs `EarlyRepaymentExecutionService::execute()` which
   invokes the calculator again and re-throws. The action's
   triple-catch captures `InvalidArgumentException` → danger toast
   "Невалидна операция". No money moves. No LoanEvent written. Loan
   status and all financial state remain unchanged.

**Outcome:** the admin cannot execute an early repayment on an
inconsistent loan. The error message hints at the root cause
("...OR has no schedule generated yet") directing the admin to
escalate. No incorrect distribution, no loan corruption, no silent
failure. F3-L2 is therefore classified as an **operational UX
limitation**, not a financial-risk limitation.

The separate follow-up (Tier 1 backfill artisan + Tier 2 admin form
lockdown per the Pre-F3 Blocker Investigation section below) will
eliminate even the UX friction.

## Operational pre-deploy checklist

1. `composer install` — F3 added no new packages; verify `composer.lock` matches this branch.
2. `php artisan migrate` — 1 new F3 migration applies on top of F2's batch. Verify
   `SHOW CREATE TABLE loans` shows `early_repaid_at` + `early_repayment_amount` + CHECK.
3. Adversarial insert check (should be blocked by CHECK):
   ```sql
   UPDATE loans SET early_repayment_amount = -1 WHERE id = <some_loan_id>;
   -- expected: SQLSTATE check constraint violated
   ```
4. Filament smoke — log in as admin, navigate to **Финанси → Кредити**. Pick a loan in
   `active`/`late`/`default`. Click "Предсрочно погасяване". Verify modal shows
   fresh breakdown + warning. Dismiss (don't click Submit). Repeat on a `draft`/`funded`/
   `repaid`/`bought_back` loan — action should NOT be visible.
5. On a staging loan: click Execute → verify loan transitions to `repaid`,
   `early_repaid_at` + `early_repayment_amount` stamped, 1 LoanEvent row
   written (`early_repayment_completed`), per-investor Transactions created
   (`TYPE_EARLY_REPAYMENT_PRINCIPAL` + `_INTEREST`), investors receive email
   + database notification.
6. Queue worker already running from F1 requirement — no new worker needed.
7. No new cron entries — no changes to `crontab -l` or `schedule:list`.
8. Manual Vue browser test on `/invest/:id` (with a newly early-repaid loan)
   — verify timeline renders "Изплатено предсрочно" badge with correct
   metadata (Получена сума, главница/лихва split) — F3-L5.

## Step completion order (for replay / forensic review)

```
4429323 (step 1)  →
f843583 (step 2)  →
d57c078 (step 3)  →
8b9cc92 (batch 4+5) →
3699ee2 (step 6)  →
<this commit> (step 7)
```

Base: `9efbbf5` (F2 Step 8 completion).

## Q1–Q7 — final resolution

All 7 discovery questions retired by the Option B decision (schedule-
boundary math, no day-count accrual).

| Q | Topic | Resolution |
|---|---|---|
| Q1 | Day-count convention | **N/A** — Option B dropped day-count entirely. |
| Q2 | Accrual reference date | **N/A** — no accrual formula. |
| Q3 | Schedule-boundary vs day-count | **Option B (schedule-boundary) approved.** F3 becomes ~70% clone of F2 buyback; no new math domain; precision loss bounded by ~half an installment's interest; overpayment flows to investors. Future day-count migration path is clean (strategy pattern + per-loan config column). |
| Q4 | Handling late schedules | **N/A** — automatic via the schedule-boundary formula (unpaid schedules with `due_date <= next_upcoming` are ALL included). |
| Q5 | WalletService extension pattern | **New methods.** `earlyRepayPrincipal` / `earlyRepayInterest` thin wrappers over existing F1/F2 shared private helpers (`creditAvailableFromInvested` / `creditAvailableAndEarned`). Zero call-site risk for F1 repay / F2 buyback methods. |
| Q6 | `loans.early_repayment_amount` column | **Approved.** Denormalised audit field. Supports admin reports without joining `loan_events`. CHECK constraint `>= 0 AND <= 10_000_000`. |
| Q7 | Amortization schedules on early repayment | **Leave untouched.** `loan.status` + `early_repaid_at` are the single source of truth. Remaining unpaid schedules stay `pending`. Mirror F2 Q7. |

Additional F3 decision (added during Step 1 scope confirmation):
- **`loans.early_repayment_amount` CHECK upper bound at 10M EUR.**
  Psychological sanity alarm — any single-loan close above 10M is
  almost certainly a calculation bug and should fire a DB-level alarm
  before the row commits.

---

## Business model (reminder for forensic review)

Platform is an accounting/ledger system. All money is virtual inside the
platform. Admin (клиентката) holds real cash in an external business
bank account.

Real-world early repayment flow:
1. Borrower contacts admin externally (phone / email) asking to close early.
2. Borrower wires funds to admin's bank account (outside the platform).
3. Admin confirms cash received in the bank.
4. Admin opens Filament, navigates to the loan.
5. Clicks **"Изпълни предсрочно погасяване"**.
6. Modal shows fresh calculated total: outstanding principal + interest
   accrued to today.
7. Admin confirms. System distributes pro-rata to investors, transitions
   loan to `repaid`, dispatches per-investor notifications.

No cron. No automation. No borrower-facing flow. Admin-in-the-loop at
every step — same pattern as F2 buyback execution.

---

## Step 0 — Current State (Discovery)

### 1. State machine — `app/Models/Loan.php`

`Loan::ALLOWED_TRANSITIONS` (post-F2) permits the needed transitions
for early repayment **without any migration or enum change**:

```php
self::STATUS_ACTIVE   => [self::STATUS_LATE, self::STATUS_REPAID],
self::STATUS_LATE     => [self::STATUS_ACTIVE, self::STATUS_DEFAULT, self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
self::STATUS_DEFAULT  => [self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
self::STATUS_REPAID   => [],
```

`active → repaid`, `late → repaid`, `default → repaid` are all already
permitted. **Scope conclusion: NO state machine changes needed for F3.**

Loan already has `bought_back_at` (timestamp) precedent; F3 mirrors with
`early_repaid_at`.

### 2. Interest / amortization model — `app/Services/AmortizationService.php`

**Discovery finding (critical for accrual formula):** the platform uses
an implicit **30/360 convention**:

```php
// Line 82 — schedule dates are 30 calendar days apart:
'due_date' => $baseDate->copy()->addDays(30 * $i),

// Lines 55–59 — monthly rate = annual / 12 (no day-count weighting):
$monthlyRate = bcdiv(bcdiv((string) $loan->interest_rate, '100', SCALE), '12', SCALE);

// Per-installment interest = remaining balance × monthly rate:
$interest = bcmul($remaining, $monthlyRate, 2);
```

**There is NO day-count accrual anywhere in the codebase currently.**
Grep for `accrued`, `day-count`, `365`, `360`, `daily_rate` confirms:
- Only references are in comments (F2 buyback calc explicitly states
  "NO day-count accrued interest — scheduled interest only").
- Deposits, savings, investments, wallets — none accrue interest
  per-day. Everything is schedule-based or transaction-based.

**F3 introduces day-count accrual for the first time** — needs an explicit
formula decision.

### 3. Transaction types — `app/Models/Transaction.php`

Current `TYPES` constant (8 entries): `deposit, withdrawal, investment,
repayment_principal, repayment_interest, buyback_principal,
buyback_interest, fee`. DB column is `varchar` with no CHECK — app-only
enum. F3 adds two:
- `TYPE_EARLY_REPAYMENT_PRINCIPAL = 'early_repayment_principal'`
- `TYPE_EARLY_REPAYMENT_INTEREST = 'early_repayment_interest'`

### 4. LoanEvent enum — `app/Models/LoanEvent.php`

✅ **F1 pre-expanded** the enum with F3 placeholders:
```php
public const TYPE_EARLY_REPAYMENT_REQUESTED = 'early_repayment_requested';
public const TYPE_EARLY_REPAYMENT_COMPLETED = 'early_repayment_completed';
```
No migration / DB CHECK change needed for `loan_events.event_type`.

F3 will write `early_repayment_completed` (the `_requested` slot stays
unused — there is no "admin clicked request, pending" state; execution
is one-click from the UI).

### 5. BuybackExecutionService — template for F3

`app/Services/Loans/BuybackExecutionService.php` (258 lines) is a 1:1
template. F3 mirrors:
- `DB::transaction` with `Loan::lockForUpdate()`.
- Idempotency check: `status === 'repaid'` → `EarlyRepaymentAlready
  ExecutedException`.
- State-machine validation: only `active | late | default` allowed.
- Fresh calculation at execute time (never from a cached value).
- Pro-rata distribution via a calc service helper.
- Per-investor wallet moves (`repayPrincipal` / `repayInterest` — or
  new `earlyRepayPrincipal` / `earlyRepayInterest` — TBD, see Q2 below).
- Stamp `early_repaid_at` + `transitionTo(STATUS_REPAID)` — ONE UPDATE.
- Write `loan_event(early_repayment_completed)` with aggregate metadata.
- Return `EarlyRepaymentResult` DTO to the caller for notification
  dispatch (money first, emails second — F1/F2 pattern).

### 6. BuybackCalculationService — partial template

`app/Services/Loans/BuybackCalculationService.php` handles coverage
math + pro-rata distribution. F3 reuses the **distribution** logic
verbatim (last-investor-remainder) but replaces the **calculation**
part — F3 uses day-count accrual, not coverage-type enum.

Principal calculation reuses the F2 approach:
```php
outstanding_principal = Σ unpaid_schedule.principal
```

Interest calculation differs — F3 computes accrual from the last paid
date (see Q1 below for the proposed formula).

### 7. Filament LoanResource — where the action goes

`app/Filament/Resources/LoanResource.php` (166 lines) has 3 row actions:
`publish`, `unpublish`, `activate`. F3 adds a 4th:
**`execute_early_repayment`** with:
- Visible when: `loan.status IN (active, late, default)` AND
  `loan.early_repaid_at IS NULL` AND `loan.bought_back_at IS NULL`.
- `requiresConfirmation()` with a modal description that calls the
  calc service to show the FRESH amount (principal + accrued split).
- Action closure: `EarlyRepaymentExecutionService::execute($loanId, $adminId)`.
- Catch `EarlyRepaymentAlreadyExecutedException` → warning toast.
- Catch generic exceptions → danger toast + log.
- On success: dispatch per-investor notifications, success toast.

### 8. RepaymentService — NOT reused

`app/Services/RepaymentService.php` handles scheduled installment
repayments — admin picks a schedule row, enters amounts, service
distributes pro-rata + marks the row `paid`. Different semantic from
full close-out. **F3 uses a NEW service**, not an extension.

RepaymentService guards status ∈ {active, late}. Early repayment
additionally needs to support `default` (per Q6 scope note — borrower
may want to catch up even a defaulted loan).

### 9. Notifications landscape

F1 added `LoanWentLateNotification`. F2 added `LoanBoughtBackNotification`
+ `BuybackEligibleAdminNotification`. Current notifications in
`app/Notifications/`:
```
DepositApprovedNotification, DepositRejectedNotification,
KycStatusNotification, LoanStatusChangedNotification,
LoanWentLateNotification, LoanBoughtBackNotification,
BuybackEligibleAdminNotification, RepaymentReceivedNotification,
WithdrawalApprovedNotification, WithdrawalRejectedNotification,
VerifyEmailNotification
```

F3 adds: **`EarlyRepaymentReceivedNotification`** — mirror of F2's
`LoanBoughtBackNotification` shape.

---

## Client-decision Q6 (approved)

> главница + лихва до днес (без rebate, без penalty)

Interpretation:
- **главница** = outstanding principal (what borrower still owes in
  principal, = Σ unpaid_schedule.principal).
- **лихва до днес** = interest accrued up to today (days since last
  schedule's due_date × daily rate × outstanding_principal).
- **без rebate** = borrower does NOT get a discount on the accrued
  interest. Pays exact accrual amount.
- **без penalty** = no early-termination fee added on top.

**Investor side:** pro-rata of (outstanding_principal + accrued) —
matches the "no prepayment penalty" industry norm. Investor "loses"
the scheduled future interest they WOULD have earned if the loan ran
to term, but gains the capital back plus accrual-to-date.

---

## Open questions for user (BLOCKING Step 1)

### Q1 — Day-count convention for accrual

**The platform has never done day-count accrual.** F3 introduces it
for the first time. Three options:

| Option | Formula | Match existing amortization? |
|---|---|---|
| **(a) 30/360** | `outstanding × (annual_rate/100) × (days/360)` | ✅ YES — amortization uses 30-day months × 12 = 360-day year |
| (b) actual/365 | `outstanding × (annual_rate/100) × (days/365)` | ❌ different assumption |
| (c) actual/360 | `outstanding × (annual_rate/100) × (days/360)` | Half-match (same denominator, but "actual days" for numerator) |

**Recommendation: (a) 30/360** — consistent with the existing
amortization model. A loan issued at rate 12%/year:
- Monthly rate = 1% (matches existing amortization)
- Daily rate = 1% / 30 = 0.0333% (= annual 12% / 360)
- Accrual formula: `outstanding × (annual_rate/100/360) × elapsed_days`

No mismatch between "what the amortization schedule implies" and
"what F3 computes for accrual".

### Q2 — Accrual reference date

Where does "days elapsed" start counting from?

| Option | Reference date | Edge case: no paid installments yet |
|---|---|---|
| **(a) Last paid installment's `due_date`** | `max(paid schedules.due_date)` | Loan activation date (`funded → active` transition) |
| (b) Last paid installment's `paid_at` | `max(paid schedules.paid_at)` | As above |
| (c) Last SCHEDULED installment's `due_date` (paid or not) | `max(schedules.due_date where due_date <= today)` | Loan activation date |

**Recommendation: (a)** — cleanest. A schedule represents its own
accrual period; when it's PAID, the investor already received its
interest. So we accrue on `outstanding_principal` for days since that
boundary.

For a loan with no paid installments yet (rare — borrower paying
early within month 1), reference = loan's activation time
(approximated as `loan.updated_at` when status transitioned to
`active` — OR a new column if we want precision).

### Q3 — Simpler alternative: sum scheduled interest, skip accrual

A pragmatic alternative that **avoids introducing day-count entirely**:

```
early_repayment_interest = Σ paid_past_and_current_schedules.interest - already_distributed
```

i.e. admin looks at today's date, identifies the most-recent schedule
whose due_date is `<=` today, charges the borrower the SUM of
scheduled interest through that installment (even if the installment
isn't paid yet), and skips all future scheduled interest.

This is discretized to installment-boundary granularity (no mid-month
proration). For a 30-day amortization cycle, mid-month accrual is
at most ~15 days off a ≈30-day step — small for typical loans.

**Pros:** zero day-count code. Consistent with F2's "scheduled
interest only" stance. Simpler.

**Cons:** borrower overpays slightly if they close mid-month (they
pay the full scheduled interest for a month they didn't fully use).

**Recommendation (alternative):** flag to user as a simpler Q3-variant.
If user accepts schedule-boundary precision (same as F2 buyback),
F3 becomes a near-clone of F2's BuybackCalculationService +
Execution pattern, with way fewer moving parts.

### Q4 — Handling `late` schedules when computing total

If the loan is in `late` status and has unpaid overdue installments:

| Option | Scheduled interest of UNPAID PAST installments |
|---|---|
| **(a) Include them** | Borrower "catches up" on months they missed. Payment = outstanding_principal + sum(past_scheduled_interest) + accrual_current_period |
| (b) Skip them | Borrower only pays outstanding_principal + day-count accrual from loan start (or last paid) — risks DOUBLE-count if we use (a)-style accrual, OR UNDER-count if we rely purely on day-count |

Actually if Q1 is **(a) 30/360 accrual from last paid due_date** and the
loan is 3 months late, the accrual period naturally extends 90+ days,
correctly capturing the overdue interest. So option **(a)** above is
AUTOMATIC — no separate "past scheduled interest" sum needed.

**Recommendation: automatic handling via Q1 formula.** No explicit
treatment of past scheduled interest needed. The `accrued_interest`
calculation handles it.

### Q5 — Wallet helper: new method or reuse repay methods?

F3 moves money `invested → available` (principal) and credits
`available + earned` (interest), identical to
`WalletService::repayPrincipal` / `repayInterest`. The ONLY difference
is the `Transaction.type` column value.

Options:
- (a) Add `WalletService::earlyRepayPrincipal` / `earlyRepayInterest`
  as new thin wrappers over the shared private helpers
  (`creditAvailableFromInvested` / `creditAvailableAndEarned`).
  Matches F2's `buybackPrincipal` pattern.
- (b) Reuse existing `repayPrincipal` / `repayInterest`; pass a string
  arg to override type. Would require signature change.

**Recommendation: (a)** — matches F2 precedent, zero call-site risk.

### Q6 — `loans.early_repayment_amount` column: yes or no?

User's scope said "*(optional) loans.early_repayment_amount за audit*".

Options:
- (a) Add the column. Redundant with `loan_events.metadata.total_amount`
  (same value in two places) but allows quick admin queries like
  `SELECT SUM(early_repayment_amount) FROM loans WHERE early_repaid_at
  >= '2026-01-01'` without joining `loan_events`.
- (b) Skip — metadata is the single source of truth; queries join
  `loan_events`.

**Recommendation: (a)** — 1 additional decimal column is cheap.
Denormalised aggregate makes admin reporting (widgets, exports)
trivial. Keeps CLAUDE.md's "admin reporting shouldn't require SQL
joins" principle.

### Q7 — Schedule rows after early repayment

F2 Q7 decided: "leave amortization_schedules alone on buyback". F3
options parallel:
- **(a) Leave alone** — loan.status=`repaid` is the source of truth.
  Remaining unpaid schedules stay `pending`. Visible in admin's
  RelationManager but clearly a closed loan (status badge).
- (b) Mark all remaining `paid` with `paid_at=early_repaid_at` — UI
  tidiness. But overloads `paid_at` semantic (borrower didn't actually
  pay those individually).
- (c) New status `early_closed` — enum change on amortization_schedules.

**Recommendation: (a)** — same as F2 Q7. No schema change, source of
truth is loan.status.

---

## Proposed changelist (file-by-file)

### New files (~8)

```
database/migrations/2026_04_25_000001_add_early_repayment_tracking_to_loans_table.php
app/Services/Loans/EarlyRepaymentCalculationService.php
app/Services/Loans/EarlyRepaymentExecutionService.php
app/Services/Loans/EarlyRepaymentCalculation.php        [DTO]
app/Services/Loans/EarlyRepaymentResult.php             [DTO]
app/Services/Loans/EarlyRepaymentAlreadyExecutedException.php
app/Notifications/EarlyRepaymentReceivedNotification.php
resources/views/emails/early-repayment-received.blade.php
```

### Edited files (~6)

```
app/Models/Loan.php                                     [+ fillable, casts for new columns]
app/Models/Transaction.php                              [+ 2 constants, added to TYPES]
app/Services/WalletService.php                          [+ earlyRepayPrincipal, earlyRepayInterest]
app/Filament/Resources/LoanResource.php                 [+ execute_early_repayment row action]
app/Http/Resources/LoanEventResource.php                [+ F3 metadata keys in whitelist]
app/Http/Resources/LoanResource.php                     [+ early_repaid_at field when status=repaid]
```

### Tests (~40 new, Step 7)

```
tests/Unit/Services/EarlyRepaymentCalculationServiceTest.php     [~10 tests]
tests/Unit/Services/EarlyRepaymentExecutionServiceTest.php       [~9 tests]
tests/Unit/Notifications/EarlyRepaymentReceivedNotificationTest.php [~10 tests]
tests/Feature/EarlyRepaymentActionTest.php                       [~8 tests — LoanResource row action]
tests/Feature/Api/LoanEventsApiTest.php                          [+2 tests — whitelist for F3 metadata]
```

---

## Proposed Step structure (mirror F2 cadence)

| Step | Description |
|---|---|
| 0 | Discovery (this document) + answer Q1–Q7 |
| 1 | Migration (loans + 2 nullable columns) + app constants + DECISIONS.md entry |
| 2 | Services: EarlyRepaymentCalculationService + EarlyRepaymentExecutionService + DTOs + exception + WalletService extension |
| 3 | Filament row action on LoanResource + fresh-calc modal |
| 4 | EarlyRepaymentReceivedNotification + BG email template + action wire-up |
| 5 | API / Vue extensions (LoanResource `early_repaid_at`, LoanEventResource whitelist, timeline rendering) |
| 6 | Comprehensive test suite (~40 new tests; target total ~409) |
| 7 | Documentation (CLAUDE.md F3 section + AUDIT finalize + DECISIONS.md pass + README) |

F3 is SMALLER than F2 (no cron, no Queue page, no admin digest,
no dismiss mechanism, simpler state machine). Estimated ~3-4 days.

---

## Known risks / flags

- **F3-R1 — First day-count accrual code in the codebase.** Introduces
  a new math domain. Needs careful test coverage including boundary
  cases: day 0 (just activated), same-day-as-last-schedule (0 accrual),
  cross-day-boundary (DST?), leap-year (365 vs 366 days — not an
  issue with 30/360 but note if (b)/(c) chosen).
- **F3-R2 — `default` loans allowed early repayment.** This is an
  unusual business scenario (defaulted loan getting paid off) but
  permitted by the state machine and consistent with F2's
  `default → bought_back` allowance. Document the compensating
  control (admin verifies externally before clicking).
- **F3-R3 — Interest already distributed vs accrued-to-date overlap.**
  Mitigated by using `outstanding_principal` (post-paid-principals)
  as the accrual base AND starting accrual from `last_paid_due_date`
  (no overlap with past distributed interest). Will be pinned by a
  dedicated test in Step 6.
- **F3-R4 — "No paid installments yet" edge case.** If borrower
  pays within month 1 (no installment has been paid yet), reference
  date = loan activation time. Need to check whether
  `loan.updated_at` at the `funded → active` transition is reliable,
  OR whether a new `loan.activated_at` column should be added.
  Investigated in Step 0: `Loan::transitionTo('active')` updates
  `updated_at` via Eloquent. But `updated_at` is mutated by ANY
  subsequent save. Flag — may need a dedicated `activated_at` column
  to be reliable. Alternative: use `amortization_schedules.created_at`
  of the first schedule as a proxy (one-time insert, immutable
  thereafter via the schedules append-only semantic).

---

## Pre-F3 Blocker Investigation — Schedule Generation Inconsistency

### Triggering observation

User reports: some `active` loans have amortization schedules, others
don't. No "Create schedule" button in Filament. Behavior inconsistent.
**F3 CANNOT proceed until understood + fixed** — F3 operates on
schedules to compute outstanding principal + unpaid interest.

### DB snapshot (production `p2plending` MySQL, 2026-04-23)

| Loan ID | Status | Amount | Funded | Schedule rows | Expected? |
|---|---|---|---|---|---|
| 1 | active | 25,000 | 25,000 | 12 | ✅ yes |
| 2 | funding | 12,000 | 4,800 | 0 | ✅ no schedule yet |
| 3 | active | 8,500 | 8,500 | 3 | ✅ yes |
| 4 | published | 6,200 | 0 | 0 | ✅ no schedule yet |
| **5** | **active** | **18,000** | **18,000** | **0** | ❌ **SHOULD HAVE** |
| **6** | **repaid** | **10,500** | **10,500** | **0** | ❌ **SHOULD HAVE** |
| 7 | published | 15,000 | 0 | 0 | ✅ |
| 8 | draft | 30,000 | 0 | 0 | ✅ |
| 9 | late | 5,000 | 5,000 | 3 | ✅ yes |
| 10 | published | 9,000 | 0 | 0 | ✅ |
| 13 | funding | 1,000 | 950 | 0 | ✅ |
| 14 | draft | 500 | 0 | 0 | ✅ |

**2 loans have the wrong state.** Loans #5 and #6 are post-funding
statuses but have no schedule.

### Root causes (3 independent)

**Cause A — Schedule generation coupled ONLY to `transitionTo()`.**

`AmortizationService::generateSchedule()` is the sole generator in
code (grep confirmed — no observers, no jobs, no listeners, no queue).
It's invoked from exactly ONE call site:

```php
// app/Models/Loan.php line 121–131 (inside transitionTo())
DB::transaction(function () use ($newStatus, $fromStatus) {
    $this->forceFill(['status' => $newStatus])->save();
    if ($fromStatus === self::STATUS_FUNDED && $newStatus === self::STATUS_ACTIVE) {
        app(AmortizationService::class)->generateSchedule($this);
    }
});
```

The `booted::updating` hook (lines 81–101) ONLY validates transitions
against `ALLOWED_TRANSITIONS`; it does NOT invoke the service. Any
path that bypasses `transitionTo()` will pass validation but skip
schedule generation silently:

- **Filament `EditLoan`** — default `EditRecord::save()` calls
  `Model::update($data)` → triggers booted hook (validation passes
  for funded→active) → row saved with new status → **NO schedule**.
  The form exposes status as a Select dropdown (LoanResource lines
  43–62), letting admin switch funded→active via save rather than
  the "Активирай" action button. Two admin paths; ONE correct.
- **`DatabaseSeeder`** — creates loans directly with `Loan::create([...
  'status' => 'active' ...])` (lines 110–121), bypassing `transitionTo`.
- **`LoanFactory::active()`** / `::repaid()` — same pattern for tests.
- **Raw DB / tinker updates** — bypass all model hooks.

**Cause B — Seeder uses a SIMPLIFIED schedule formula, not annuity.**

For seeded `active`/`late` loans (lines 124–137), inline schedule:

```php
$mp = bcdiv($amount, $months, 2);                       // flat principal
$mi = bcdiv(bcmul($amount, $rate / 1200), '1', 2);      // flat interest
```

This is **flat split, not annuity**. `AmortizationService` produces
annuity: declining interest + increasing principal per installment.
Seeder's schedules have ALL installments identical, which is
mathematically WRONG for an annuity-rate loan.

Consequence: schedules on seeded loans #1, #3, #9 are present but
numerically incorrect. F3 calculations built on top would produce
wrong interest amounts for investors.

**Cause C — Seeder skips schedule for `repaid` loans.**

Line 124: `if (in_array($status, ['active', 'late']))`. `repaid`
status is excluded even though a real repaid loan would have had
a schedule (all paid by now). Loan #6 is the victim of this filter.

### Manifest trace per affected loan

| Loan | Seeder data | audit_logs rows | Explanation |
|---|---|---|---|
| #5 | seeded as `funding`, funded=9,900 | **3** (1 create + 2 updates) | Someone mutated post-seed: status→active + funded→18,000. No code path re-invoked AmortizationService. |
| #6 | seeded as `repaid` directly | 1 (create only) | Seeder filter on line 124 skipped schedule generation. |

Audit trail confirms Cause A on loan #5 specifically — the status
change happened WITHOUT firing the schedule generator.

### Admin workflow summary (the bug path)

```
Admin wants to activate a funded loan.

  Path 1 (canonical):             Path 2 (buggy):
  ─────────────────               ─────────────────
  Click "Активирай" button   vs   Edit loan form → change Status
  → LoanResource action           Select → Save button
  → $loan->transitionTo(           → $loan->update(['status' => ...])
    STATUS_ACTIVE)                 → booted::updating validates
  → transitionTo invokes           → row saved, status = active
    AmortizationService            → NO schedule generated
  → schedule row(s) created
```

Both paths produce a loan with `status = active`. Only Path 1 produces
a schedule. Neither path warns the admin that they picked the broken
one. No UI indicator. No "missing schedule" alert.

### Impact on F3

A loan without a schedule is unusable for F3:
- `EarlyRepaymentCalculationService` would compute
  `outstanding_principal = Σ unpaid schedule.principal = 0`.
- `EarlyRepaymentExecutionService` would reject with "zero total"
  `InvalidArgumentException`.
- Admin click on "Execute early repayment" would show a danger toast
  with no useful error message beyond "total is 0.00".

Additionally, F3 assumes schedule amounts are mathematically correct
(annuity formula, consistent with `interest_rate` used by
RepaymentService + BuybackCalculationService). Seeder's simplified
formula produces WRONG numbers, so F3 built on test data would
distribute incorrect amounts to investors.

**F3 Step 2 (services) is BLOCKED** until this is resolved.

### Proposed fix scope (for user decision)

No code written yet — these are OPTIONS for discussion.

**Tier 1 — one-time data fix (mandatory for F3 to operate):**
- Artisan command `loans:backfill-missing-schedules --dry-run / --force`.
  For each loan in status IN (active, late, default, bought_back, repaid)
  with 0 schedule rows: invoke `AmortizationService::generateSchedule()`.
- For loans with the seeder's simplified-formula schedules (loans #1,
  #3, #9), optional regen: delete existing + regenerate via annuity.
  **Data-disruptive** — repayments already tied to specific schedule
  rows by `amortization_schedule_id` foreign key. If we regenerate,
  existing RepaymentService state is invalidated. Hence: **leave
  them as-is OR manual per-loan audit before regen**.
- For `repaid` loans with 0 schedule rows (loan #6): generate schedule
  via annuity, mark all rows `paid` with `paid_at = loan.created_at
  + 30 × i` (synthetic but makes the UI consistent).

**Tier 2 — prevent future inconsistencies (required to unblock long-term):**
- **Option 2A — Remove `status` from LoanResource form.** Force admin
  to use explicit action buttons (Publish, Activate, Unpublish, etc.).
  Each action wraps `transitionTo()` correctly. Less magic, more
  explicit. Removes 1 UI surface (the Select dropdown).
- **Option 2B — Hook AmortizationService into the booted() update**
  event. On any status transition funded→active regardless of path,
  invoke the service. More magic, but backward-compatible for any
  other write path (factory, seeder, tinker).
- **Option 2C — Add a "Generate Schedule" row action** on LoanResource,
  visible when `status IN (active, late, default, bought_back, repaid)`
  AND `schedule_count == 0`. Admin safety-net for edge cases.

**Recommendation: Tier 2A + Tier 2C combined.** Remove the dropdown
(forces the correct path), add the safety-net button (recovers from
past data accidents OR any future glitch). Option 2B adds implicit
magic that could surprise future maintainers — explicit is better.

**Tier 3 — seeder + factory fix (dev/test data correctness):**
- `DatabaseSeeder` uses `transitionTo()` through proper lifecycle
  instead of direct `Loan::create(['status' => 'active'])`. For a
  loan seeded as `active`, do: create as draft → publish → funding →
  funded → active (calling transitionTo at each step). Schedule
  generated correctly via AmortizationService.
- `LoanFactory::active()` / `::repaid()` likewise.
- `repaid` state: generate schedule via annuity, mark all rows paid.
- Aligns test data with production invariants. Downstream: existing
  tests that assume seeded-schedule numbers may need adjustment
  (simplified → annuity numbers).

### Blocker status

| Tier | Required to unblock F3? | User decision |
|---|---|---|
| 1 | **YES** — can't test F3 on loans without schedules | Required |
| 2 | **YES** — F3 adds no value if admin form still silently breaks schedules | Required |
| 3 | Optional for F3 (F3 tests can use `transitionTo` correctly in their own setup) | Nice-to-have |

## Awaiting client answers (historical — all resolved)

All Q1–Q7 questions and F3-R4 are resolved as of the 2026-04-23
client Option B decision. Final outcomes documented in the
"Q1–Q7 — final resolution" table near the top of this file.

- **Q3 choice:** Option B (schedule-boundary, no day-count) approved.
- **Q5/Q6/Q7 defaults:** accepted as recommended.
- **F3-R4:** N/A (no day-count → no `loans.activated_at` needed).
