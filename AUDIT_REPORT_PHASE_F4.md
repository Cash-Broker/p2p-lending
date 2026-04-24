# Phase F4 — Fees Infrastructure: Implementation Report

**Branch:** `feature/fees-infrastructure` (from `main` at `83458b7`)
**Base commit:** `83458b7` (F3 chore/handoff — per `APP_BASE_PATH`-fixed
baseline: 406 passed + 4 skipped = 410 tests, 1215 assertions)
**Session start:** 2026-04-24
**Client decision on Q5 (scope):** *E — infrastructure ready, disabled by
default in v1.*

## Completion summary

| Step | Description | Commit |
|---|---|---|
| — | Copy hotfix: FAQ + chatbot → "безплатно" (decoupled from F4 main work; truth-before-infra) | `f301c19` |
| 0 | Discovery (section 2 below) — catalogue 16 exists-and-wired items + dormant producers + missing pieces. Recommended GAP-FILL scope. | (this doc, pre-commit) |
| 1 | Migration: `platform_settings` seed rows (`fees_withdrawal_enabled` bool, `fees_withdrawal_amount` float) + CHECK 0–100 + `PlatformSetting::set()` helper + FeesPage scaffold + DECISIONS.md F4-01 + this audit | `6dc63c6` |
| Batch A (Steps 2+3+FeesPage UI) | `FeeService` + `FeeQuote` DTO + `WithdrawalService::approve()` branch-split + FeesPage live preview + stats + recent-fees list + 13 tests | `fa300b4` |
| Batch B (Steps 4+5) | `FeeController` + `GET /api/fees/config` + `WithdrawalPage.vue` breakdown + 14 more tests | `36a00d8` |
| 6 | CLAUDE.md Phase F4 section + this audit finalised + DECISIONS.md F4-02/F4-03 + README Operations extension | (this commit) |

## Test coverage (final)

| Metric | Pre-F4 baseline* | F4 final | Delta |
|---|---|---|---|
| Tests passed | 406 | **433** | +27 |
| Tests skipped (documented null-path placeholders) | 4 | **4** | 0 |
| **Total** | 410 | **437** | **+27** |
| Assertions | 1215 | **1272** | +57 |
| Test files in tests/ | 38 | **40** | +2 new F4 |
| Regressions on F1/F2/F3 tests | — | **0** | — |
| Local MySQL suite duration | ~225 s | ~262 s | stable |

*"Pre-F4 baseline" uses the APP_BASE_PATH-corrected count. HANDOFF_F4
recorded 404+4=408; discrepancy is dataProvider-case counting, not a
regression (see "Worktree workflow notes" below).

### New F4 test files

| File | Tests | Focus |
|---|---|---|
| `tests/Unit/Services/FeeServiceTest.php` | 13 | Flag on/off matrix + zero-gross / zero-amount / missing-key edges + category whitelist + unpadded-value normalisation + large-gross precision + `FeeQuote::netAmount` arithmetic |
| `tests/Feature/WithdrawalFeeTest.php` | 8 | Flag-off parity + flag-on two-debit + reserved-bucket invariant + negative-net guard + description/reference format + sequential-withdrawal uniqueness + TOCTOU (enable-between-create-and-approve) + reconciliation sum |
| `tests/Feature/Api/FeesConfigApiTest.php` | 6 | Default disabled shape + public no-auth + flag toggle reflected + amount change reflected + stored-value normalisation + authenticated-equivalence |

27 F4 tests, 3 files. No contract-guard pattern (no F4 notifications
with dedupe). No whitelist-sanitisation guard (no F4 `loan_events`
entries — see "Not written" below).

## Known limitations (deliberate; v1.1 or separate follow-up)

