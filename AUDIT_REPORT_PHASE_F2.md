# Phase F2 — Buyback Guarantee: Implementation Report

**Branch:** `claude/reverent-merkle-671306` (worktree off `main`)
**Base commit:** `47d90fb` (F1 fully shipped — 305 tests passing, 780 assertions)
**Session start:** 2026-04-23
**Preceding handoff:** [HANDOFF_F2.md](HANDOFF_F2.md)

## Decisions confirmed by client (2026-04-23)

| Q | Decision | Rationale |
|---|---|---|
| Q1 | Refuse buyback + alert admin when originator lacks sufficient balance. TODO hook for partial-buyback in v1.1. | Safer for v1, binary behaviour, signals originator health issues. |
| Q2 | `principal_plus_interest` = sum of scheduled interest of UNPAID installments (no accrued-since-last-payment math). | Only 2 coverage options, not 3. Day-count convention is a business decision not yet made. Predictable for investors. |
| Q3 | `bought_back` is a **TERMINAL** state. No `bought_back → repaid` path. Originator post-buyback collection is off-platform, out-of-scope. | Investors already paid out; platform owes nothing further. `bought_back → repaid` would confuse investors reading loan history. |
| Q4 | Trigger clock starts from **`loan.became_late_at`** (whole-loan), not per-installment `became_late_at`. | Consistent with F1 state-machine; avoids operational noise on recovery→late re-entry. |
| Q5 | **2** coverage options only: `principal_only` \| `principal_plus_interest`. | Fewer code paths, client didn't distinguish accrued-interest concept. |

## Approved configuration defaults

| Setting | Default | Override at |
|---|---|---|
| `buyback_default_trigger_days` | 60 | per-originator via `originators.buyback_trigger_days` (nullable → fallback) |
| `buyback_default_coverage` | `principal_plus_interest` | per-originator via `originators.buyback_coverage` (nullable → fallback) |
| `buyback_check_enabled` | `true` | platform kill-switch for the detection cron (admin Execute path unaffected) |
| Cron schedule | `03:45` daily, after `loans:process-late` at 03:30 | — |
| Command name | `loans:detect-buyback-eligible` (renamed from the initial `loans:process-buyback` plan — detection only; execution is admin-clicked, not cron) |  |
| Notification classes | `LoanBoughtBackNotification` (investor), `BuybackEligibleAdminNotification` (admin digest) | — |

## Completion summary

| Step | Description | Commit | Status |
|---|---|---|---|
| 0 | Discovery (this document, Section 26 adds the post-scope-revision verification) | — | ✅ |
| 1 | Migrations (originators 2 cols, loans 5 cols, platform_settings 3 seeds) + app constants + DECISIONS.md entry | `60e6068` | ✅ |
| 2 | Services (BuybackEligibility, BuybackCalculation, BuybackExecution) + WalletService buyback methods + DTOs + exception | `87eb988` | ✅ |
| 3 | Command `loans:detect-buyback-eligible` + admin digest notification + schedule entry + health endpoint buyback block | `ffc0b9a` | ✅ |
| 4+5 (Batch A) | Filament Buyback Queue Page + OriginatorResource form/table + LoanHealthOverview widget extension + investor API/Vue | `074ff0d` | ✅ |
| 6 | `LoanBoughtBackNotification` + BG email template + BuybackQueue Execute wire-up + copy updates | `86b2c2d` | ✅ |
| 7 | 66 new tests (~57 effective; 2 skipped with documented rationale) + SchedulerHealthController disabled-ignored amendment | `eda4045` | ✅ |
| 8 | CLAUDE.md F2 section + this audit finalised + F1 audit L1/L2 cross-refs + README ops update + DECISIONS.md pass | (this commit) | ✅ |

## Test coverage (final)

| Metric | F1 baseline | F2 final | Delta |
|---|---|---|---|
| Tests passed | 305 | **369** | +64 |
| Tests skipped (documented) | 0 | **2** | +2 (null-path placeholders for F1 symmetry) |
| **Total** | 305 | **371** | +66 |
| Assertions | 780 | **1054** | +274 |
| Files in tests/ | 25 | **33** | +8 |
| Regressions on F1 tests | — | **0** | — |
| Suite duration (local MySQL) | ~260 s | ~246 s | stable |

### New test files

| File | Tests | Focus |
|---|---|---|
| `tests/Unit/Services/BuybackEligibilityServiceTest.php` | 8 | 5 query filters, originator override, platform fallback, zero-trigger `??` contract |
| `tests/Unit/Services/BuybackCalculationServiceTest.php` | 8 | Coverage math, pro-rata, last-investor-remainder (penny-precision), 24-installment no-drift, multi-position investor sum |
| `tests/Unit/Services/BuybackExecutionServiceTest.php` | 9 | Happy-path, idempotency exception, state-machine, dismissed, zero-total, rollback, loan_event metadata, transition, single-UPDATE |
| `tests/Unit/Notifications/LoanBoughtBackNotificationTest.php` | 10 (2 skipped) | Contract guard (bought_back_at ISO), dedupe (same/different/null-fallback), BG content, no PII, no loan type, ShouldQueue, facade |
| `tests/Feature/Commands/DetectBuybackEligibleCommandTest.php` | 10 | Happy-path, idempotency, dry-run, --loan, --force, cache lock, metrics, digest skip/send, content |
| `tests/Feature/BuybackQueuePageTest.php` | 12 | Access control, nav badge, table filter, Execute/Dismiss/Reactivate actions, perf regression guard |
| `tests/Feature/Api/LoanEventsApiTest.php` (extend) | +2 | Whitelist filters adversarial buyback keys; whitelisted keys pass-through |
| `tests/Feature/Api/SchedulerHealthEndpointTest.php` (extend) | +7 | Worst-of-two scenarios, disabled-ignored rule, structure, backwards compat |

## Known limitations (deliberate; F3/F4 or post-launch hooks)

| # | Limitation | Follow-up |
|---|---|---|
| F2-L1 | **No auto `late → default` transition.** Inherited from F1-L1 — F2 provides a parallel "buyback" exit that works from either `late` or `default`, but does not itself automate the escalation. Admin still flips late→default manually when a loan has no buyback path. | Could be added to F2's detection cron as a secondary rule (e.g. "late > 90 days → default"), but requires a business decision on the threshold. |
| F2-L2 | **Originator funding model is off-platform.** Platform does not track per-originator balance; admin verifies payment externally (bank account) before clicking Execute. Simplifies v1 to "admin-in-the-loop", postpones the `originator_balances` schema to v1.1+. | Follow the AUDIT Section 23 Q6 discussion if v1.1 wants in-platform balance tracking. |
| F2-L3 | **Only 2 coverage options.** `principal_only` + `principal_plus_interest`. Day-count accrued interest (the original `principal_plus_accrued_interest` option from HANDOFF_F2) is deferred per Q5. | Adding `principal_plus_accrued_interest` is a service-method addition + seed update + whitelist extension. Low risk if business wants it. |
| F2-L4 | **Buyback interest counts as `earned` in the investor's portfolio view.** Per Q13. Same wallet bucket as repayment interest; indistinguishable in the summary aggregate. | Could split `total_earned` into `total_earned_repayments` + `total_earned_buybacks` if investor clarity becomes a request. |
| F2-L5 | **Queue's "Сума при откриване" column is N+1.** One LoanEvent subquery per row — acceptable at v1 admin scale (< 50 rows typical). Performance regression guard in `BuybackQueuePageTest::test_queue_page_does_not_n_plus_one_with_many_loans` bounds the growth at 50 queries for 20 rows. | Optimise with `withLatestOfMany` eager-load subquery if the queue grows. |
| F2-L6 | **Null `bought_back_at` handling in LoanBoughtBackNotification is dead code** (non-nullable constructor type). Fallback kept for F1 symmetry + future admin-backfill scenarios. 2 tests skipped with documented rationale. | Remove only when the fallback is proven unreachable long-term. |
| F2-L7 | **No Vue browser smoke test.** Same constraint as F1 L10 — Vite manifest absent in worktree. Manual browser verification required before production for `PortfolioPage` positive banner, `InvestmentDetailPage` timeline metadata, and the Buyback Queue admin page. | Standard pre-deploy checklist below. |
| F2-L8 | **Admin digest deduplication is queue-retry-only**, not user-intent. Two manual `--force` runs on the same calendar day will BOTH dispatch a digest (different `run_at` timestamps). Defensible — admin may want a fresh view on demand. | If operators report "duplicate same-day email" complaints, add calendar-day coalescing to `wasRecentlyNotified()`. |

