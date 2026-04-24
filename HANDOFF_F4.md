# F4 Handoff Document

**Audience:** the next Claude Code session (or human developer) picking up Phase F4 — Fees infrastructure.

**Author:** the F1+F2+F3 session, on completion of F3 merge (`a30fafc` on `main`).

---

## 1. Project context

**Platform:** P2P lending marketplace at **vamaasset.bg**. Investors fund loans originated by licensed financial institutions (originators). The platform is the intermediary — accounting + ledger system. Built in Laravel 13 + Filament 5.4 + Vue 3.

**Solo developer:** Yordan Yordanov (CashBroker / itcashbroker@gmail.com). Works in a single-developer Claude Code flow — each phase audited, committed, merged explicitly via the user.

**Client:** non-technical Bulgarian admin ("клиентката"). She is the **central operational actor**: approves deposits, processes repayments, executes buybacks and early repayments. All significant money movements are admin-triggered through Filament; no borrower-facing flow, no originator-facing flow. Originators are metadata-only (name, buyback config, logo) — no login, no balance tracking on the platform.

**Virtual money model (critical for F4):** the platform is strictly an accounting ledger. ALL money inside the platform is virtual. The admin holds REAL money in an external business bank account. Every money-movement action in the platform mirrors (or anticipates) a real bank transfer she handles externally:

- Investor deposits → investor wires to admin's bank; admin verifies, clicks "Захрани сметка" in Filament → platform credits investor's available bucket.
- Loan repayments → borrower wires to admin's bank; admin verifies, enters amounts in Filament → platform distributes to investors.
- Buyback execution (F2) → originator wires to admin's bank; admin verifies, clicks Execute in Queue.
- Early repayment (F3) → borrower wires to admin's bank; admin verifies, clicks Execute on LoanResource row.