| # | Limitation | Follow-up |
|---|---|---|
| F4-L1 | **Withdrawal-only scope in v1.** Origination / service / late / early-repayment / inactivity fees are placeholder-ready (`Transaction::TYPE_FEE` + `LoanEvent::TYPE_FEE_APPLIED` exist since F1) but not implemented. Each category requires its own migration + `FeeService::CATEGORIES` entry + integration point. | Incremental — next fee category when business enables it. |
| F4-L2 | **No platform-wallet model.** Fee revenue lives in admin's business bank account off-platform. Platform ledger records the debit only; no paired credit row. Reconciliation is the admin's bank statement vs. `SUM(transactions WHERE type='fee')` per period. | DECISIONS.md F4-01 documents trigger conditions (multi-tenant expansion, regulator-mandated fiduciary-vs-operating separation, admin reconciliation friction) + 2-3 day effort estimate for introducing `platform_wallets`. |
| F4-L3 | **No browser tests.** Vue breakdown rendering is not covered by PHPUnit (no Dusk / Vitest in the project). Data-contract coverage via `FeesConfigApiTest` (6 tests pinning exact JSON shape) is the strongest guarantee without a browser-driver setup. Manual QA before merge is the v1 compensating control. | Dusk 1-2 day spike flagged for a separate follow-up. Phase 5 Frontend audit may address coverage gaps. |
| F4-L4 | **Public-copy coupling.** When operator flips `fees_withdrawal_enabled=true`, FAQ (`resources/js/components/landing/FaqSection.vue`) + chatbot (`resources/js/components/ChatbotWidget.vue`) MUST be amended to describe the concrete fee. The FeesPage activation banner warns of this but does not enforce. Copy is currently "безплатно" (commit `f301c19`). | Activation procedure (manual). Could grow into a pre-toggle checklist in a future version of the FeesPage form. |
| F4-L5 | **No per-loan fee events.** `LoanEvent::TYPE_FEE_APPLIED = 'fee_applied'` pre-expanded in the F1 enum but NOT written by F4. Withdrawal fees aren't loan-scoped. Origination / service / late fees (future) will write it; admin-internal metadata vs. investor-public will require extending `LoanEventResource::PUBLIC_METADATA_KEYS` at that time. | Deferred until a loan-scoped fee category lands. |

## Operational pre-deploy checklist

1. **Code deploy** — merge `feature/fees-infrastructure` to `main`.
   Verify `composer.lock` unchanged (F4 added no new PHP packages).
2. **Migration** — `php artisan migrate`. One new F4 migration applies
   on top of F3's batch:
   ```
   2026_04_25_000002_seed_fee_platform_settings ........... DONE
   ```
   Verify 2 new rows in `platform_settings`:
   ```sql
   SELECT `key`, `value`, `type` FROM platform_settings
    WHERE `key` LIKE 'fees_%';
   -- fees_withdrawal_enabled | false | bool
   -- fees_withdrawal_amount  | 2.50  | float
   ```
3. **CHECK constraint verification** (MySQL; SQLite has no CHECK):
   ```sql
   UPDATE platform_settings SET value = '101'
    WHERE `key` = 'fees_withdrawal_amount';
   -- expected: ERROR 3819 (HY000) check constraint violated
   ```
4. **Assets rebuild** — `npm run build`. F4 modified
   `resources/js/views/WithdrawalPage.vue`; stale `public/build/`
   will render the old version without the breakdown.
5. **Flag-off smoke** (default state):
    - Log in as investor. Navigate **Теглене**. Enter an amount >
      available balance → error shown. Enter valid amount → submit →
      confirmation modal shows gross only, no breakdown. Confirm →
      admin Filament shows pending withdrawal → admin approves.
    - Verify DB: ONE `transactions` row of `type='withdrawal'` with
      amount = full requested; ZERO `type='fee'` rows.
    - Confirm pre-F4 behaviour byte-identical.