## Operational pre-deploy checklist

1. `composer install` — verify `composer.lock` matches what was committed to this branch.
2. `php artisan migrate` — 3 F2 migrations apply on top of F1; verify `SHOW CREATE TABLE originators`, `loans`, `platform_settings` show the new columns + CHECKs.
3. Platform-setting sanity check:
   ```sql
   SELECT `key`, value, type FROM platform_settings WHERE `key` LIKE 'buyback_%';
   ```
   Should return 3 rows (coverage=`principal_plus_interest`, trigger=`60`, enabled=`true`).
4. `php artisan schedule:list` — confirms both `loans:process-late` (03:30) AND `loans:detect-buyback-eligible` (03:45) are listed.
5. Dry-run sanity check against production data:
   ```sh
   php artisan loans:detect-buyback-eligible --dry-run --detail
   ```
6. Filament smoke: log in as admin, navigate to **Финанси → Buyback Queue**. Verify:
   - Navigation badge renders with correct count (0 on a fresh install).
   - Page loads without error; empty state visible.
   - On a late loan past trigger threshold, click Execute → confirm modal shows fresh calc.
7. `curl /api/health/scheduler` from outside the cluster. Expected JSON shape includes `buyback.last_run_at` block. Overall `status` `healthy` after both crons have run once.
8. Queue worker already running (F1 requirement); no new worker needed for F2 (new notifications use the same queue).
9. Manual Vue browser test on `/portfolio` (with a bought-back loan in the DB) and `/invest/:id` (timeline showing buyback events) — F2-L7.

## Step completion order (for replay / forensic review)

```
60e6068 (step 1) →
87eb988 (step 2) →
ffc0b9a (step 3) →
074ff0d (batch A = steps 4+5) →
86b2c2d (step 6) →
eda4045 (step 7) →
<this commit> (step 8)
```

## Section 23 open questions — final resolution

All Q1–Q23 raised during discovery are resolved. Summary:

- **Q1, Q6, Q20:** retired by scope revision to admin-in-the-loop model (no `originator_balances`, no "refuse due to insufficient balance" logic).
- **Q2, Q3, Q4, Q5:** approved as recorded in the top-of-file decisions table.
- **Q7:** amortization schedules unchanged on buyback (loan.status is single source of truth).
- **Q8, Q22:** two LoanEvents written per buyback (`buyback_triggered` at detection, `buyback_completed` at execution).
- **Q9:** WalletService gained 2 new methods sharing private helpers with F1 repay methods.
- **Q10, Q17:** email copy approved (Step 6 commit message records the final shape).
- **Q11:** idempotency via `loan.status === 'bought_back'` inside `lockForUpdate`.
- **Q12:** `originators.buyback` kept as-is; no rename.
- **Q13:** `total_earned` aggregates repayment interest + buyback interest.
- **Q14–16:** no-ops (duplicates of Q8/7/11).
- **Q18:** backwards-compat additive health-endpoint shape.
- **Q19:** `buyback_check_enabled` platform setting added; controls cron only.
- **Q21:** admin digest per cron run (not per loan), skipped if 0/0 counts.
- **Q23:** dismiss mechanism adds 3 loans columns (dismissed_at, dismissed_reason, dismissed_by FK).

---

## Step 0 — Current State (Discovery)

This section is a file-by-file reading pass of all touch points F2 will modify, plus the patterns F2 will mirror. The reading was done entirely **read-only** — no file was edited, no migration was planned yet.

### Section 1 — State machine (`app/Models/Loan.php`)

Lines **19–65** define the finite state machine. Current form:

```php
const STATUSES = [
    'draft', 'published', 'funding', 'funded',
    'active', 'late', 'default', 'repaid',
];

const INVESTOR_VISIBLE_STATUSES = [
    'published', 'funding', 'funded', 'active', 'late', 'repaid',
    // NB: 'default' is deliberately hidden from investors — they see `late`
    //     in the UI until/unless F2 exposes a bought-back UX.
];

const ALLOWED_TRANSITIONS = [
    'draft'     => ['published'],
    'published' => ['draft', 'funding'],
    'funding'   => ['funded'],
    'funded'    => ['active'],
    'active'    => ['late', 'repaid'],
    'late'      => ['active', 'default', 'repaid'],
    'default'   => ['repaid'],
    'repaid'    => [],
];
```

**F2 changes required at `Loan.php`:**
- Add `const STATUS_BOUGHT_BACK = 'bought_back';`
- Append `'bought_back'` to `STATUSES`.
- Append `'bought_back'` to `INVESTOR_VISIBLE_STATUSES` (investors must see their bought-back positions in portfolio).
- Extend transitions:
  - `late    => ['active', 'default', 'repaid', 'bought_back']`
  - `default => ['repaid', 'bought_back']`
  - `bought_back => []` — **TERMINAL per Q3**.
- **No** `active → bought_back` (buyback only fires from `late`/`default`; voluntary pre-late buyback is out of scope).
- `FUNDABLE_STATUSES` unchanged — `bought_back` can't be invested in.

**DB layer:** `loans.status` column is `$table->string('status')` (migration `2026_03_29_100003`, line 24). **No CHECK constraint** enforcing the enum — ✅ adding `bought_back` is app-only, no migration needed for the `status` column.

**Auto-generation side effect:** `Loan::transitionTo()` (lines 104–125) auto-generates amortization schedule only on `funded → active`. No side effect on `late|default → bought_back` — ✅ no interference risk.

---

### Section 2 — Originator model (`app/Models/Originator.php` + migration)

Current `$fillable` (lines 15–21): `name, description, website, buyback, logo_path`. Casts `buyback → boolean`.

Migration `2026_03_29_100000_create_originators_table.php`:
```php
$table->string('name');
$table->text('description');
$table->string('website')->nullable();
$table->boolean('buyback')->default(false);
$table->string('logo_path')->nullable();
```

**F2 changes required:**
- **New migration** `2026_04_24_000001_add_buyback_config_to_originators_table.php`:
  ```php
  $table->string('buyback_coverage', 32)->nullable()->after('buyback');
  $table->unsignedSmallInteger('buyback_trigger_days')->nullable()->after('buyback_coverage');

  // CHECK: buyback_coverage ∈ {principal_only, principal_plus_interest} OR NULL
  // CHECK: buyback_trigger_days ∈ [0, 365] OR NULL
  // Skipped under SQLite (same pattern as F1 `chk_grace_period_days_range`).
  ```
- `Originator` model: append fields to `$fillable`; no cast needed (strings/int).
- `OriginatorFactory`: extend to randomise new fields where `buyback=true`.

**Naming decision — flagged below as Q12:** whether to *rename* `buyback` → `buyback_enabled` (HANDOFF suggested) or keep as-is (less churn). **Recommendation below.**

---

### Section 3 — Transaction types (`app/Models/Transaction.php` + migration)

Lines 20–34 define 6 types: `deposit, withdrawal, investment, repayment_principal, repayment_interest, fee`. Migration `2026_03_29_100006` uses `$table->string('type')` — **no DB CHECK**, only the comment lists enum values.

Immutability is enforced via DB triggers in migration `2026_03_29_110003_add_transaction_immutability_triggers.php` (UPDATE + DELETE blocked with `SIGNAL SQLSTATE '45000'`). These are type-agnostic; new types inherit immutability automatically.