**Fees (F4) operate on the same virtual-ledger model.** Whatever fee logic gets added, the actual money charging / remitting happens off-platform (admin's bank account). Platform records the fee as a Transaction + possibly an event; admin reconciles externally.

---

## 2. Implementation progress

| Phase | Scope | Commits (range on main) | Tests added | Status |
|---|---|---|---|---|
| F1 | Late/Default Automation | ~9 commits, final `47d90fb` | +53 (→ 305) | ✅ on main |
| F2 | Buyback Guarantee | 8 commits, `60e6068` → `9efbbf5` | +66 (→ 371) | ✅ on main |
| F3 | Early Repayment | 6 commits, `4429323` → `a30fafc` | +37 (→ 408) | ✅ on main |
| **F4** | **Fees infrastructure** | — | — | **⏭️ next** |
| F5 | APR (Annual Percentage Rate) | — | — | after F4 |

F3 final metrics: **404 passed + 4 skipped = 408 total tests, 1213 assertions, 0 regressions**.

F3 commit hashes (most recent):
```
a30fafc  docs(f3): step 7 — F3 section в CLAUDE.md + audit finalized
3699ee2  feat(f3): step 6 — comprehensive F3 test suite (~35 tests)
8b9cc92  feat(f3): batch 4+5 — investor timeline + notification + execute wire-up
d57c078  feat(f3): step 3 — Filament LoanResource row action for early repayment
f843583  feat(f3): step 2 — EarlyRepaymentCalculation/ExecutionService
4429323  feat(f3): step 1 — early_repayment_tracking on loans + transaction constants
```

F2 commit hashes:
```
9efbbf5  docs(f2): step 8 — F2 section в CLAUDE.md + audit finalized
eda4045  feat(f2): step 7 — comprehensive F2 test suite (66 new tests)
86b2c2d  feat(f2): step 6 — LoanBoughtBackNotification + email + execute wire-up
074ff0d  feat(f2): batch a (steps 4+5) — admin Buyback Queue + investor API/Vue
ffc0b9a  feat(f2): step 3 — detection command + admin digest + schedule + health
87eb988  feat(f2): step 2 — buyback services + WalletService extensions
60e6068  feat(f2): step 1 — migrations for buyback + app constants
```

F1 final commit:
```
47d90fb  docs(f1): CLAUDE.md F1 section + AUDIT report finalized + README
```

---

## 3. Established patterns (inherited from F1+F2+F3)

F4 MUST follow these patterns — they're enforced by tests across the codebase.

1. **bcmath for ALL money.** Never floats. Storage `decimal(12,2)`; intermediate arithmetic at `scale 2` for amounts, `scale 10` for ratios (e.g. pro-rata shares). `bcadd / bcsub / bcmul / bcdiv / bccomp` are the only arithmetic operators for money values.

2. **`DB::transaction` + `lockForUpdate` for every money-movement service method.** Acquire loan + wallet row locks at the TOP of the transaction; release implicitly on commit/rollback. Never split money movements across two transactions.

3. **Triple-layer defense on append-only tables:**
    - DB triggers (`prevent_transaction_update`, `prevent_loan_event_update`, etc.) using `SIGNAL SQLSTATE '45000'` for immutability.
    - CHECK constraints for value ranges (`chk_wallets_*_non_negative`, `chk_loans_funded_amount_valid`, `chk_loan_events_event_type`, etc.) — defence in depth.
    - Application-level guards (`Model::update()` / `Model::delete()` throwing `LogicException`, `$fillable` allowlist, model `booted()` validations).

4. **Append-only ledger tables.** `transactions` and `loan_events` are both append-only. Financial history is immutable. New event types get pre-expanded in the CHECK enum upfront (see F1 migration `2026_04_23_140004_create_loan_events_table.php` — `fee_applied` is ALREADY in the enum since F1).

5. **Whitelist metadata sanitisation.** `LoanEventResource::PUBLIC_METADATA_KEYS` is the single gatekeeper between admin-internal metadata and investor-visible event history. Every new F4 event metadata key MUST be added to the whitelist if it should surface to investors; otherwise it stays admin-only. Adversarial tests in `LoanEventsApiTest` verify unwhitelisted keys get filtered.

6. **Contract guard tests FIRST in every test file.** Notification classes with dedupe queries have a pinned `test_toarray_includes_*_as_iso_string_for_rate_limit_contract` as the FIRST method — breaking the JSON shape fails CI immediately rather than silently producing duplicate emails in production. Required pattern for any new notification with a dedupe key.

7. **Money first, emails second.** Notifications dispatched AFTER `DB::transaction` commits — never inside. If email fails, money movement stands. If money movement fails, no email queued. Each investor notification wrapped in its own try/catch + log so one bad address cannot starve the rest.

8. **Empirical SQL log tests as regression guards.** Services that batch multiple column writes into a single UPDATE (e.g. `BuybackExecutionService` stamping `bought_back_at` + `status`; `EarlyRepaymentExecutionService` stamping `early_repaid_at` + `early_repayment_amount` + `status`) have a dedicated test that enables `DB::getQueryLog`, runs the operation, and asserts `count(loanUpdates) === 1` with the expected column names in the SQL text. Stops a silent regression to multi-UPDATE from shipping.

9. **Last-investor-remainder pro-rata distribution.** When dividing a total across N investors, compute N-1 shares via `bcmul(total, bcdiv(investor_amount, funded, 10), 2)` and give the LAST investor `bcsub(total, Σdistributed, 2)`. Guarantees Σ(shares) == total exactly, no penny loss. Mirror across `RepaymentService::processRepayment`, `BuybackCalculationService::distribute`, `EarlyRepaymentCalculationService::distribute`.

10. **`forceFill + transitionTo` single-UPDATE batch pattern.** To stamp one or more non-status columns alongside a state-machine transition: `$loan->forceFill([col1 => v1, col2 => v2])` (marks dirty), then `$loan->transitionTo(NEW_STATUS)` (which calls `save()` internally, persisting ALL dirty fields in ONE UPDATE). Keeps `audit_logs` to ONE row per business event. Documented in DECISIONS.md "F3: Single-UPDATE batching pattern".

11. **Structured exceptions / sentinel classes.** Service layer throws typed exceptions that the UI layer catches with specific toast styling:
    - Idempotency hits → sentinel class (e.g. `BuybackAlreadyExecutedException`, `EarlyRepaymentAlreadyExecutedException`) → warning toast.
    - User-facing business violations → `InvalidArgumentException` with a human-readable message → danger toast.
    - Unexpected errors → generic `\Throwable` catch → `Log::error` + generic danger toast.

---

## 4. User confirmation for F4

User stated:

> "fees infrastructure already configured"

This suggests F4 may be partially or fully in place already — possibly from Phase 1 work or an earlier scaffold. The exact scope is **TBD pending Step 0 investigation** (see section 6 below).

The pre-expanded `loan_events.event_type` enum already includes `fee_applied` (added in F1's migration `2026_04_23_140004_create_loan_events_table.php`, line 41 of the enum list). `Transaction::TYPE_FEE = 'fee'` has existed since the original `2026_03_29_100006_create_transactions_table.php` migration. So SOME fee scaffold exists.

---

## 5. F4 initial scope guidance (from client decisions)

Client decision Q5 (recorded): **"E — infrastructure ready, disabled by default in v1."**

Interpretation:
- Fee columns, transaction types, event types already exist in the schema.
- A feature flag (likely a `platform_settings` row) gates whether any fee logic actually fires.
- v1 ships with the flag OFF — no fees charged in production yet. Infrastructure is "ready for flip" when business decides to enable fees.

**F4 scope constraints derived from this decision:**

- **NO cron.** Fees are calculated at transaction time (during deposit approval, withdrawal execution, repayment distribution, etc.), not nightly.
- **NO notifications.** Fees are visible in transaction history / admin reports but do not trigger investor emails. (v1 posture; may change later.)
- **Feature flag pattern:** a `platform_settings` row (probably `fees_enabled = 'false'`) gates the fee application. Services check this flag and short-circuit when disabled.
- **No breaking changes to F1/F2/F3 pipelines.** Fee application is additive — wraps existing services rather than replacing.

**F4 scope may be one of three shapes depending on discovery findings:**

- **SKIP scope** — all fee infrastructure already in place and correct. F4 session verifies, documents, and moves on.
- **GAP-FILL scope** — some pieces exist (enum slots, column reservations) but glue code is missing. F4 session completes the feature-flag wiring + minimal service integration.
- **FULL scope** — infrastructure exists only in name (enum values, commented-out columns). F4 session implements the full fee application path, similar to F2/F3 shape (service + admin UI for fee config + tests).

The Step 0 discovery determines which.

---

## 6. Step 0 discovery tasks (for the new session)

**READ-ONLY investigation.** No migrations, no code writes until findings are reported and scope confirmed with user.

### Searches to run

1. **Grep "fee" across the codebase** (case-insensitive):
    ```sh
    grep -ri "fee" app/ resources/ database/ tests/ config/ bootstrap/
    ```
    Sort matches into: real fee logic vs. unrelated (e.g. `coffee`, `feedback`).

2. **Platform settings inspection** — query the DB:
    ```sql
    SELECT * FROM platform_settings WHERE `key` LIKE '%fee%' OR `key` LIKE '%enable%';
    ```
    Expected: maybe a `fees_enabled` row. Check for any other fee-related keys (defaults, percentages, caps).

3. **Transaction::TYPES scan** — `app/Models/Transaction.php`:
    - `TYPE_FEE = 'fee'` exists since F1 (line ~25).
    - Does it get WRITTEN anywhere in the codebase? `grep "TYPE_FEE\|'fee'"` in `app/Services/**` and `app/Http/**`.
    - Expected: probably dormant (no service writes `TYPE_FEE` transactions currently).

4. **LoanEvent::TYPE_FEE_APPLIED scan** — `app/Models/LoanEvent.php`:
    - `TYPE_FEE_APPLIED = 'fee_applied'` exists (pre-expanded in F1 enum migration).
    - Does any service write this event? `grep "TYPE_FEE_APPLIED\|'fee_applied'"`.
    - Expected: dormant.

5. **Migrations search** — `database/migrations/`:
    - Any file with "fee" in the name or contents?
    - Any columns on existing tables named `*_fee` or `fee_*`?

6. **Filament admin surfaces** — `app/Filament/`:
    - `PlatformSettingResource` — does it list a fees_enabled setting?
    - Any `FeeResource` or fee-related widget?
    - Repayment / deposit / withdrawal forms — do they have a "fee" input?

7. **Frontend** — `resources/js/`:
    - Grep for "fee" / "такса" / "комисиона" in Vue components.
    - Check if PortfolioPage or InvestmentDetailPage displays any fee breakdown.

8. **Models** — `app/Models/`:
    - Any fee-specific model (`Fee.php`, `FeeSchedule.php`)?
    - Any accessor/mutator related to fees on existing models?

9. **Factories + seeders**:
    - `database/factories/` — `FeeFactory.php`?
    - `database/seeders/DatabaseSeeder.php` — any fee seed data?

### Categorise findings

Each fee-related item is one of:

- **EXISTS AND USED** — code is in production code path, has tests. No action needed (verify only).
- **EXISTS BUT DORMANT** — code exists (enum slot, column, comment) but is never invoked. Candidate for gap-fill OR intentional v1-disabled state.
- **MISSING** — nothing in place. Requires full implementation if F4 scope covers it.

### Deliverable

Write **`AUDIT_REPORT_PHASE_F4.md`** (mirror F1/F2/F3 audit report shape):
- File-by-file findings per category.
- Recommended F4 scope: `SKIP` | `GAP-FILL` | `FULL`.
- List of open questions for the user (what fee categories? % or flat? who pays? etc.).
- Proposed step structure if scope = GAP-FILL or FULL.

**Do not start Step 1 migrations until user reviews the audit report and confirms scope.**

---

## 7. Branch setup

```sh
# At the start of the session, in the parent checkout (not any worktree):
cd /path/to/p2p-lending

git checkout main
git pull origin main  # ensure latest; main should be at a30fafc or newer

git checkout -b feature/fees-infrastructure
```

The naming convention `feature/fees-infrastructure` mirrors F1 (`feature/late-default-automation`) and F3 (`feature/early-repayment`). F2's original worktree auto-name was renamed to `feature/buyback-guarantee` before merge — same convention.

**No worktree needed** for a single-developer flow. Work directly in the parent checkout. (F3 used this approach.)

---

## 8. Known context from mentor discussions

### Option B schedule-boundary (F3 precedent)

F3's early-repayment calculation uses scheduled-interest-boundary math instead of day-count accrual (DECISIONS.md "F3: Early repayment — schedule-boundary interest"). The rationale: codebase has zero day-count math; introducing it for one feature adds a new risk domain. Schedule-boundary trades a ~5 EUR overpayment ceiling for dramatically simpler code + zero new math.

**F4 implication:** if fees have any temporal component (daily late fee, monthly service fee), default to a schedule-boundary / snapshot-on-event approach rather than continuous day-count accrual, unless the business case strongly demands precision. Day-count is available as a v1.1 upgrade path for both F3 and F4 together.

### Virtual money model implications for fees

Platform records fees as Transaction rows. Actual money doesn't change hands inside the platform — admin's bank account is the source of truth.

Scenarios:
- **Fee deducted from investor's balance** — the fee is purely a ledger entry. Platform reduces investor's `available` bucket; admin remits externally if appropriate. No real money moves through the platform.
- **Fee added to borrower's repayment** — admin collects an extra amount from the borrower externally; enters it as part of repayment; platform records the fee separately from principal/interest for reporting.
- **Originator fee** — if originator pays a platform fee, it happens off-platform; platform records it for audit.

F4 should NOT attempt to model bank settlements — that's out of scope.

### Admin-triggered operations pattern

F2 buyback and F3 early repayment both established the pattern: **admin verifies off-platform bank transfer → clicks Execute in Filament → platform updates ledger**. Fees might follow the same pattern if any fee application requires admin judgement (e.g. "charge an administration fee for manual repayment entry"). Alternatively, fees may be auto-applied at service-layer transaction time — scope depends on discovery findings.

---

## 9. Key files reference

**Primary docs (read in this order):**
1. **`CLAUDE.md`** — project overview + Phase F1/F2/F3 technical reference sections. Lines:
    - Phase F1: `## Phase F1 — Late/Default Automation` (starts around line 142).
    - Phase F2: `## Phase F2 — Buyback Guarantee` (starts around line 303).
    - Phase F3: `## Phase F3 — Early Repayment` (starts around line 549).
    - Also: `Financial Logic — CRITICAL` (bcmath, wallet buckets, transaction immutability), `Testing — MANDATORY` (test layout + coverage expectations), `Design Style` (Filament colours, Vue palette).
2. **`HANDOFF_F4.md`** — this document.
3. **`DECISIONS.md`** — running log of architectural decisions. F3 entries at top (schedule-boundary, no new status, single-UPDATE batching). F2 entries below. F1 entries (2FA deferral, CSP, SEPA-only IBANs, admin role consolidation).
4. **`AUDIT_REPORT_PHASE_F3.md`** — F3 implementation report. Completion table + test coverage + known limitations + pre-deploy checklist + Q1–Q7 resolution. F3-L2 self-protection detail is the "operational UX limitation, zero financial damage" classification.
5. **`AUDIT_REPORT_PHASE_F2.md`** — F2 implementation report with similar shape. Relevant for the F2→F3 execution pattern template.
6. **`AUDIT_REPORT_PHASE_F1.md`** — F1 implementation report. Known limitations L1/L2 marked RESOLVED-in-F2 where applicable.

**Source-code patterns to study (same order of importance):**
1. `app/Services/Loans/EarlyRepaymentExecutionService.php` — most recent F3 execution service, cleanest template for admin-triggered F4 actions if fees need one.
2. `app/Services/Loans/EarlyRepaymentCalculationService.php` — calculation + pro-rata distribution pattern.
3. `app/Services/WalletService.php` — all wallet bucket changes go through here. F4 will likely extend with new methods (e.g. `chargeFee`).
4. `app/Filament/Resources/LoanResource.php` — row-action pattern with triple-catch + modal content closure.
5. `app/Notifications/LoanBoughtBackNotification.php` + `EarlyRepaymentReceivedNotification.php` — notification patterns (if F4 ends up notifying, which per the user's decision it probably does NOT for v1).
6. `app/Models/Loan.php` — state machine (unlikely F4 modifies), `$fillable`, `casts`.
7. `app/Models/Transaction.php` — `TYPE_FEE` constant. Scan to see if it's written anywhere currently.
8. `app/Models/LoanEvent.php` — `TYPE_FEE_APPLIED` constant.
9. `app/Models/PlatformSetting.php` — feature flag pattern (`late_check_enabled`, `buyback_check_enabled`). F4's `fees_enabled` (if chosen) follows this shape.

**Test patterns:**
- `tests/Unit/Services/EarlyRepaymentExecutionServiceTest.php` — happy path + failure paths + rollback + single-UPDATE + metadata.
- `tests/Unit/Notifications/EarlyRepaymentReceivedNotificationTest.php` — contract guards first.
- `tests/Feature/LoanResourceEarlyRepaymentActionTest.php` — Filament action integration (visibility matrix + modal + Notification::fake).

---

## 10. Outstanding pre-existing items (NOT F4 blockers)

- **F3-L2 — schedule generation inconsistency.** Admin's Filament EditLoan form's `status` Select dropdown can set a loan to `active` without going through `Loan::transitionTo()` → `AmortizationService::generateSchedule()` is silently skipped → inconsistent DB state (Loan #5 in test DB is an example). F3 self-protects (calculator throws on empty unpaid schedules → modal error panel → danger toast), so zero financial damage. Tracked for separate follow-up commits AFTER F4 — user deferred to Phase 2 infrastructure audit. Three-tier fix proposed in `AUDIT_REPORT_PHASE_F3.md` Pre-F3 Blocker Investigation section: (1) `loans:backfill-missing-schedules` artisan, (2) remove Status select from form + route transitions through explicit action buttons, (3) seeder + factory audit.

- **`stoic-tesla` worktree leftover.** Located at `.claude/worktrees/stoic-tesla` on branch `claude/stoic-tesla`. Contains business artifacts unrelated to F1/F2/F3 (95 KB `build_docx.cjs` Node script + 67 KB `P2P_Invest_Pravna_Dokumentaciya.docx` Bulgarian legal documentation PDF). User deferred cleanup during F2 Step 7; still present. Not F4 scope.

- **Stale local branches.** 13 branches in `git branch` output: `audit/phase-2-financial`, `claude/awesome-jepsen`, `claude/exciting-swanson`, `claude/flamboyant-shirley`, `claude/funny-dhawan`, `claude/inspiring-liskov`, `claude/interesting-hermann`, `claude/nifty-kirch`, `claude/stoic-tesla` (still tied to the worktree), `dashboard`, `feature/late-default-automation`, `fillament`, `security/phase-1-remediation`. All `behind main` after F1/F2/F3 merges. Housekeeping candidate — not F4 blocker.

- **Empty `reverent-merkle-671306` directory.** At `.claude/worktrees/reverent-merkle-671306` — an empty directory left over from the F2 worktree that couldn't be `rmdir`-d due to a Windows file handle held by the bash shell's original CWD. Will clear on its own when the shell session ends. Worktree metadata already removed from git (not in `git worktree list`).

---

## 11. Commit conventions

Follow the F1/F2/F3 pattern:

```
feat(f4): step N — short imperative description
docs(f4): step N — short imperative description
chore(f4): short description  (for non-implementation commits like this handoff)
```

Examples from history:
- `feat(f3): step 1 — early_repayment_tracking on loans + transaction type constants`
- `feat(f3): step 6 — comprehensive F3 test suite (~35 tests in 5 files)`
- `docs(f3): step 7 — F3 section в CLAUDE.md + audit finalized + DECISIONS.md + README`

Commit body: multi-paragraph describing WHAT changed + WHY + any notable decisions. End with:
```
Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

**Never amend a commit that has been pushed to origin/main.** Amend freely within the feature branch before the final push.

---

## 12. Recommended first message to user (for new session)

> "Phase F4 — Fees infrastructure. Read HANDOFF_F4.md in the repo root.
> Starting Step 0: read-only investigation per the handoff's section 6
> to categorise existing fee-related code as EXISTS-AND-USED,
> EXISTS-BUT-DORMANT, or MISSING. Will write findings to
> AUDIT_REPORT_PHASE_F4.md and report back before proposing scope
> (SKIP / GAP-FILL / FULL). Awaiting user confirmation of that scope
> before Step 1 migrations."

---

## Good luck, next session 👋

— the F1+F2+F3 session (wrapped on `a30fafc`)