6. **Flag-on smoke** (admin activation):
    - Log in as admin. Navigate **Финанси → Такси**. Verify the
      activation banner with 4-step checklist. Amend the public copy
      (FAQ + chatbot) first per the checklist. Flip `fees_withdrawal
      _enabled` to true. Save. Verify `audit_logs` row written.
    - Navigate `/fees/config` directly in the browser (unauth). Should
      return `{"withdrawal":{"enabled":true,"amount":"2.50"}}`.
    - As investor, initiate a withdrawal. Verify breakdown panel shows
      Заявявате / Такса / Получавате with live math. Confirmation
      modal shows the breakdown too.
    - Submit + admin approves. Verify DB: TWO transaction rows per
      withdrawal — `type='withdrawal'` amount = net (gross − fee) with
      reference `withdrawal_request:{id}`, AND `type='fee'` amount =
      fee with reference `withdrawal_request:{id}:fee`.
    - Verify reserved bucket on the investor's wallet dropped to 0
      after both debits.
7. **Negative-net edge** — with flag on, create a withdrawal for an
   amount ≤ fee (admin SQL insert or create with flag off, then
   enable flag). Admin approves. Expect `ValidationException("must
   exceed fee")`; withdrawal stays `pending`; no transactions
   created; reserved bucket untouched.
8. **FeesPage stats smoke** — return to **Финанси → Такси**. Verify
   "Събрани такси (общо)" + "Този месец" cards show correct totals.
   "Последни 10 такси" list shows the recent approvals.
9. **Reconciliation** — after a few test withdrawals:
   ```sql
   SELECT SUM(amount) FROM transactions WHERE type = 'fee';
   ```
   Compare with the expected total (N withdrawals × fee amount). Any
   discrepancy indicates a missed `TYPE_FEE` write path and needs
   investigation BEFORE enabling for production.
10. **Public copy** — after flag flip, re-verify FAQ + chatbot copy
    describes the concrete fee (not the current "безплатно" from
    `f301c19`). This is F4-L4; it's a HUMAN step, no test covers it.
11. **Queue worker** — no new workers required. F4 added no investor-
    facing notifications (per Q5 scope).
12. **Cron** — no new scheduled commands. `crontab -l` should match
    the F3 state.

## Step completion order (for replay / forensic review)

```
f301c19 (copy hotfix)   →
6dc63c6 (step 1)        →
fa300b4 (batch a)       →
36a00d8 (batch b)       →
<this commit> (step 6)
```

Base: `83458b7` (F3 chore/handoff — "main" at the start of this session).

## Q1–Q13 final resolution

All 13 discovery questions resolved during Step 1 scope confirmation.
Summary table:

| Q | Topic | Resolution |
|---|---|---|
| Q1 | Fee categories in v1 | **Withdrawal only.** Origination / service / late / early-repayment / inactivity deferred. Each gets its own migration + FeeService extension when enabled (F4-L1). |
| Q2 | Withdrawal fee shape | **Flat 2.50 €** (matches historical FAQ/chatbot copy). No percent, no floor. Configurable via FeesPage. |
| Q3 | Who pays | **Investor.** Deducted from reserved bucket at admin approve time. |
| Q4 | Timing (per-transaction or batch) | **Per-transaction** at `WithdrawalService::approve()` — no batch processing. |
| Q5 | Ledger shape | **Option A — separate `TYPE_FEE` row** linked via `reference = 'withdrawal_request:{id}:fee'`. Mirrors F1/F2/F3 transaction-per-event pattern. |
| Q6 | Investor UI display | **Wallet shows `available` dropping by gross** via two transactions (net `TYPE_WITHDRAWAL` + `TYPE_FEE`). Transactions page shows both rows; breakdown panel on WithdrawalPage shows math at request time. |
| Q7 | Virtual-ledger accounting | **No platform-wallet bucket** — fee "leaves the ledger" from investor's POV. Admin's bank statement = revenue source of truth (DECISIONS.md F4-01). |
| Q8 | Kill-switch shape | **Per-category flags**, not master. `fees_withdrawal_enabled` in v1; future categories add their own. |
| Q9 | Public copy commitment | **Amend copy to "безплатно" first** (commit `f301c19`), then ship F4 with flag off. Flip copy back when flag flips on. |
| Q10 | Admin-triggered or automatic | **Auto at approve time.** Fee lookup INSIDE `DB::transaction` (TOCTOU-safe). Admin triggers the approve; fee rides along. |
| Q11 | Admin UI surface | **Dedicated FeesPage** (Filament Page, not Resource — DECISIONS.md F4-02). Navigation "Такси" under Финанси. |
| Q12 | Investor pre-submit visibility | **Live breakdown in WithdrawalPage + confirmation modal** + `amountBelowFee` pre-submit guard. |
| Q13 | Test coverage expectations | **27 F4 tests** — smoke + edge cases + API contract. Browser tests out of scope (F4-L3). |