**F2 changes required:**
- `Transaction.php`: add constants `TYPE_BUYBACK_PRINCIPAL = 'buyback_principal'`, `TYPE_BUYBACK_INTEREST = 'buyback_interest'`. Append to `TYPES` array.
- **No migration needed** for the `type` column — app-only.
- PortfolioController summary (line 71) currently sums `TYPE_REPAYMENT_INTEREST` for `total_earned`. **F2 decision needed (see Q13):** does a buyback interest portion count as `earned` for investor reporting purposes? Likely yes (same wallet bucket), but the API semantic should be explicit.

---

### Section 4 — LoanEvent enum (`app/Models/LoanEvent.php` + migration)

**F1 already pre-expanded** the `event_type` CHECK enum at migration `2026_04_23_140004` line 31–41. Model constants (`LoanEvent.php` lines 42–43):
```php
public const TYPE_BUYBACK_TRIGGERED = 'buyback_triggered';
public const TYPE_BUYBACK_COMPLETED = 'buyback_completed';
```
✅ **No DB migration needed** for event_type column.

**Status pair CHECK (migration line 100–108):**
```sql
CHECK (
    (from_status IS NULL AND to_status IS NULL)
 OR (from_status IS NOT NULL AND to_status IS NOT NULL
     AND from_status <> to_status)
)
```

