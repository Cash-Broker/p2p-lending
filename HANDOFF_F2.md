# F2 Handoff Document

**Audience:** the next Claude Code session (or human dev) picking up Phase F2 — Buyback Guarantee.

**Author:** the F1 session, on completion of `47d90fb`.

---

## Current state

- **Branch:** `main` (F1 fully merged)
- **Last commit:** `47d90fb docs(f1): CLAUDE.md F1 section, AUDIT report finalized, README operations note`
- **origin/main:** in sync with local
- **Test count:** **305 passing**, 780 assertions, 0 regressions
- **Status:** F1 (Late/Default Automation) **complete and pushed**. Ready for F2.

Quick verification when starting:
```sh
git log --oneline -1                    # → 47d90fb
git rev-parse origin/main               # → 47d90fb53875…
php artisan test --exclude-filter=ExampleTest  # → 305 passed
```

---

## F1 delivered (context for F2)

F1 was the late-detection automation. F2 should reuse the foundations it laid down — the database, command, and notification scaffolding are designed to extend.

### Database (already in place)

| Table / column | Purpose | F2 angle |
|---|---|---|
| `platform_settings` (key/value, typed, audited) | Configurable platform-wide settings | Add buyback defaults here as fallback when an originator hasn't customised |
| `platform_metrics` (key/value, observed) | Cron observability | Add `last_buyback_run_*` metrics |
| `loan_events` (append-only, 3-layer immutable) | Lifecycle log per loan | **Enum already includes** `buyback_triggered`, `buyback_completed`, `went_default` — F2 starts writing them; **no migration needed** for the column |
| `loans.last_late_check_at`, `loans.became_late_at` | Late-state tracking | Read these to determine buyback eligibility (days since became_late_at vs originator's trigger threshold) |
| `amortization_schedules.became_late_at`, `days_late` | Per-installment late tracking | Read for buyback amount calculations |

### Command + scheduler

- `loans:process-late` — daily 03:30 (`bootstrap/app.php`).
- Pattern: `--dry-run` (DB transaction rollback), `--loan=ID`, `--detail`, `--force`, cache lock 10 min, metrics writes per run.
- **F2 hook point:** add a buyback step *after* the existing recovery step in `ProcessLateLoans::runWork()`, OR add a sibling command `loans:process-buyback` running at 03:45 (cleaner separation, same patterns to follow). **Recommend separate command** — simpler to test and toggle independently.

### Notification pattern (reusable for F2)

- `LoanWentLateNotification` shows the established shape:
  - `implements ShouldQueue`
  - Channels `['mail', 'database']`
  - Constructor takes domain entity + value snapshots (never re-computes during email render)
  - **Per-late-period dedupe** via `wasRecentlyNotified()` querying `whereJsonContains('data->became_late_at', …)`
  - Markdown template at `resources/views/emails/loan-went-late.blade.php`
  - **Contract guard test pinned** — `data->became_late_at` MUST stay ISO-8601 string; same pattern needed for any new dedupe key F2 introduces

### Health endpoint pattern

- `/api/health/scheduler` already exposes `last_run_stats` for late check.
- F2 can either:
  - Add `last_run_stats.buyback_*` fields to the existing endpoint (single-pane health), OR
  - Add new `/api/health/buyback-scheduler` endpoint (separate concerns).
  - **Recommend extending the existing endpoint** — external monitors stay configured against one URL.

### Admin UI pattern

- `LoanHealthOverview` widget (sibling to `StatsOverview`) — add buyback stats here.
- `PlatformSettingResource` — type-aware ghost-field pattern; reusable for buyback defaults.
- `LoanEventsRelationManager` — already renders `buyback_triggered`/`buyback_completed` event types from the pre-expanded enum (BG labels in place, only colour scheme picked).

### Documentation foundation

- `CLAUDE.md` has a "Phase F1 — Late/Default Automation" section with the late-detection mechanism, recovery rule R1, **and a STATUS_DEFAULT note flagging F2 as the consumer of `recovery_skipped_default` metric**.
- `DECISIONS.md` carries 4 entries (2FA deferral, CSP roll-out, SEPA-only IBANs, admin role consolidation). Add F2 decisions here.
- `AUDIT_REPORT_PHASE_F1.md` lists 12 known limitations (L1–L12), 3 of which are explicit F2 hooks (L1 default automation, L2 buyback honour-flow, L8 admin role).

---

## F2 Scope

Buyback Guarantee implementation per client decisions:

- **Q1 — Buyback coverage:** *configurable per-originator*. Possible values: `principal_only`, `principal_plus_interest`, `principal_plus_accrued_interest`. Recommended default if originator doesn't set: `principal_plus_interest`.
- **Q2 — Buyback trigger:** *configurable per-originator* (days late before buyback fires). Recommended default: **60 days** (industry standard for P2P; Mintos/PeerBerry pattern).

Existing `originators.buyback` boolean stays as the master switch ("does this originator offer buyback at all?"). New columns extend it with HOW buyback works.

---

## F2 Steps (recommended order)

### Step 0 — Discovery
- Read CLAUDE.md "Phase F1" section + `app/Console/Commands/Loans/ProcessLateLoans.php` + `app/Services/Loans/*` to understand the patterns.
- Re-confirm with user: defaults (60 days, principal_plus_interest), the 3 open questions below, separate command vs extend.
- Write `AUDIT_REPORT_PHASE_F2.md` discovery section.

### Step 1 — Migrations
- `originators` add columns:
  - `buyback_enabled` BOOLEAN — alias of existing `buyback` or rename for clarity (decide with user).
  - `buyback_coverage` VARCHAR(32) — enum-as-string with CHECK: `principal_only` | `principal_plus_interest` | `principal_plus_accrued_interest`. Nullable → falls back to platform default.
  - `buyback_trigger_days` UNSIGNED SMALLINT — nullable → falls back to platform default. CHECK 0..365.
- `platform_settings` SEED:
  - `buyback_default_coverage` = `principal_plus_interest`
  - `buyback_default_trigger_days` = `60`
- `loans.status` enum extension: add `bought_back` value. Update `Loan::ALLOWED_TRANSITIONS`:
  - `late → bought_back` (path 1)
  - `default → bought_back` (path 2)
  - `bought_back → repaid` (terminal — originator finishes external collection)
- `investments.status` — currently no `status` column; investments table is append-only with no status. Decide: add a `status` column, OR derive "bought_back" from `loan.status` reads (simpler, no migration). **Recommend deriving** — keeps investments as immutable position records.
- `transactions.type` — `Transaction::TYPES` constant already exists; add `'buyback_principal'` and `'buyback_interest'` (NOT just `'buyback'` — the wallet-bucket math differs). Update the DB CHECK if any (verify in `2026_03_29_100006_create_transactions_table.php`).
- **Reuse `loan_events` for buyback timeline** — DON'T create `buyback_events`. The enum already has `buyback_triggered`/`buyback_completed`; the table is append-only with the same triple-layer immutability you'd want anyway.

### Step 2 — Services
- `BuybackEligibilityService`:
  - Query loans where `status='late'` AND `became_late_at <= today - originator.buyback_trigger_days`.
  - Originator-level filter: only `originators.buyback_enabled=true`.
  - Returns collection of eligible loans.
- `BuybackCalculationService`:
  - Per loan: outstanding principal = `Σ scheduled.principal − Σ paid.principal`.
  - Per coverage type:
    - `principal_only` → outstanding principal.
    - `principal_plus_interest` → + scheduled interest of past-due unpaid installments.
    - `principal_plus_accrued_interest` → + day-count-prorated interest since last payment (more complex; may defer to F2.1).
  - Per investor distribution: pro-rata by `investment.amount / loan.funded_amount`. Use `RepaymentService` "last investor gets remainder" pattern to avoid penny loss. **Re-read** `app/Services/RepaymentService.php` for the pattern.
- `BuybackExecutionService`:
  - Wraps the actual money movement.
  - **All in DB::transaction with lockForUpdate** on loan + investments + wallets.
  - Idempotency key: similar to `Investment.idempotency_key` (existing pattern in `InvestmentService`).
  - Per investor: `WalletService::repayPrincipal` for buyback principal, `::repayInterest` for buyback interest. Use new `Transaction::TYPE_BUYBACK_*` types so reconciliation can distinguish them.
  - Originator-side: F1 has no originator wallet. **Decide**: track originator buyback obligations as platform-wide liability (running total in `platform_metrics`?), OR add `originator_balances` table. Open question — see below.
  - Writes `loan_events`: `buyback_triggered` (decision) + `buyback_completed` (after distribution).
  - Transitions loan: `late → bought_back` or `default → bought_back`.

### Step 3 — Command integration
- Create `app/Console/Commands/Loans/ProcessBuybacks.php` mirroring `ProcessLateLoans.php` shape (same flag set, same dry-run wrapping, same cache lock, same metrics).
- Schedule daily at **03:45** (after `loans:process-late` at 03:30 completes any recovery-skipped-default surfacing).
- Metrics: `last_buyback_run_at`, `last_buyback_status`, `last_buyback_loans_processed`, `last_buyback_total_principal`, `last_buyback_total_interest`, `last_buyback_notifications_queued`.

### Step 4 — Admin UI (Filament)
- `OriginatorResource` form: add `buyback_enabled` toggle, `buyback_coverage` select, `buyback_trigger_days` numeric (with helper text showing platform default when null).
- `LoanHealthOverview` widget: add "Eligible for buyback today" stat, "Bought back this month" stat.
- `LoanEventsRelationManager` — already renders the new event types; verify colours match (info/success per F1 setup).
- New `BuybackResource`? Probably overkill — the loan detail page + transactions list already shows the data. **Decide with user.**

### Step 5 — Investor UI
- API: `LoanResource` add `is_eligible_for_buyback` and `buyback_coverage` (read-through to originator), null when originator has buyback disabled. Sanitize via the existing `LoanEventResource` whitelist — extend to include `buyback_amount`, `buyback_coverage`, etc. (**Update the contract guard test in `LoanEventsApiTest::test_metadata_whitelist_filters_non_public_keys`** — it currently asserts only F1 keys.)
- Vue `PortfolioPage.vue`: add "Bought back" loan section (collapsible). Banner copy update for buyback flag.
- Vue `InvestmentDetailPage.vue` timeline: render `buyback_triggered` and `buyback_completed` events with new BG labels (already in `eventTypeLabels` map — verify).

### Step 6 — Email notification
- `LoanBoughtBackNotification` (mirror `LoanWentLateNotification`):
  - ShouldQueue, mail + database channels.
  - Constructor: Loan, buybackAmount, buybackPrincipal, buybackInterest, becameBoughtBackAt.
  - Dedupe by `data->became_bought_back_at` (single-shot, but keep the contract for symmetry).
  - **Add a contract-guard test for `became_bought_back_at` ISO format** — same pattern as F1 (`tests/Feature/Notifications/LoanWentLateNotificationTest::test_toarray_includes_became_late_at_as_iso_string_for_rate_limit_contract`). Skip and CI fails on silent format change.
- Markdown template `resources/views/emails/loan-bought-back.blade.php`:
  - **Anonymised — no PII.** No borrower fields, no loan type (per F1 PII hygiene decision).
  - Subject: "Кредит #{id} е изкупен от оригинатора — P2P Invest"
  - Body in Bulgarian. Data table: loan id, buyback amount, principal/interest split, originator name (originator IS public — visible in marketplace).
  - **No speculative copy** about default/recovery (F1 pattern: don't promise features the platform doesn't deliver).

### Step 7 — Tests (target: ~50 new tests)
- `BuybackEligibilityServiceTest` — trigger boundaries (59/60/61 days), originator-disabled skipped, default fallback when originator nulls.
- `BuybackCalculationServiceTest` — per-coverage-type math, distribution penny-precision (3-investor 33.33%/33.34% scenarios), edge cases (loan with no payments, loan fully paid).
- `BuybackExecutionServiceTest` — wallet bucket movements, transaction records, loan_events writes, idempotency, lockForUpdate behaviour.
- `ProcessBuybacksCommandTest` — happy path, dry-run zero writes, --loan filter, --force, idempotency, metrics.
- `LoanBoughtBackNotificationTest` — **contract guards FIRST** (became_bought_back_at ISO), rate-limit, BG content, no PII.
- `LoanEventsApiTest` (existing) — extend whitelist test with adversarial buyback metadata keys.
- Re-run: target 305 → ~355 with no regressions.

### Step 8 — Documentation
- Update CLAUDE.md "Phase F1 — Late/Default Automation" section. Either rename to "Phase F1+F2 — Late/Default + Buyback" or add a new "Phase F2 — Buyback" section.
- Update `AUDIT_REPORT_PHASE_F1.md` known limitations: mark L1, L2, L9 as RESOLVED.
- Create `AUDIT_REPORT_PHASE_F2.md` with completion table + test stats + new known limitations.
- Update README operations: schedule list now includes `loans:process-buyback`. Health endpoint shape may have new fields.

---

## Important constraints from F1 audit work

- **bcmath everywhere for money** — never floats; scale 2 for storage, scale 10 for intermediate (rate, share).
- **DB::transaction + lockForUpdate** for ALL financial operations.
- **Idempotency keys** for repayments — reuse pattern for buyback execution.
- **Whitelist metadata sanitisation** in any API resource exposing event metadata to investors. Opt-in safety.
- **Immutable ledger** — DB triggers + CHECK constraints + app-level guards. Pattern in `Transaction` and `LoanEvent`.
- **3-layer defense standard** for any new append-only table.
- **Anonymisation** — investors must NEVER see borrower PII. Filament admin can; API resources for investors must filter.
- **Notification dedupe contract guards** — every queued notification with a dedupe key needs a pinned test asserting the data shape (saved at least 2 weeks of debugging when F1 added it).

---

## Open questions for F2

These should be the FIRST things asked of the user in Step 0:

1. **Originator funding model.** If originator's buyback obligation exceeds their available platform balance:
   - (a) Refuse buyback, raise alert to admin?
   - (b) Partial buyback (cover what they can), flag remainder as pending?
   - (c) Platform fronts the money (= platform-as-insurer)?
   The choice changes the schema (need an `originator_balances` table or not).

2. **Buyback distribution math under irregular pay history.** A loan might have:
   - 5 scheduled installments
   - 2 paid (months 1–2, on time)
   - 3 unpaid (months 3–5, all late, triggering buyback at month 3+60 days)
   Outstanding principal is clear. Outstanding interest options:
   - (a) Sum of scheduled interest of unpaid installments (clean, ignores accrual since last payment).
   - (b) (a) + day-count prorated interest from last payment date to today (more accurate, more complex).
   - (c) Per coverage choice — `principal_plus_interest` uses (a), `principal_plus_accrued_interest` uses (b).
   **Recommend (c)** as it gives the originator a config knob. Confirm with user.

3. **Post-buyback loan state.**
   - Does the platform continue to track that loan as `bought_back` indefinitely, or does it move to a "closed" state?
   - When the originator eventually collects from the borrower (off-platform), does that money flow back to investors, or does it stay with the originator (since they paid out the buyback)?
   - **Spec'd answer is needed before Step 1** — affects whether `bought_back` is a terminal state or just a way-station.

4. **Buyback trigger semantics — schedule-late vs loan-late.**
   - Is the 60-day clock from `loan.became_late_at` (whole loan), OR
   - From `amortization_schedules.became_late_at` of the OLDEST late schedule (per-installment)?
   - These differ when a loan has been late, recovered, and gone late again.
   - Recommend loan-level (`loan.became_late_at`) because that's what the late-state-machine tracks. Per-installment is more conservative but operationally noisier.

5. **Coverage type wording — is `principal_plus_accrued_interest` the third option needed?** Or simplify to two: `principal_only` and `principal_plus_interest`? Three options means more code paths to test; two is cleaner. Defer to user.

---

## Files to read first (new session)

In recommended order:

1. **`CLAUDE.md`** — has the F1 context section + hook points for F2 (search for "F2"). Also wallet bucket reference, financial logic rules.
2. **`HANDOFF_F2.md`** — this document.
3. **`AUDIT_REPORT_PHASE_F1.md`** — completion table, known limitations L1/L2/L8 are F2-relevant.
4. **`DECISIONS.md`** — 4 prior decisions; add F2 decisions here.
5. **`app/Services/Loans/LateDetectionService.php`** — pattern for the new BuybackEligibilityService.
6. **`app/Services/Loans/LoanStatusUpdaterService.php`** — pattern for state transitions + event writes + recovery rule shape.
7. **`app/Services/RepaymentService.php`** — pro-rata distribution + last-investor-remainder pattern (reuse for buyback).
8. **`app/Services/WalletService.php`** — the only place that touches wallet bucket math; buyback execution will call its `repayPrincipal`/`repayInterest` (or new methods if buyback gets dedicated bucket logic).
9. **`app/Console/Commands/Loans/ProcessLateLoans.php`** — pattern for the new ProcessBuybacks command.
10. **`app/Notifications/LoanWentLateNotification.php`** — pattern for LoanBoughtBackNotification.
11. **`app/Models/LoanEvent.php`** — append-only model with the pre-expanded enum (no migration needed for buyback event types).
12. **`tests/Feature/Notifications/LoanWentLateNotificationTest.php`** — contract guard test pattern (FIRST in file). Mirror it for the buyback notification.
13. **`tests/Feature/Loans/ProcessLateLoansCommandTest.php`** — pattern for the new buyback command test.
14. **`bootstrap/app.php`** — schedule registration; add the new buyback command here.

---

## Workflow expectations

- **Step-by-step approvals.** User reviews after each Step before next. No batch implementation. The F1 session learned that batched commits are fine for clearly-related work (Batch A combined Steps 4+5) but the user wants explicit approval gates between major phases.
- **Show the email template before committing.** F1 had two rounds of copy edits on the late notification template; same will apply to buyback. Render preview, get sign-off, then commit.
- **Defense in depth.** Every new financial column gets a CHECK constraint. Every new append-only table gets DB triggers AND app-level immutability AND CHECK constraints (3-layer pattern).
- **Contract guards for notifications.** Any new notification with a dedupe key needs a contract-guard test pinning the JSON data shape. F1 set this precedent — `test_toarray_includes_became_late_at_as_iso_string_for_rate_limit_contract`.
- **Smoke first, automated tests later.** F1 used throwaway PHP scripts (e.g. `f1_smoke.php`, deleted before commit) to verify end-to-end behaviour during Step 2 before writing the formal Pest/PHPUnit tests in Step 7. Useful pattern for catching `$fillable` and similar bugs early.
- **No production code changes in test commits.** Step 7 commits ONLY contain test files (and at most a single hot-fix if a test surfaces a real bug).

---

## What to watch out for (lessons from F1)

- **`$fillable` on new columns.** F1 added `became_late_at` + `days_late` to `amortization_schedules` migration but forgot the model's `$fillable` array. Eloquent silently dropped the values on `create()`. Smoke caught it; without smoke it would have shipped. **Always update `$fillable` AND `casts()` when adding columns.**
- **MySQL CHECK + FK SET NULL incompatibility.** F1 hit `error 3823` trying to use a SET NULL FK column inside a CHECK constraint. Default Laravel `nullOnDelete()` had to become bare `constrained()` (= RESTRICT). Document the constraint when defining a CHECK that references a FK.
- **Laravel reserves `--verbose`.** F1 had to rename a custom command flag to `--detail` because `-v / --verbose` is the framework log-verbosity flag. Pick non-conflicting names from the start.
- **PHPUnit data providers in PHPUnit 12.** Use `#[DataProvider('method')]` attribute, not `@dataProvider` annotation. F1 hit this in `ValidIbanTest` from Phase 1.
- **Carbon `diffInDays` returns float in 3.x.** Coerce to int with `(int) abs(…)` for day-count math. F1 wraps this in `LateDetectionService::daysBetween()`.
- **MailTrap rate limits SMTP at 1/sec.** When smoke-testing notifications with sync queue + real SMTP, you'll hit 550 errors. Either use `MAIL_MAILER=array` or `Mail::fake()` for local smoke.
- **Dry-run via outer `DB::beginTransaction + rollBack` works** even for nested service `DB::transaction()` calls (they become savepoints). Pattern is sound; reuse for ProcessBuybacks.
- **Worktree gotcha.** This branch was developed in `.claude/worktrees/inspiring-liskov`. The worktree's `vendor/` was a junction to parent's, which broke PSR-4 autoloading (`$baseDir` resolved to parent project). Solution: `composer install` in the worktree to get its own `vendor/`. Alternatively, do F2 in the parent worktree directly to avoid the issue.
- **Composer.lock conflicts on rebase.** If F2 adds packages, expect a merge conflict on `composer.lock` if main has moved (e.g. dependabot bumps, like F1's last rebase). Don't hand-resolve; just delete the file from your branch, re-checkout from main, and re-run `composer require <pkg>` to regenerate.

---

## Recommended first message to user

> "Phase F2 — Buyback. Read HANDOFF_F2.md. Before I start Step 0 (discovery),
> I have 5 open questions from the handoff that I'd like answered:
> [list questions 1–5]. I'll also recommend defaults: 60-day trigger,
> principal_plus_interest coverage, separate `loans:process-buyback` command
> at 03:45. Confirm or amend, then I'll start with discovery."