## Worktree workflow notes (Claude Code specific; not blocking merge)

These quirks are artifacts of developing in a Claude Code worktree
(`.claude/worktrees/goofy-archimedes-b2be8f`). They do NOT leak into
the commit and clear automatically when `git worktree remove` runs.
Documented here for any future developer following the same pattern.

1. **Composer vendor junction** — `worktree/vendor` was created as a
   Windows directory junction pointing at `parent/vendor` to avoid a
   full `composer install` per worktree. Works for most operations,
   but `Application::inferBasePath()` resolves its composer loader
   through the junction and returns the PARENT path. As a result,
   `php artisan test` (via phpunit) loads the parent's
   `bootstrap/app.php`, sets `base_path()` to parent, and scans
   parent's `database/migrations/` — missing any migrations that
   exist only on the feature branch.
2. **Fix: `APP_BASE_PATH` env var** — prefix test invocations with
   `APP_BASE_PATH="$(pwd)"` so `inferBasePath()` picks up the worktree
   path. `bootstrap/app.php` in production is called by `artisan`
   (worktree's real file) which passes `basePath: dirname(__DIR__)`
   explicitly — so this is test-only. Production `php artisan test`
   from the merged branch in `main` needs no prefix.
3. **public/build junction** — similarly junctioned to the parent for
   the Laravel `ExampleTest` which requires `public/build/manifest.json`.
4. **`.env` copied from parent** — worktree has no `.env`; `cp
   ../../../.env .env` once per worktree. Gitignored.
5. **Baseline count discrepancy** — HANDOFF_F4 recorded 404+4=408.
   Actual on `83458b7` with APP_BASE_PATH fix is 406+4=410. 2-test
   delta is dataProvider-case counting behaviour differences between
   the two test invocations, not a regression. F4 adds +27 on top of
   whichever baseline.

`git status --short` shows only the 6 F4 implementation commits' worth
of files. None of the scaffolding above is tracked.

---

## Step 0 — original Discovery Report (below)

*The section that follows is the verbatim read-only discovery document
written before Step 1. Preserved for forensic review — DO NOT EDIT.
Superseded by the Implementation Report above for current-state
reference.*

---

## 1. Methodology

Per `HANDOFF_F4.md` section 6. Ran ripgrep sweeps over `app/`, `database/`,
`resources/js/`, `tests/`, `config/`, `bootstrap/` for:

- English: `fee`, `TYPE_FEE`, `TYPE_FEE_APPLIED`, `fees_enabled`, `FeeService`.
- Bulgarian: `такса`, `комисиона`, `комисион`.
- Explicit fee-column names (`fee_*`, `*_fee`).

No database queries executed (worktree is read-only for discovery). All
findings derived from source files.

---

## 2. Findings by file — categorised

### 2.1 EXISTS AND WIRED — read paths only (dormant producer, live consumer)

These items are in the codebase, compile, and display if data arrives.
**Nothing currently writes the data** that would feed them.

| # | Location | What it does | Status |
|---|---|---|---|
| 1 | [app/Models/Transaction.php:25](app/Models/Transaction.php:25) | `const TYPE_FEE = 'fee'` | Defined |
| 2 | [app/Models/Transaction.php:50](app/Models/Transaction.php:50) | `TYPE_FEE` in `TYPES` allowlist | Defined |
| 3 | [app/Models/LoanEvent.php:50](app/Models/LoanEvent.php:50) | `const TYPE_FEE_APPLIED = 'fee_applied'` | Defined |
| 4 | [database/migrations/2026_04_23_140004_create_loan_events_table.php:39](database/migrations/2026_04_23_140004_create_loan_events_table.php:39) | `'fee_applied'` pre-expanded in `event_type` CHECK enum (F1 forward-looking) | In schema |
| 5 | [database/migrations/2026_03_29_100006_create_transactions_table.php:17](database/migrations/2026_03_29_100006_create_transactions_table.php:17) | Column comment lists `fee` as allowed transaction type (NO DB CHECK on `transactions.type` — string only; app-level allowlist via `Transaction::TYPES`) | Documented |
| 6 | [app/Console/Commands/ReconcileLedger.php:42](app/Console/Commands/ReconcileLedger.php:42) | `$sums[Transaction::TYPE_FEE] ?? '0.00'` — defensive aggregation if any fee rows existed | Sums would work |
| 7 | [app/Filament/Resources/TransactionResource.php:36](app/Filament/Resources/TransactionResource.php:36) | Badge color mapping for `'fee'` (danger, red) | Would render |
| 8 | [app/Filament/Resources/AuditLogResource.php:105](app/Filament/Resources/AuditLogResource.php:105) | Display label `'fee' => "Такса: -{$amount} € ({$desc})"` | Would render |
| 9 | [app/Filament/Resources/LoanResource/RelationManagers/LoanEventsRelationManager.php:54](app/Filament/Resources/LoanResource/RelationManagers/LoanEventsRelationManager.php:54) | Label for `TYPE_FEE_APPLIED` → "Приложена такса" | Would render |
| 10 | [app/Filament/Resources/LoanResource/RelationManagers/LoanEventsRelationManager.php:64](app/Filament/Resources/LoanResource/RelationManagers/LoanEventsRelationManager.php:64) | Badge color (`gray`) for `TYPE_FEE_APPLIED` | Would render |
| 11 | [app/Http/Requests/TransactionFilterRequest.php:18](app/Http/Requests/TransactionFilterRequest.php:18) | Allows `type[]=fee` in investor API list filter | Would filter |
| 12 | [resources/js/views/DashboardPage.vue:102](resources/js/views/DashboardPage.vue:102) | `fee: 'Такса'` in type label map | Would render |
| 13 | [resources/js/views/TransactionsPage.vue:21](resources/js/views/TransactionsPage.vue:21) | `{ value: 'fee', label: 'Такса' }` filter option + label/color maps | Would render |
| 14 | [resources/js/views/InvestmentDetailPage.vue:58](resources/js/views/InvestmentDetailPage.vue:58) | `fee_applied: 'Приложена такса'` in event-type label map | Would render |
| 15 | [database/factories/TransactionFactory.php:18](database/factories/TransactionFactory.php:18) | `'fee'` listed as possible random type | Factory fake only |
| 16 | [app/Services/WalletService.php:56](app/Services/WalletService.php:56) | `debit(...)` comment: "Used for: withdrawals, fees." `debit` is type-agnostic — a fee call would be one line away. | Generic utility |

**Summary:** Transaction type `fee` + LoanEvent type `fee_applied` are
plumbed end-to-end (DB → model → Filament → API → Vue) as *receivers* of
data, but nothing on the producer side emits them.

### 2.2 EXISTS BUT DORMANT — scaffold only

| # | Observation |
|---|---|
| D1 | **No service ever writes `Transaction::TYPE_FEE`.** Grep on `TYPE_FEE\|'fee'` across `app/Services/**` returned zero call sites. |
| D2 | **No service ever writes `LoanEvent::TYPE_FEE_APPLIED`.** Grep on `TYPE_FEE_APPLIED\|'fee_applied'` across `app/Services/**` returned zero call sites. |
| D3 | **[WithdrawalService::approve()](app/Services/WithdrawalService.php:48) does NOT charge a fee.** It calls `WalletService::debitReserved(..., TYPE_WITHDRAWAL, ...)` and stops there — no second debit for a fee, no netting, no config lookup. |
| D4 | **[AUDIT_REPORT_PHASE_F1.md:57](AUDIT_REPORT_PHASE_F1.md:57) explicitly documents this as known limitation L5** — "No origination/service/early-repayment fees. `Transaction::TYPE_FEE` enum exists, never written. → F4 (`fee_applied` enum slot reserved)." Plan was always to deliver in F4. |

### 2.3 MISSING — nothing in place

| # | Observation |
|---|---|
| M1 | **No `Fee*` model.** No `app/Models/Fee.php`, `FeeSchedule.php`, `FeeConfig.php`. |
| M2 | **No `Fee*` service.** No `app/Services/FeeService.php`, `FeeCalculator.php`. |
| M3 | **No `Fee*` Filament resource or page.** No admin surface to configure fee amounts/rules. |
| M4 | **No fee-related `platform_settings` rows.** Grep for `fees_enabled`, `fee_default`, `fee_amount`, `fee_percent` returned only the HANDOFF_F4.md mentions — no actual seed data or migration. |
| M5 | **No fee-related migration.** No `*fee*.php` in `database/migrations/`. No fee column on any existing table. |
| M6 | **No fee-related test.** No test file under `tests/Feature` or `tests/Unit` references `fee` (other than the factory-fake "fee" appearing as a random type in generic `Transaction` tests). |
| M7 | **No fee-related DECISIONS.md entry.** DECISIONS.md has F1/F2/F3 sections but no F4. |
| M8 | **No fee-related notification class.** No `FeeChargedNotification`, etc. |

### 2.4 User-facing PROMISES without implementation

| # | Location | Promise |
|---|---|---|
| P1 | [resources/js/components/landing/FaqSection.vue:24](resources/js/components/landing/FaqSection.vue:24) | **"При теглене се начислява минимална такса."** — Landing-page FAQ says withdrawals incur a minimum fee. |
| P2 | [resources/js/components/ChatbotWidget.vue:15](resources/js/components/ChatbotWidget.vue:15) | **"При теглене се начислява минимална такса от 2.50 €."** — Chatbot tells users the withdrawal fee is 2.50 €. |

These are **marketing commitments** already displayed to visitors of
vamaasset.bg. Code does not implement them. Either F4 must deliver them
(partial or full) or the copy must be removed before production.

---

## 3. Recommended scope: **GAP-FILL**

Per handoff section 5, the client decision was:
> Q5: "E — infrastructure ready, disabled by default in v1."

The scaffolding matches the Q5 decision partially:
- Transaction + LoanEvent type enums are ready. ✅
- Display surfaces (Filament badges, Vue labels) are ready. ✅
- **Feature-flag row in `platform_settings` is MISSING.** ❌
- **Fee-calculation + fee-application code is MISSING.** ❌
- **Admin configuration surface (Filament row / dedicated page) is MISSING.** ❌

"Disabled by default" implies a kill-switch that can be flipped ON. No
such switch exists today — there is nothing to flip. **GAP-FILL scope
completes the feature-flag wiring, the fee-calculation engine, and the
one integration that the FAQ/chatbot already promises (withdrawal fee).**

Recommended F4 to leave UNCHANGED on first pass:
- The frontend label maps, badge colors, reconciliation aggregation —
  they already work for `fee` data and need no modification.
- The `TYPE_FEE` + `TYPE_FEE_APPLIED` constants — keep them.
- Pre-expanded CHECK enum on `loan_events.event_type` — no migration.

---

## 4. Open questions for user (block Step 1)

These must be answered before migrations ship. Mentor decisions needed on
items marked **(M)**.

### Scope

1. **Which fee categories does v1 need?**
    - (a) Withdrawal fee (publicly advertised — P1, P2 above).
    - (b) Origination fee (charged to borrower; platform records, admin
      collects off-platform).
    - (c) Service / management fee (periodic; monthly or annual).
    - (d) Early-repayment fee (borrower incentive discouragement).
    - (e) Late-payment fee (penalty).
    - (f) Inactivity fee (dormant-account).
    - Client Q5 said "infrastructure ready, disabled by default". Which
      calculators/integrations do we actually BUILD in F4 vs. add
      empty-but-present enum slots only?

2. **Withdrawal fee — flat, percent, or min(flat, %)? (M)**
    - FAQ says "минимална такса" (minimum fee) — implies a floor.
    - Chatbot says "2.50 €" — concrete number.
    - Mintos model: flat per withdrawal (~0.50 €). PeerBerry: flat (1 €).
    - Options: flat 2.50, or flat 2.50 with % above threshold, or % with
      2.50 minimum.

3. **Who pays which fee — investor, borrower, or originator?**
    - Withdrawal fee: obviously investor.
    - Origination: borrower (but platform records on originator's behalf).
    - Late fee: borrower.
    - Service: investor (e.g. % of earnings) OR originator (revenue share).

### Mechanics

4. **Timing — per-transaction or per-batch?**
    - Auto at `WithdrawalService::approve()` vs. admin-entered at
      withdrawal-approval time.

5. **How is the fee recorded as a ledger entry?**
    - Option A: Separate `Transaction` row with `type='fee'`, linked via
      `reference` to the parent (withdrawal, repayment, loan).
    - Option B: Add `fee_amount` column to `transactions` (inline).
    - Option A matches F1/F2/F3 ledger-immutability pattern better — every
      money movement = one Transaction row. Recommend A.

6. **Does the investor see "amount - fee = net" in their wallet math?**
    - If they withdraw 100 €, wallet `available` drops by 102.50 (two
      transactions) OR by 100 with a separate 2.50 fee debit? First is
      cleaner but changes the current UX (only 100 appears in withdrawal
      form).

7. **Virtual-ledger question (M).** Admin sends `amount - fee` to the
   investor externally, keeps `fee` in the business bank account. How
   does the ledger reflect that?
    - Fee `Transaction` debits investor's `available`: ledger-consistent,
      but the platform does NOT have a "platform wallet" bucket to
      receive the fee. The fee just vanishes from investor's balance
      with no corresponding credit. That's **accounting-correct** for a
      virtual ledger (admin's real bank account is the source of truth),
      but we should make that explicit in DECISIONS.md.
    - Alternative: add a virtual "platform" wallet to track fees earned
      → reconciliation-friendly, but adds a new bucket.
    - Mentor preference?

### Policy

8. **Kill-switch scope (M).**
    - One `fees_enabled` master flag?
    - Per-category flags (`withdrawal_fee_enabled`, `origination_fee_enabled`, …)?
    - Per-originator override on origination/late fees?

9. **Is the FAQ/chatbot copy (P1, P2) a *commitment* that F4 must deliver
   BEFORE launch — or can F4 ship with the flag OFF and the copy is
   amended to "безплатно" until a later release?**
    - If commitment → withdrawal fee MUST be enabled in v1 (scope creep
      relative to Q5's "disabled by default").
    - If amendable → F4 can truly ship disabled, client changes the copy
      in a follow-up PR.

10. **Is admin-triggered (F2/F3 pattern) or auto-applied at service layer
    the right model for fees?**
    - Withdrawal fee → auto (fires inside `WithdrawalService::approve`).
    - Origination fee → admin-entered at loan publish (one-shot).
    - Late fee → automated (runs alongside `loans:process-late`).
    - Service fee → cron (monthly).
    - Early-repayment fee → auto inside F3 execute flow.

### Surfaces

11. **Admin UI surface (M).** Options:
    - Reuse `PlatformSettingResource` — already supports int/float/bool
      editing. Fee config goes as rows: `withdrawal_fee_amount`,
      `origination_fee_percent`, etc.
    - OR a dedicated `FeeResource` / `FeeConfigPage` with a grouped form.
    - Recommend the former for consistency with `grace_period_days`,
      `buyback_default_*` etc. — zero new resource files.

12. **Investor visibility.**
    - Does the investor see a fee breakdown in their Transactions page?
      Current `TransactionsPage.vue` filter option already lists "fee";
      the row would just appear once data exists.
    - Do they see a fee warning BEFORE confirming a withdrawal? (UX
      question — "You will receive 97.50 € after a 2.50 € fee").

### Testing

13. **Test coverage expectations.** F3 added 35 tests. F4 scope is
    smaller if we only integrate withdrawal fee:
    - Feature flag ON/OFF behavior.
    - Fee math (bcmath, rounding).
    - `WithdrawalService` integration (fee debit timing + idempotency).
    - Contract guards on any new notification (unlikely per Q5 — no
      notifications).
    - Adversarial metadata whitelist (if `fee_applied` events are
      written and surface on the public loan timeline API).

---

## 5. Proposed step structure (conditional on GAP-FILL confirm)

**All steps are drafts until user answers Q1–Q13 above.**

| Step | Deliverable | Est. effort |
|---|---|---|
| 0 | This audit report + scope confirmation | done (pending user sign-off) |
| 1 | Migration: seed `platform_settings` fee rows with CHECK constraints (value ranges, allowed-key coupling). App-level constants (if any) on `PlatformSetting` helper. DECISIONS.md entry recording: virtual-ledger fee model, flag shape, category decisions from Q&A above. | 0.5 d |
| 2 | `FeeService` (or extend `WalletService` with `chargeFee` depending on mentor direction). Feature-flag guard + bcmath math + `Transaction::TYPE_FEE` writes. Pro-rata NOT applicable (fees are single-user, single-transaction). Pure-function calculation service returns a `FeeResult` DTO. | 1 d |
| 3 | `WithdrawalService::approve` integration (iff withdrawal fee in scope) — one `WalletService::debit(..., TYPE_FEE, ...)` inside the same `DB::transaction` as the withdrawal debit. Idempotency via `reference = withdrawal_request:{id}:fee`. | 0.5 d |
| 4 | Filament: verify `PlatformSettingResource` displays fee rows correctly (should "just work" thanks to polymorphic form — no edits needed). Possibly add a Filament action badge to show fee-config status in the navigation. | 0.25 d |
| 5 | LoanEventResource whitelist: only if we write `fee_applied` events in F4 and they need to surface on the investor timeline. If fees are withdrawal-only, this step is SKIPPED. | 0.25 d (or 0) |
| 6 | Tests: feature-flag on/off matrix, `FeeService` unit tests (math + edge cases), `WithdrawalService` integration tests, contract guard on any public API shape, adversarial whitelist if applicable. Target ~15–25 new tests. | 1 d |
| 7 | Docs: CLAUDE.md Phase F4 section, finalise this report, README note, DECISIONS.md entries for every Q1–Q13 resolution. | 0.5 d |

**Total estimate:** 3.5–4 developer-days for a minimal withdrawal-fee
GAP-FILL. Scales up if Q1 adds more categories.

---

## 6. What this report does NOT change

Zero files modified by this discovery — the only new file is this
document itself. `git status` (outside the worktree) will show this
report as untracked.

No branch created. Handoff recommends `feature/fees-infrastructure`
off `main`; will be created in Step 1 after user scope confirmation.

---

## 7. Next steps — pending user confirmation

1. User reviews this report.
2. User answers Q1–Q13 (mentor consults for (M)-marked items).
3. User confirms: `SKIP` / `GAP-FILL` / `FULL` scope.
4. If `GAP-FILL` or `FULL`: create branch `feature/fees-infrastructure`
   off `main` @ `a30fafc`; start Step 1.
5. If `SKIP`: append a "verified dormant, no action taken" paragraph to
   this report; close F4; proceed to F5 (APR).

---

*End of Step 0 Discovery Report. Awaiting user input.*