**F2 implications:**
- `buyback_triggered` event (decision marker, pre-distribution) → `from_status=NULL, to_status=NULL` (both null branch). OK.
- `buyback_completed` event (post-distribution transition) → `from_status='late'`|`'default'`, `to_status='bought_back'`. OK (different values, self-transition forbidden doesn't apply).
- **Q14 below:** do we actually want two events (triggered + completed), or just one (completed with rich metadata)?

**Triggered_by CHECK:** `'system'` requires `triggered_by_user_id IS NULL`. Buyback from cron is `'system'` + user_id=null. ✅ compatible.

**Immutability:** 3-layer (app + triggers + CHECK) already in place. F2 writes through `LoanEvent::create()` — inherits all guards.

---

### Section 5 — LoanEventResource whitelist (`app/Http/Resources/LoanEventResource.php`)

Lines 30–34 define the investor-facing whitelist:
```php
private const PUBLIC_METADATA_KEYS = [
    'previous_became_late_at',
    'transitioned_to',
    'days_late_at_transition',
];
```
Sanitiser (line 53): `array_intersect_key($metadata, array_flip(self::PUBLIC_METADATA_KEYS))` — opt-in safety. Contract-guarded by `LoanEventsApiTest::test_metadata_whitelist_filters_non_public_keys`.

**F2 changes required:**
- Add buyback keys: `buyback_amount_total`, `buyback_amount_principal`, `buyback_amount_interest`, `buyback_coverage`, `originator_name`, `became_bought_back_at`.
- **DO NOT** expose: originator balance, internal buyback idempotency id, refused/alert state, per-investor distribution details (those are their OWN notification, not the public loan timeline).
- **Extend** `LoanEventsApiTest::test_metadata_whitelist_filters_non_public_keys` with adversarial buyback keys (`originator_internal_id`, `buyback_refused_reason`, `admin_override_note`).

---

### Section 6 — WalletService pattern (`app/Services/WalletService.php`)

Relevant existing methods:
- `repayPrincipal($userId, $amount, $desc, $ref)` — invested → available, writes `TYPE_REPAYMENT_PRINCIPAL` transaction.
- `repayInterest($userId, $amount, $desc, $ref)` — credits available + earned, writes `TYPE_REPAYMENT_INTEREST`.

**Pattern:** each method is DB::transaction-wrapped, acquires `Wallet::lockForUpdate()`, `forceFill` the bucket, creates `Transaction` record. Type is HARDCODED per method — no `$type` parameter.

**F2 design question (Q9):** how to write buyback movements.
- Buyback principal: invested → available (same as repayPrincipal) but transaction type = `buyback_principal`.
- Buyback interest: credit available + earned (same as repayInterest) but type = `buyback_interest`.

**Recommended design:** extract private helpers, expose 4 public methods (2 existing + 2 new):
```php
// Public API stays the same for repayments; add 2 new methods for buybacks
public function buybackPrincipal($userId, $amount, $desc, $ref): Transaction { ... }
public function buybackInterest($userId, $amount, $desc, $ref): Transaction { ... }

// Internal helpers shared by repay/buyback to avoid duplication
private function creditAvailableFromInvested(...) { ... }
private function creditAvailableAndEarned(...) { ... }
```

This avoids touching existing call-sites (zero risk to F1 repayment flow) while keeping DRY.

---

### Section 7 — RepaymentService pattern (`app/Services/RepaymentService.php`)

Key patterns F2's BuybackExecutionService must mirror:

1. **Collect notifications OUTSIDE the transaction** (lines 32–36). Notifications are side effects — if email fails, financial operation must NOT roll back. "Money first, emails second."
2. **Lock loan first** (line 39), then lock investments with `with('user')->lockForUpdate()->get()` (lines 59–62) to prevent N+1 AND race conditions.
3. **Pro-rata distribution with last-investor-remainder** (lines 81–96):
   ```php
   $share = bcdiv($investment->amount, $totalFunded, 10);
   $principalShare = bcmul($principalAmount, $share, 2);  // scale 2
   // ... accumulate distributedPrincipal
   // Last investor: $principalShare = bcsub($principalAmount, $distributedPrincipal, 2);
   ```
   Guarantees sum == total, avoids penny-loss.
4. **Zero-share guard** (line 98): skip investors whose pro-rata rounds to 0.
5. **Reference format** (line 102): `"loan:{$loanId}:investment:{$investment->id}"`. F2 should use `"loan:{$loanId}:buyback:investment:{$investment->id}"` to distinguish at reconciliation time.
6. **Schedule updates inside transaction** (lines 139–143) — in F2 this becomes "do we update schedule status on buyback? See Q15."
7. **Try/catch on notifications** (lines 147–160) — if email send fails, log warning, continue.

---

### Section 8 — InvestmentService idempotency pattern (`app/Services/InvestmentService.php`)

Lines 30–81: the `invest()` method uses:
- `idempotency_key` column on `investments` (migration `2026_04_15_000002`).
- Check **INSIDE transaction** (lines 34–39) to prevent TOCTOU race.
- Catch `UniqueConstraintViolationException` (line 77) for concurrent requests.

**F2 design question (Q16):** idempotency for buyback execution.

Buyback is **one-per-loan** (not one-per-investor-position), so a column on `investments` isn't the right place. Options:

- (a) Use `loan.status === 'bought_back'` as natural idempotency key inside `Loan::lockForUpdate()`. No migration.
- (b) Add `buybacks` table with `idempotency_key` — more ceremony, overkill for one-per-loan semantics.
- (c) Rely on `LoanEvent` presence — check if `buyback_completed` event exists for this loan; if yes, no-op.

**Recommendation: (a)**. The `Loan::lockForUpdate()` + status check is sufficient: parallel runs of the same loan block each other, second run sees `status='bought_back'` and no-ops. Zero migration, zero new column. HANDOFF_F2 mentioned idempotency_key but it was pattern-general — (a) is the minimal application.

---

### Section 9 — Late-detection services (patterns to mirror)

`LateDetectionService.php`:
- Timezone: `Carbon::now(config('app.timezone'))->startOfDay()` (line 49). F2 uses the same for the 60-day trigger.
- Optional `$loanIdsFilter` parameter (line 47) for `--loan=ID` debug flag. F2 mirrors this.
- Per-loan `DB::transaction` (line 64) to keep locks small.

`LoanStatusUpdaterService.php`:
- Returns result buckets as arrays of loan ids (line 57–62). F2 returns `{bought_back: int[], refused_insufficient_funds: int[], skipped_not_eligible: int[]}`.
- Safeguard pattern for default schedules (lines 168–176) — F2's eligibility service has analogous "originator not approved for buyback" / "insufficient originator balance" safeguards, each written to its own bucket.
- Writes `LoanEvent::create(...)` inside the transaction (line 129). F2 does the same.

---

### Section 10 — Command pattern (`app/Console/Commands/Loans/ProcessLateLoans.php`)

Structure (lines 58–321):
- Signature with flags `--dry-run`, `--loan=ID`, `--detail`, `--force` (lines 60–64).
- `handle()` does: flag parse → enabled check → cache lock → runWithDryRunWrapper (lines 72–119).
- `runWithDryRunWrapper()` wraps in `DB::beginTransaction` + `rollBack` for dry-run (lines 127–141). Inner service `DB::transaction()` calls become savepoints. **This pattern F2 mirrors exactly** — same `--dry-run` semantics.
- `runWork()` orchestrates: detect → refresh → transition → notify → metrics (lines 143–309).
- Metrics via `PlatformMetric::record()` (line 316–320).

**F2 command file:** `app/Console/Commands/Loans/ProcessBuybacks.php`.
- Lock key: `'loans:process-buyback'` (separate from F1).
- Same flag set.
- Same dry-run wrapper.
- Same metric-writing pattern with keys `last_buyback_run_at`, `last_buyback_status`, `last_buyback_loans_processed`, `last_buyback_total_principal`, `last_buyback_total_interest`, `last_buyback_notifications_queued`, `last_buyback_refused_insufficient_funds` (see Q6 below).

**Enabled check:** F2 should add `buyback_check_enabled` platform setting (mirror of `late_check_enabled` pattern). Master kill switch.

---

### Section 11 — Notification pattern (`app/Notifications/LoanWentLateNotification.php`)

Relevant design:
- `implements ShouldQueue` (line 62) — never block the command's wall-clock time.
- Constructor takes snapshot values explicitly (lines 66–72) — never recompute at render time.
- `via()` (lines 79–85) returns `[]` to skip when already notified — Laravel's documented opt-out.
- `wasRecentlyNotified()` (lines 100–121) queries `$notifiable->notifications()->where('type', static::class)->whereJsonContains('data->loan_id', ...)->whereJsonContains('data->became_late_at', ...)`.
- `toArray()` (lines 145–155) — keys here are **part of the rate-limit CONTRACT**; changes break dedupe.
- **Contract-guard test** `LoanWentLateNotificationTest::test_toarray_includes_became_late_at_as_iso_string_for_rate_limit_contract` (lines 65–82). Pins the JSON shape.

**F2 notification:** `LoanBoughtBackNotification` with dedupe on `(user, loan, became_bought_back_at)`. **Must have analogous contract-guard test** pinning `became_bought_back_at` as ISO-8601 string.

---

### Section 12 — Email template (`resources/views/emails/loan-went-late.blade.php`)

30 lines of Bulgarian Markdown mail. Structure:
```
@component('mail::message')
# Title

{{ $name }}, …

@component('mail::table')
| Параметър | Стойност |
| — | — |
| Кредит | #{{ $loanId }} |
| Вашата инвестиция | {{ $investmentAmount }} € |
| Дни закъснение | {{ $daysOverdue }} |
| Оставаща ваша главница | {{ $outstandingPrincipal }} € |
@endcomponent

Reassurance line.

@component('mail::button', ['url' => $portfolioUrl])
Виж в портфолиото
@endcomponent

**Important:** disclaimer.

екипът на {{ config('app.name') }}
@endcomponent
```

**Anonymisation guards (pinned by `LoanWentLateNotificationTest`):**
- NO borrower full_name, personal_id, address, phone, income.
- NO loan `type` (removed in F1 per PII hygiene decision — test `test_loan_type_is_not_in_email_per_pii_hygiene_decision`).
- Investor-specific amount values only.

**F2 template:** `resources/views/emails/loan-bought-back.blade.php`.
- Subject: "Кредит #{id} е изкупен от оригинатора — P2P Invest".
- Table rows: Кредит, Изкупена главница, Изкупена лихва, Общо изкупено, Оригинатор (originator name IS public — it's visible in marketplace).
- Reassurance: "Вашите средства вече са на разположение в портфейла." (Terminal state per Q3 — no "we'll keep you updated" promise.)
- **Copy needs client sign-off before commit** — see Q17.

---

### Section 13 — Schedule registration (`bootstrap/app.php`)

Lines 9–28:
```php
->withSchedule(function (Schedule $schedule): void {
    $schedule->command('ledger:reconcile --notify')
        ->dailyAt('03:00')->withoutOverlapping()->runInBackground();
    $schedule->command('loans:process-late')
        ->dailyAt('03:30')->withoutOverlapping(60)->runInBackground()
        ->appendOutputTo(storage_path('logs/loans-process-late.log'));
})
```

**F2 change:**
```php
$schedule->command('loans:process-buyback')
    ->dailyAt('03:45')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/loans-process-buyback.log'));
```

---

### Section 14 — Health endpoint (`app/Http/Controllers/Api/SchedulerHealthController.php`)

Current shape (lines 35–67): single-pane with `last_run_stats` for the late check only. Thresholds: healthy ≤26h, warning 26–48h, critical >48h (=HTTP 503).

**F2 extension:** HANDOFF recommended **extending** (single endpoint, single URL for monitors) rather than adding a second endpoint. Shape after F2:

```json
{
  "status": "healthy" | "warning" | "critical",
  "late_check": {
    "last_run_at": "...",
    "minutes_since_last_run": 47,
    "enabled": true,
    "last_run_stats": { ... }
  },
  "buyback": {
    "last_run_at": "...",
    "minutes_since_last_run": 47,
    "enabled": true,
    "last_run_stats": {
      "status": "success",
      "loans_processed": 3,
      "total_principal": "15000.00",
      "total_interest": "1200.00",
      "refused_insufficient_funds": 0,
      "notifications_queued": 12
    }
  }
}
```

Top-level `status` = WORST of the two (late_check.status, buyback.status). HTTP 503 if either is critical.

**Breaking change:** the current response flattens `late_check` fields at top level; moving them under `late_check.*` IS a shape change. **Two options (Q18 below):**
- (a) Restructure (cleaner long-term, breaks any external monitor that parses specific fields).
- (b) Keep `last_run_at`, `minutes_since_last_run`, etc. at top level as alias for `late_check.*`, add `buyback.*` as new block (backwards-compatible but ugly).

**Recommendation: (b)** — backwards-compat aliases. Existing test `SchedulerHealthEndpointTest` stays green without rewrite; F2 adds new asserts for the new block.

---

### Section 15 — Filament admin (`app/Filament/Resources/OriginatorResource.php`)

Current form (lines 25–38):
```php
Forms\Components\TextInput::make('name')->required(),
Forms\Components\Textarea::make('description')->required(),
Forms\Components\TextInput::make('website')->url()->nullable(),
Forms\Components\Toggle::make('buyback')->label('Buyback гаранция'),
Forms\Components\FileUpload::make('logo_path')->image()->maxSize(2048),
```

Table columns (lines 44–49): name, buyback (icon column), loans_count, created_at.

**F2 additions:**
```php
Forms\Components\Select::make('buyback_coverage')
    ->label('Покритие (тип)')
    ->options([
        'principal_only'            => 'Само главница',
        'principal_plus_interest'   => 'Главница + планирана лихва',
    ])
    ->nullable()
    ->helperText(fn () => 'Без избор = платформен default: '
        . PlatformSetting::get('buyback_default_coverage', 'principal_plus_interest')),

Forms\Components\TextInput::make('buyback_trigger_days')
    ->label('Buyback след (дни)')
    ->numeric()
    ->minValue(0)
    ->maxValue(365)
    ->nullable()
    ->helperText(fn () => 'Без стойност = платформен default: '
        . PlatformSetting::get('buyback_default_trigger_days', 60)),
```

Both fields should be `->visible(fn (Get $get) => $get('buyback') === true)` so they hide when buyback toggle is off.

**Table column extension:** add badge showing coverage if set, else "default". Sortable `buyback_trigger_days`.

---

### Section 16 — Filament widget (`app/Filament/Widgets/LoanHealthOverview.php`)

Current stats (lines 43–68):
- Закъснели кредити (count + amount)
- Просрочени кредити
- **"Близо до просрочване"** — F2 placeholder with `'placeholder за Phase F2'` description (line 59)
- Последна late-проверка (relative time)

**F2 changes:**
- Replace "Близо до просрочване" placeholder with real stat: "Eligible for buyback today" (loans passing eligibility but not yet processed — useful for ops to see the pending pipeline).
- Add new stat: "Изкупени този месец" (bought-back count + total amount this calendar month).
- Add `last_buyback_run_at` stat with diffForHumans, mirroring the existing last-late-check stat.

---

### Section 17 — Investor API (`app/Http/Resources/LoanResource.php`, `OriginatorResource.php`, `PortfolioController.php`)

**`LoanResource::toArray()`** (lines 15–42) — F2 additions:
```php
// Expose whether this loan's originator has buyback wired up AND whether
// it's relevant at this loan's current status. Null for bought-back loans
// (the event is in the past; current status is terminal).
'is_eligible_for_buyback' => $this->when(
    $this->relationLoaded('originator') && $this->originator->buyback
        && in_array($this->status, ['active', 'late']),
    true, false,
),
'buyback_coverage' => $this->when(
    $this->relationLoaded('originator') && $this->originator->buyback,
    $this->originator->buyback_coverage
        ?? PlatformSetting::get('buyback_default_coverage'),
),
```

**`OriginatorResource::toArray()`** (lines 12–17) — F2 additions: `buyback_coverage` (resolved with fallback to platform default; null when `buyback=false`). Do NOT expose `buyback_trigger_days` to investors (it's an operational value, not investor-facing).

**`PortfolioController::summary()`** (lines 45–103) — F2 additions:
- SQL: `SUM(CASE WHEN loans.status = 'bought_back' THEN investments.amount ELSE 0 END) as bought_back_amount`
- SQL: `COUNT(DISTINCT CASE WHEN loans.status = 'bought_back' THEN loans.id END) as bought_back_loans_count`
- Response: `breakdown_by_status.bought_back`, top-level `bought_back_loans_count`.
- **Note:** `default_loans_count` was kept at 0 in F1 "for forward compatibility". Same principle — add fields even if initially empty.

---

### Section 18 — Vue (`resources/js/views/PortfolioPage.vue`, `InvestmentDetailPage.vue`)

**PortfolioPage.vue**:
- `typeLabels` / `statusLabels` / `statusClasses` dictionaries (lines 14–23) — add `bought_back` entries. Recommend: `'Изкупен'` label, `'bg-blue-50 text-blue-600'` class (info color, distinct from active/late/default/repaid).
- Late banner (lines 93–117) is conditional on `late_loans_count > 0 || default_loans_count > 0`. F2 should EITHER widen this banner OR add a separate "Buyback" card/banner with different copy ("N от вашите кредити са изкупени от оригинатори — средствата са ви върнати").
- Chart (lines 40–51): add bought_back slice.
- Table: "Статус" column already renders `statusLabels[inv.loan?.status]` — picks up `bought_back` automatically once the label is added.

**InvestmentDetailPage.vue**:
- `eventTypeLabels` map (lines 50–60) ✅ already has `buyback_triggered: 'Buyback стартиран'` and `buyback_completed: 'Buyback завършен'`.
- `eventTypeClass` map (lines 61–65) does NOT have buyback entries. Add:
  ```js
  buyback_triggered: 'bg-blue-50 text-blue-700 ring-blue-200',
  buyback_completed: 'bg-green-50 text-green-700 ring-green-200',
  ```
- Timeline metadata rendering (lines 304–326) — current conditionals cover `days_late_at_transition`, `previous_became_late_at`, `transitioned_to`. Add buyback metadata blocks (`buyback_amount_total`, split principal/interest, `buyback_coverage`).

**Borrower panel (lines 222–249)** — already hides PII via `anonymized_profile`. Originator card shows `buyback` boolean. **Consider:** add `buyback_coverage` label when loan is still pre-buyback so investors see what they'd get if the loan goes late long enough.

---

### Section 19 — Amortization schedules & buyback status

Migration `2026_03_29_100004_create_amortization_schedules_table.php` line 18:
```php
$table->string('status')->default('pending'); // pending, paid, late, default
```
NO DB CHECK constraint. Enum is convention-only.

**F1 writes:** `pending → late → paid` (plus `paid` may be set by admin directly for a pending row via Filament).

**F2 question (Q15):** When a loan transitions `late → bought_back`, what happens to its schedule rows?
- (a) Leave all schedules as-is (`pending`, `paid`, `late`). Loan-level status is the source of truth for "is this bought back". Scheduled future installments STAY as `pending` in the DB (they won't be called by any service because loan is terminal).
- (b) Mark all unpaid (pending/late) schedules as `bought_back` status. Requires:
  - New schedule status value
  - App enforcement (services don't write late/paid to bought_back rows)
- (c) Mark them `paid` with `paid_at = now()` to signify investor-received.

**Risk of (a):** RepaymentService's `processRepayment()` (line 42) validates loan is `active|late` — a bought-back loan can't receive a repayment. So stale `pending` schedules on a bought-back loan are harmless. But admin Filament might be confused seeing "pending" rows on a bought-back loan.

**Risk of (b):** Migrating the enum + extending it means rework across Filament AmortizationSchedulesRelationManager status color map + Vue `scheduleStatusLabels`. Meaningful, not trivial.

**Risk of (c):** `paid` is semantically about the BORROWER paying. Using it for "investor got buyback" overloads the term. Audit confusion risk.

**Recommendation: (a)** — leave schedules alone, loan status is the source of truth. Filament AmortizationSchedulesRelationManager can add a banner when parent loan is `bought_back` saying "Кредитът е изкупен; неплатените вноски са отговорност на оригинатора". Minimum change, minimum risk.

---

### Section 20 — README operational notes (`README.md` lines 52–97)

Current Operations section covers cron, queue worker, health endpoint, timezone. **F2 additions:**
- Verify line in cron verification section: `php artisan schedule:list` should show `loans:process-buyback` in the list.
- Log file: `storage/logs/loans-process-buyback.log` alongside `loans-process-late.log`.
- Health endpoint response shape updated (see Q18 decision on backwards-compat).

---

### Section 21 — Tests baseline (tests/Feature/Notifications/LoanWentLateNotificationTest.php)

**Contract guards pinned:**
- `test_toarray_includes_became_late_at_as_iso_string_for_rate_limit_contract` — pins JSON format of dedupe key.
- `test_toarray_serialises_null_became_late_at_as_null` — pins null handling.

**F2 MUST add equivalents:**
- `test_toarray_includes_became_bought_back_at_as_iso_string_for_rate_limit_contract`
- Rate-limit behaviour tests (3 scenarios: same period → skip, different period → send, null fallback).
- PII absence test (mirror `test_email_carries_no_borrower_pii` — no borrower data, no loan type).
- `ShouldQueue` assertion.

LoanEventsApiTest pattern (lines 93–132): extend `test_metadata_whitelist_filters_non_public_keys` with adversarial buyback keys.

---

## Section 22 — Spec ↔ HANDOFF ↔ code mismatches found during discovery

| # | HANDOFF_F2 claim | Reality in code | Resolution |
|---|---|---|---|
| M1 | "rename `buyback` → `buyback_enabled` for clarity" | Column is used across Filament, OriginatorResource API, PortfolioResource, seeders, tests (25 matches from `grep -i buyback`). Renaming = ripple across 15+ files, no functional gain. | **Keep `buyback`.** Add new columns alongside. See Q12. |
| M2 | "reuse `LoanEvent` enum — no migration" | ✅ confirmed — event_type CHECK migration already includes both `buyback_triggered` and `buyback_completed`. | Aligned. |
| M3 | "`loans.status` enum extension — add `bought_back`" | `loans.status` is `$table->string('status')` with **no DB CHECK**. App-only constant change is enough. | No migration for loans.status. |
| M4 | "`transactions.type` — update the DB CHECK if any" | `transactions.type` is `$table->string('type')` with **no DB CHECK**. App-only. | No migration for transactions.type. |
| M5 | "investments.status — decide add column or derive" | Investments has no status column and no migration for it. Derive from `loan.status`. | ✅ derive — confirmed per Q approved list. |
| M6 | "LoanEventsRelationManager — already renders buyback event types from the pre-expanded enum (BG labels in place, only colour scheme picked)" | ✅ confirmed — file lines 50–51 have BG labels; lines 62–63 map buyback_* → `info` (blue). | Aligned. |
| M7 | "add `last_run_stats.buyback_*` fields to existing /api/health/scheduler" | Current response structure has flat fields at top level (`last_run_at`, `last_run_stats`, etc.). Extending requires either restructure (breaks external monitors that parse specific keys) or backwards-compat wrappers. | See Q18. |

---

## Section 23 — NEW open questions surfaced by discovery (BLOCKING Step 1)

These are decisions required from the client BEFORE Step 1 migration work starts. Each one shapes schema and/or service architecture.

### Q6 — Originator balance / funding model (CRITICAL)

**Problem:** Q1's approved answer is "refuse buyback + alert admin when originator lacks balance". But the codebase has **NO originator balance tracking whatsoever**. Confirmed by `grep originator_balance|originatorBalance|OriginatorBalance` → zero matches (only HANDOFF_F2.md mentions it).

Without a balance model, "refuse because originator can't cover" has no basis — there's nothing to refuse against. Three architectural options:

- **(A) Add `originator_balances` table + Filament admin for manual top-ups.** Admin deposits funds on behalf of originator (bank wire received off-platform → admin credits originator balance). BuybackExecutionService reads balance, debits on buyback, refuses when insufficient. Adds ~1 migration, 1 model, 1 Filament resource, ~1 day of work to scope.
- **(B) No balance tracking — admin-in-the-loop workflow.** Cron fires buyback eligibility check and creates a `buyback_pending` event + admin dashboard task. Admin confirms originator has paid off-platform, then manually triggers distribution. Not fully automated. Breaks the F1 "cron fires end-to-end" model.
- **(C) Skip funding check entirely in v1.** Assume originator always covers. Automated buyback proceeds without balance check. TODO comment for v1.1. Simplest code, but contradicts Q1's "refuse + alert" logic (nothing to refuse against).

**Recommendation: (A).** It's additional scope but matches Q1's intent, keeps full automation, and creates a place for the admin to see originator solvency at a glance — an important fintech panel. Rough scope estimate: +1 day on top of current F2 scope.

**If user picks (C):** Q1 semantically becomes "no check, always execute". Acceptable for v1 if user confirms the simplification.

### Q7 — `amortization_schedules.status` behaviour on buyback

See Section 19 above. **Recommendation: leave schedules alone** (option a); add a banner in Filament; no schema change. Confirm?

### Q8 — Single event or two events per buyback?

See Section 4 above. **Recommendation: two events** (`buyback_triggered` + `buyback_completed`) so ops has "decision made" vs "distribution done" visibility, matching HANDOFF. Slightly more LoanEvent rows but richer audit. Confirm?

### Q9 — WalletService buyback methods: new or parametrised?

See Section 6 above. **Recommendation: new methods** (`buybackPrincipal`, `buybackInterest`) sharing private helpers with existing `repayPrincipal`/`repayInterest`. Zero call-site risk for F1 code, low duplication. Confirm?

### Q10 — Buyback email template Bulgarian copy

Draft below; needs client sign-off **before** Step 6 commit (F1 had 2 rounds of copy edits on the late notification, same precedent applies):

> **Subject:** Кредит #{{ $loanId }} е изкупен от оригинатора — P2P Invest
>
> Здравейте, {{ $name }},
>
> Оригинаторът **{{ $originatorName }}** изкупи кредит **#{{ $loanId }}** в който имате инвестиция. Вашата част е разпределена в портфейла ви.
>
> | Параметър | Стойност |
> | — | — |
> | Кредит | #{{ $loanId }} |
> | Оригинатор | {{ $originatorName }} |
> | Покритие | Главница + лихва \| Само главница |
> | Получена главница | {{ $buybackPrincipal }} € |
> | Получена лихва | {{ $buybackInterest }} € |
> | Общо получено | {{ $buybackTotal }} € |
>
> [Виж портфолиото]
>
> **Важно:** Средствата са вече във вашия свободен баланс и можете да ги инвестирате отново или да ги изтеглите.

No reassurance paragraph about "we'll update you further" (per Q3 — terminal state).

### Q11 — Idempotency mechanism for BuybackExecutionService

See Section 8 above. **Recommendation: use `loan.status === 'bought_back'` inside `Loan::lockForUpdate()`** — natural one-per-loan semantics, zero migrations. Confirm?

### Q12 — Rename `originators.buyback` to `buyback_enabled`?

See M1 and Section 2 above. **Recommendation: keep `buyback`**. Rationale: 25 files touch the column; rename is churn for zero functional gain. New columns `buyback_coverage` + `buyback_trigger_days` sit alongside. Confirm?

### Q13 — Does `buyback_interest` count toward investor's `total_earned`?

`PortfolioController::summary` (line 71) sums `Transaction::TYPE_REPAYMENT_INTEREST` for `total_earned`. Under F2, buyback interest is conceptually earnings for the investor (borrower didn't pay, but originator did, and the investor receives interest). Two options:

- (a) Extend the `total_earned` query to `whereIn('type', [TYPE_REPAYMENT_INTEREST, TYPE_BUYBACK_INTEREST])` — investor sees buyback interest as earned.
- (b) Keep `total_earned` = repayment interest only; add new `total_buyback_received` field separately.

**Recommendation: (a)** — the investor's perspective is "I got interest income"; they don't care about the provenance. Still distinguishable in transaction list if needed. Confirm?

### Q14 — Same as Q8 (duplicate — keeping numbering)

(Same as Q8; no action.)

### Q15 — Same as Q7 (duplicate — keeping numbering)

(Same as Q7; no action.)

### Q16 — Same as Q11 (duplicate — keeping numbering)

(Same as Q11; no action.)

### Q17 — Email copy sign-off process

F1 convention: email copy gets client review BEFORE Step 6 commits. Can client sign off on the draft at Q10 now, or require an iteration round once I render the preview?

### Q18 — Health endpoint backwards-compat strategy

See Section 14 above. **Recommendation: (b) additive** — keep existing top-level fields, add new `buyback` block. Preserves SchedulerHealthEndpointTest without rewrite; external monitors keep working. Confirm?

### Q19 — Originator `buyback_enabled` platform-wide kill switch?

F1 has `late_check_enabled` platform setting as master kill switch. F2 should add `buyback_check_enabled` the same way. **Default:** `true`. **Recommend: yes, add it**. Confirm?

### Q20 — `buyback_triggered` event when originator refuses (insufficient balance)?

If Q6=(A) and originator balance is insufficient, we refuse and alert. Do we write a LoanEvent for the refusal?

- (a) `buyback_triggered` with metadata `{refused: true, reason: 'insufficient_originator_balance'}` — audit-preserving but whitelist filter needs to hide the reason from investors.
- (b) No LoanEvent on refusal — admin gets dashboard alert only.
- (c) New event type `buyback_refused` — requires CHECK enum migration (HANDOFF claim "no migration" breaks).

**Recommendation: (a)** — reuse existing enum, keep investors blind to refusal (whitelist filter), admin sees the metadata in Filament RelationManager. Confirm?

---

## Section 24 — Risks identified during discovery

- **R1 — `Loan::IMMUTABLE_AFTER_DRAFT` interaction.** `Loan::booted::updating` (lines 75–93) blocks field changes post-draft for `amount, interest_rate, interest_rate_annual, term_months, originator_id, borrower_id, type`. Buyback writes `status` and possibly nothing else on the loan row (all the money goes through wallet/transactions). Should be safe, but verify during Step 2 that BuybackExecutionService doesn't inadvertently dirty an immutable field.

- **R2 — Pro-rata math penny-loss at small principal amounts.** RepaymentService uses the "last investor gets remainder" pattern to guarantee sum = total. BuybackCalculationService must mirror this. Edge case: a buyback of outstanding principal 0.03 € split across 3 investors → after pro-rata math, the last investor gets 0.01, others get 0.01 each — tight but OK. Need dedicated test for 3-investor fractional scenarios.

- **R3 — LoanEvent `occurred_at` ordering vs `created_at`.** Existing code writes `'occurred_at' => now()` on event creation. If BuybackExecutionService writes `buyback_triggered` and `buyback_completed` in the same DB::transaction, both get the same `now()`. The RelationManager default sort is `occurred_at DESC` — they may render in insertion order or alphabetical by id. Trivial; mention in test.

- **R4 — `amortization_schedules.status` not being a CHECK enum.** Means F2 could write a typo like `'boughtback'` or `'bougth_back'` and the DB wouldn't reject. App-level enum + tests is the only guard. This is NOT a new risk — F1 has it too — but worth noting. A CHECK migration could be added in a separate housekeeping PR; not part of F2 scope.

- **R5 — Queue worker requirement.** `LoanBoughtBackNotification` implements ShouldQueue. Same deployment requirement as F1 (queue worker must be running). README already documents it; no new ops burden.

- **R6 — MailTrap rate-limit during manual smoke testing.** F1 lesson carries forward — use `MAIL_MAILER=array` or `Mail::fake()` for local smoke runs on ProcessBuybacks.

- **R7 — BoughtBack inside investments table query performance.** PortfolioController joins loans; `WHERE loans.status = 'bought_back'` is cheap because `loans.status` has an index (migration `2026_03_29_100003` line 28). ✅ no N+1 risk.

---

## Section 25 — Files to modify / create (summary)

### New files

```
database/migrations/2026_04_24_000001_add_buyback_config_to_originators_table.php   [NEW]
database/seeders/                                                                    [update DatabaseSeeder to seed buyback platform settings]
app/Services/Loans/BuybackEligibilityService.php                                     [NEW]
app/Services/Loans/BuybackCalculationService.php                                     [NEW]
app/Services/Loans/BuybackExecutionService.php                                       [NEW]
app/Console/Commands/Loans/ProcessBuybacks.php                                       [NEW]
app/Notifications/LoanBoughtBackNotification.php                                     [NEW]
resources/views/emails/loan-bought-back.blade.php                                    [NEW]

# If Q6=(A) — originator balance model:
database/migrations/2026_04_24_000002_create_originator_balances_table.php           [NEW, conditional]
app/Models/OriginatorBalance.php                                                     [NEW, conditional]
app/Services/OriginatorBalanceService.php                                            [NEW, conditional]
app/Filament/Resources/OriginatorBalanceResource.php                                 [NEW, conditional]

tests/Unit/Loans/BuybackEligibilityServiceTest.php                                   [NEW]
tests/Unit/Loans/BuybackCalculationServiceTest.php                                   [NEW]
tests/Unit/Loans/BuybackExecutionServiceTest.php                                     [NEW]
tests/Feature/Loans/ProcessBuybacksCommandTest.php                                   [NEW]
tests/Feature/Notifications/LoanBoughtBackNotificationTest.php                       [NEW]
```

### Files to modify

```
app/Models/Loan.php                                                                  [+ STATUS_BOUGHT_BACK, transitions]
app/Models/Originator.php                                                            [+ fillable, no cast]
app/Models/Transaction.php                                                           [+ TYPE_BUYBACK_PRINCIPAL, _INTEREST]
app/Services/WalletService.php                                                       [+ buybackPrincipal, buybackInterest]
app/Http/Resources/LoanResource.php                                                  [+ is_eligible_for_buyback, buyback_coverage]
app/Http/Resources/OriginatorResource.php                                            [+ buyback_coverage]
app/Http/Resources/LoanEventResource.php                                             [+ buyback_* to PUBLIC_METADATA_KEYS]
app/Http/Controllers/Api/PortfolioController.php                                     [+ bought_back sum/count]
app/Http/Controllers/Api/SchedulerHealthController.php                               [+ buyback block]
app/Filament/Resources/OriginatorResource.php                                        [+ form fields, table column]
app/Filament/Widgets/LoanHealthOverview.php                                          [replace F2 placeholder with real stats]
resources/js/views/PortfolioPage.vue                                                 [+ bought_back labels/colors/banner]
resources/js/views/InvestmentDetailPage.vue                                          [+ buyback_* event class + metadata rendering]
bootstrap/app.php                                                                    [+ schedule loans:process-buyback]
database/factories/OriginatorFactory.php                                             [+ randomise new fields when buyback=true]
tests/Feature/Api/LoanEventsApiTest.php                                              [extend whitelist test with buyback keys]
tests/Feature/Api/SchedulerHealthEndpointTest.php                                    [+ buyback block asserts]
README.md                                                                            [+ loans:process-buyback in ops checklist]
CLAUDE.md                                                                            [+ Phase F2 section]
DECISIONS.md                                                                         [+ entries for F2 design decisions]
AUDIT_REPORT_PHASE_F1.md                                                             [mark L1, L2 RESOLVED]
```

Total: ~23 modifications + 11 new files (14 if Q6=(A) originator balance model adopted).

---

## Section 26 — Verification step (post-scope-revision, 2026-04-23)

After client confirmed the revised F2 model ("admin-triggered execution, not automatic; originators are metadata-only"), a verification pass read the three other financial flows (repayments, deposits, withdrawals) to confirm that **all existing significant financial actions are admin-triggered through Filament**. This gave the pattern BuybackExecutionService must mirror.

### 26.1 Repayment flow — `app/Filament/Pages/ProcessRepayment.php` (88 lines)

**Pattern:** Filament **Page** (not Resource). Form with loan_id select, principal_amount, interest_amount, optional schedule_id. Submit handler calls `RepaymentService::processRepayment()` directly; shows Filament success/error notification.

Duplicate guard at the Page layer (lines 58–66): rejects re-submission of an already-paid schedule. Service layer also has its own guard (RepaymentService line 53).

**Why a Page (not Resource):** the repayment amount is **arbitrary** — borrower pays whatever they pay. Admin enters the number each time. No balance check, no eligibility calculation.

### 26.2 Deposit flow — `app/Filament/Resources/DepositRequestResource.php` + `DepositService`

Two UI entry points:
- **Header action "Захрани сметка"** (lines 62–90): admin creates-and-approves in one click (for off-platform bank wires that have already landed). Invokes `createRequest(...)` then `approve(...)` back-to-back.
- **Row actions "Approve / Reject"** (lines 92–106): for existing pending rows.

**Service pattern** (`DepositService::approve`, lines 27–60):
1. `DB::transaction` wrapping the whole financial movement.
2. `WithdrawalRequest::where(...)->lockForUpdate()->firstOrFail()`.
3. `WalletService::credit()` (creates Transaction, locks wallet, updates `available`).
4. `$deposit->update(['status' => 'approved', 'admin_note' => "Approved by admin #{$adminId}"])`.
5. **Notification AFTER the transaction committed** (comment line 52: "email failure must not rollback money").

**Signature:** `approve(int $depositRequestId, int $adminId)` — admin_id is an explicit parameter, written into `admin_note`. ✅ This is the **exact pattern** BuybackExecutionService should mirror.

### 26.3 Withdrawal flow — `app/Filament/Resources/WithdrawalRequestResource.php` + `WithdrawalService`

**Hybrid:** investor-initiated (via `POST /api/withdrawals`), admin-approved (via Filament row action).

`WithdrawalService::createRequest` (line 24) runs on investor API — **it reserves funds immediately** (available → reserved) so the investor can't double-spend. No transaction record yet (reservation is a hold).

`WithdrawalService::approve` (line 48): `DB::transaction` + `lockForUpdate` + `WalletService::debitReserved` (creates Transaction from reserved bucket).

Three admin row actions: Approve / Reject / Mark Processed (`processed_at` timestamp once bank has wired).

### 26.4 Confirmation — revised F2 model aligns

✅ **All three financial flows are admin-triggered through Filament.** No webhook, no payment gateway integration. Admin is the single entry point for all significant money movements.

✅ **Pattern to mirror for buyback execution:**
```php
class BuybackExecutionService
{
    public function execute(int $loanId, int $adminId): Loan
    {
        $loan = DB::transaction(function () use ($loanId, $adminId) {
            $loan = Loan::where('id', $loanId)
                ->whereIn('status', ['late', 'default'])
                ->lockForUpdate()
                ->firstOrFail();
            // idempotency: second call sees status='bought_back' and throws
            // or returns early.
            // calculate, distribute, transition, write loan_event, …
        });
        // notifications AFTER transaction committed
        return $loan;
    }
}
```

### 26.5 Flagged nuances for Step 4 (Admin UI)

- Repayment is a **Page** (form-with-submit).
- Deposit/Withdrawal are **Resources** (list-with-row-actions).

**Buyback Queue** is a list with per-row actions → fits the **Resource** or **Page-with-table** mold better than Page-with-form. Three options:
- **(a)** Extend `LoanResource` with filter "Buyback eligible" + row action "Execute buyback". Simplest; reuses existing Resource.
- **(b)** Dedicated `BuybackQueue` Filament Page with an embedded table. Clear nav entry under "Финанси".
- **(c)** New Filament Resource backed by a query-scope on Loan (e.g. `Loan::buybackEligible()`).

**Recommendation: (b)** — matches client's stated "Buyback Queue" terminology, separates cognitive load from the general LoanResource, gives a natural nav entry.

### 26.6 Admin notification pattern

No existing admin-facing `Notification` classes (all 9 in `app/Notifications/` are investor-facing). One admin Mailable exists: `AdminLoginAlertMail.php`.

**Recommendation:** create `BuybackEligibleAdminNotification` (new Notification class), delivered via `mail + database` to all users with `role='admin'`, **as a single digest per cron run** rather than N individual notifications. Digest body lists the eligible loans + CTA to Buyback Queue. Dedupe key: `(admin_user_id, run_timestamp)`. See Q21 below.

### 26.7 Revised file changelist (supersedes Section 25)

**DROPPED** (human-in-the-loop eliminates these):
- ~~`database/migrations/*_create_originator_balances_table.php`~~
- ~~`app/Models/OriginatorBalance.php`~~
- ~~`app/Services/OriginatorBalanceService.php`~~
- ~~`app/Filament/Resources/OriginatorBalanceResource.php`~~

**ADDED** (admin-centric workflow needs these):
- `app/Filament/Pages/BuybackQueue.php` — new Page with embedded eligible-loans table + per-row Execute / Dismiss actions.
- `app/Notifications/BuybackEligibleAdminNotification.php` — digest notification to admins after cron run.

**RENAMED / CLARIFIED:**
- Command name: **`loans:detect-buyback-eligible`** (client's terminology — accurate, since execution is not in the cron).
- Cron schedule stays `03:45` daily.
- Migration on `loans`: add **4** columns (was planned 2):
  - `buyback_eligible_at` timestamp NULL — set by cron.
  - `bought_back_at` timestamp NULL — set by BuybackExecutionService.
  - `buyback_dismissed_at` timestamp NULL — set by admin "Dismiss" action (see Q23).
  - `buyback_dismissed_reason` string(255) NULL — admin note.
- `platform_settings` seeds unchanged: `buyback_default_coverage`, `buyback_default_trigger_days`, `buyback_check_enabled`.

### 26.8 Updated Q-status matrix

| Q | Status | Resolution |
|---|---|---|
| Q1 | **N/A** | No balance check — admin verifies externally before Execute. |
| Q6 | **RESOLVED** | No `originator_balances` table. Human-in-the-loop replaces it. |
| Q7 | Previously approved | Amortization schedules unchanged on buyback. |
| Q8 | **REVISED** | Two events still: `buyback_triggered` on cron DETECTION (not on Execute), `buyback_completed` on execution. See Q22. |
| Q9 | Previously approved | New WalletService methods `buybackPrincipal/buybackInterest`. |
| Q10 | Email draft pending sign-off | Submitted below for client review. |
| Q11 | Previously approved | `loan.status='bought_back'` as idempotency key inside `lockForUpdate`. |
| Q12 | Previously approved | Keep `originators.buyback` as-is; add new columns alongside. |
| Q13 | Previously approved | `buyback_interest` counts toward `total_earned`. |
| Q18 | Previously approved | Additive health endpoint (backwards-compat). |
| Q19 | Previously approved, renamed | `buyback_check_enabled` — toggles the DETECTION cron only. |
| Q20 | **N/A** | No "refused" path — admin simply doesn't click Execute. |

### 26.9 New questions surfaced during verification

These are the ONLY open items remaining before Step 1.

**Q21 — Admin notification: individual per loan or digest per cron run?**

Options: (a) one BuybackEligibleAdminNotification per eligible loan; (b) one digest notification per cron run with count + loan ID list.

**Recommendation: (b)** — digest. Avoids inbox flood if 10 loans become eligible on the same day. Dedupe on `(admin_user_id, cron_run_timestamp)`. Empty-run = no notification.

**Q22 — Does DETECTION cron write a `buyback_triggered` LoanEvent?**

Two options:
- (a) YES — cron sets `buyback_eligible_at` AND writes `buyback_triggered` event with metadata `{eligible_at, days_since_became_late, calculated_buyback_amount_at_detection, coverage_type}`. Full audit even for dismissed/never-executed cases.
- (b) NO — LoanEvent only written when admin clicks Execute (`buyback_completed`). Dismissed loans leave no event trail.

**Recommendation: (a)** — matches LoanEvent "append-only lifecycle log" intent. Forensically useful: if admin executes 3 days later and the calculated amount has drifted, the original `buyback_triggered` event has the at-detection snapshot.

Status pair for `buyback_triggered`: `from_status=NULL, to_status=NULL` (both-null branch of the `chk_loan_events_status_pair` CHECK). No loan transition happens on detection — it's a decision event.

**Q23 — "Dismiss" mechanism on Buyback Queue**

When admin dismisses a loan in the Queue, how do we mark it?
- (a) Clear `buyback_eligible_at = NULL`. Cron on next run will re-set it (loan still late past trigger_days).
- (b) Add `buyback_dismissed_at` + `buyback_dismissed_reason` columns. Queue filters out dismissed rows; cron SKIPS rows with `dismissed_at` set (unless `--force`). Admin can un-dismiss.
- (c) Hybrid: detection writes a new eligible row only if `dismissed_at` is NULL OR was set before today's check; effectively re-prompts dismissed loans after N days.

**Recommendation: (b)** — clean separation, reversible, auditable. +2 columns to the loans migration (net: 4 new columns on loans). Un-dismiss is a simple "Reactivate" row action.

---

## Verdict — Step 0 + Verification complete

Model aligned. Revised F2 scope is **admin-centric human-in-the-loop**:
- **Detection** automated (cron at 03:45; sets `buyback_eligible_at`; digest-notifies admins; writes `buyback_triggered` event per Q22).
- **Execution** manual (admin clicks "Execute" in Buyback Queue; BuybackExecutionService does the full financial flow 1:1 like DepositService/WithdrawalService; writes `buyback_completed` event; queues investor notifications).
- **Dismiss** manual (admin clicks "Dismiss"; loan drops off Queue; cron skips; reversible).

No originator balance subsystem. No webhook. No payment gateway integration. Estimated scope: **~4–5 days** (down from original ~8).

**Open items remaining:**
- **Q21** — digest admin notification (recommended).
- **Q22** — LoanEvent on detection (recommended YES).
- **Q23** — dismissal mechanism (recommend +2 columns).

**Awaiting client approval on Q21–Q23 (or amendments) before Step 1 migrations.**
