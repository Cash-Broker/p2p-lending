# Phase F5 — APR Display: Implementation Report

**Branch:** `feature/apr-display` (from `main` at `cadc3a8`)
**Base commit:** `cadc3a8` (F4 + Filament hotfix merged — 437 passed + 4 skipped = 441 tests, 1276 assertions)
**Session start:** 2026-04-24
**Client decision on Q7:** *A — implement APR display now.*

## Completion summary

| Step | Description | Commit |
|---|---|---|
| 0 | Discovery (§1–§9 below) — greenfield finding, formula correction, 9 open questions resolved. | (this doc, pre-commit) |
| 1 | `APRCalculatorService` + `Loan::apr()` memoized accessor + `LoanResource` form hardening (minValue/maxValue + helper text) + DECISIONS.md F5-01 + this audit | `db78bd6` |
| Batch A+B | API `LoanResource` `apr` field + Filament table column "ГПР (APR)" + "Ставки" edit-page section (Доходност/ГПР/Марж) + Vue MarketplacePage column + Vue InvestmentDetailPage stat cell + helper copy + 17 new tests | `3fb38e6` |
| Final | CLAUDE.md Phase F5 section + this audit finalised + README Operations extension | (this commit) |

## Test coverage (final)

| Metric | Pre-F5 baseline* | F5 final | Delta |
|---|---|---|---|
| Tests passed | 437 | **454** | +17 |
| Tests skipped | 4 | 4 | 0 |
| **Total** | 441 | **458** | **+17** |
| Assertions | 1276 | **1300** | +24 |
| Test files in tests/ | 42 | **46** | +4 new F5 |
| Regressions on F1/F2/F3/F4 tests | — | **0** | — |
| Local MySQL suite duration | ~421 s | ~518 s | +97 s (new Livewire Filament smoke tests) |

*Pre-F5 baseline = post-F4 + Filament hotfix (`cadc3a8`).

### New F5 test files

| File | Tests | Focus |
|---|---|---|
| `tests/Unit/Services/APRCalculatorServiceTest.php` | 7 | Nominal pass-through + null/zero/negative guards + precision normalisation + exact preservation at 9.99 / 10.01 / 999.99 bounds |
| `tests/Unit/Models/LoanAPRTest.php` | 4 | `apr()` delegation + null return + **memoization contract pins** (once-called service mock for both non-null and null outcomes) |
| `tests/Feature/Api/LoanAPRApiTest.php` | 3 | `apr` under `data.*` for index; `apr` at root for show (different wrapping via `response()->json(new Resource(...))`); DB-zero → JSON null |
| `tests/Feature/LoanResourceAPRTest.php` | 3 | ListLoans render + zero-apr row doesn't crash + EditLoan renders "Ставки" section end-to-end (all 3 Placeholder closures fire) |

## Known limitations (intentional; v1.1+)

| # | Limitation | Follow-up |
|---|---|---|
| F5-L1 | **Nominal pass-through only.** `APRCalculatorService::calculate()` returns `number_format((float)$loan->interest_rate_annual, 2)` — EXACT for no-fee annuity loans but diverges from the EU CCD IRR-defined APR the moment any borrower-side fee activates. | Upgrade trigger: first borrower-side fee category (origination/service/late/inactivity) goes live in `FeeService::CATEGORIES`. Swap the service body for Newton-Raphson IRR in bcmath. Caller contract preserved (`calculate(Loan): ?string`) — no UI/API edits needed. ~1-day solver + 0.5-day test fixtures from regulator example calculators. |
| F5-L2 | **No `apr_at_activation` snapshot column.** Live recalculation only. If the borrower rate changes after disclosure (rare — but possible via direct DB intervention), the APR displayed today differs from what was disclosed to the investor at investment time. | If Bulgarian CCD enforcement ever mandates "APR as disclosed on day X" preservation: add nullable `apr_at_activation decimal(5,2)` column, set on `funded → active` transition, render it in the investor detail page alongside the live APR when they differ. |
| F5-L3 | **No Vue browser tests.** Same coverage gap as F1/F2/F3/F4. Data-contract pinned by `LoanAPRApiTest` (3 tests on index + show endpoints + DB-zero edge); Filament render-smoke pinned by `LoanResourceAPRTest` (3 Livewire tests including the Ставки section). Manual browser QA before production. | Standard pre-deploy checklist below. Phase 5 Frontend audit may address the broader Dusk setup. |
| F5-L4 | **Zero-apr legacy rows.** Form validation now enforces `minValue(0.01)` on new loans, but any pre-F5 row with `interest_rate_annual = 0` renders as "—" in the UI. Not a data loss — the rate was always zero, UI just now surfaces that explicitly. Backfill optional. | If an operator wants clean display: `UPDATE loans SET interest_rate_annual = interest_rate WHERE interest_rate_annual <= 0` (or set to a business-sane default). Not recommended without audit — zero may reflect a legitimate promotional below-cost loan. |
| F5-L5 | **Marge column not sortable.** Table sort is on `interest_rate_annual` only. Filtering/sorting by spread would require either a DB computed column or a query-time subselect. Not important in v1 (admin manually inspects per-loan). | Add a sortable derived column via `->getStateUsing` + a custom sort closure in v1.1 if the operator requests it. |

## Operational pre-deploy checklist

1. **Code deploy** — merge `feature/apr-display` to `main`. Verify `composer.lock` unchanged (F5 added no new PHP packages).
2. **No migration** — F5 is pure calculation. `php artisan migrate:status` should show zero pending. Any legacy loan with `interest_rate_annual = 0` will render "—" everywhere (F5-L4) — optional backfill per §L4 guidance.
3. **Assets rebuild** — `npm run build`. F5 modified `resources/js/views/MarketplacePage.vue` and `InvestmentDetailPage.vue`; stale `public/build/` won't show the ГПР column/cell or the helper copy.
4. **Admin Filament smoke** — log in as admin. Navigate **Кредити**:
   - Table: new "ГПР (APR)" column between "Доходност" and "Срок". Values formatted as "X.XX%" or "—" for zero-annual rows. Sortable (click header).
   - Edit any loan: new "Ставки" section visible on edit page. Three Placeholders render (Доходност, ГПР, Марж). Марж value = ГПР − Доходност, correct sign.
   - Create a new loan: "Ставки" section **hidden** on create page (no record yet). Attempting to save with zero in either rate field shows validation error "must be at least 0.01".
5. **Investor Vue smoke** (on a browser reloaded after `npm run build`):
   - `/marketplace`: desktop table shows "ГПР" column with values; mobile card shows "ГПР X%" sub-line under the existing interest-rate badge. Hover header tooltip shows "ГПР — Годишен Процент на Разходите...".
   - `/invest/{id}`: stats grid has 5 cells on wide screens (Сума / Доходност / ГПР / Срок / Инвеститори); helper paragraph below the grid explains both rates.
6. **API smoke**:
   ```sh
   curl -H "Authorization: Bearer $INVESTOR_TOKEN" https://app/api/loans | jq '.data[0].apr'
   # expect "10.50" or similar 2-decimal string (or null for zero-annual loans)
   curl -H "Authorization: Bearer $INVESTOR_TOKEN" https://app/api/loans/1 | jq '.apr'
   # expect same format; note NO `data` wrapping on show endpoint
   ```
7. **Regression spot-check** — existing loan actions (invest, early-repay, buyback queue, fees page) should all continue to work. F5 touched no service/state-machine code.
8. **No queue worker or cron changes** — F5 adds neither.
9. **Public copy** — no changes required. F5 is admin + authenticated-investor only (per Q5). Landing page and chatbot do not mention APR.

## Step completion order

```
db78bd6 (step 1)       →
3fb38e6 (batch a+b)    →
<this commit> (final)
```

Base: `cadc3a8` (F4 + Filament hotfix).

## Q1–Q9 final resolution

All 9 discovery questions resolved before Step 1. Summary:

| Q | Topic | Resolution |
|---|---|---|
| Q1 | Rate source | **Q1c — dual display.** Доходност (investor, `interest_rate`) + ГПР (borrower, `interest_rate_annual`). Unlocks F1-L6 productively; preserves existing investor-facing "Доходност" semantic. |
| Q2 | Formula | **Option A — nominal pass-through.** Mathematically exact for no-fee annuity. IRR solver is the v1.1 upgrade path (F5-L1). |
| Q3 | Label | Vue = "ГПР" (BG regulatory). Filament = "ГПР (APR)" bilingual for admin clarity. Comments/code = "APR". |
| Q4 | Precision | **2 decimals.** Matches `decimal:2` cast, EU CCD norm, SPA parseFloat-toFixed stability. |
| Q5 | Display scope | Admin: always (list column + detail section). Investor: marketplace + loan detail. **Not shown:** landing, dashboard, portfolio (per Q5 — avoid signal dilution). |
| Q6 | Live vs frozen | **Live** — pure calc, per-instance memo. No `apr_at_activation` column (F5-L2 revisit trigger). |
| Q7 | Implement now | ✓ Confirmed before discovery. |
| Q8 | Seeder changes | None — `DatabaseSeeder.php` already supplies `interest_rate_annual` for every loan. |
| Q9 | Backfill | None needed — all seeded loans have positive `interest_rate_annual`. F5-L4 documents the optional backfill procedure for hypothetical zero-rate rows. |

## Risks revisited (from Discovery §7, post-implementation status)

- **Formula choice drift** — MITIGATED. `APRCalculatorService::calculate()` is the single change point when fees activate. Caller contract preserved. DECISIONS.md F5-01 documents the upgrade trigger + estimate.
- **Dual-display confusion** — MITIGATED. Helper copy below the InvestmentDetailPage stats grid explicitly distinguishes "what you earn" from "what the borrower pays". Hover tooltips on each stat cell add secondary-channel explanation.
- **F1-L6 activation** — MITIGATED. Three-layer null-safe defense (service / model / UI) + form `minValue(0.01)` prevents future zero entries. Legacy zero rows render "—" (F5-L4).
- **Seeder honesty** — VERIFIED. Seeded loans have `interest_rate_annual > interest_rate` by 1–3 percentage points (factory spread logic). Investor UI will show plausible dual-rate values immediately after `migrate:fresh --seed`.

---

## 1. Methodology

Grep sweeps over `app/`, `resources/js/`, `database/` for:
- English: `apr`, `annual_percentage`, `effective_rate`.
- Bulgarian: `гпр`, `ГПР`.
- Existing rate columns: `interest_rate`, `interest_rate_annual`.

File-level reads on `Loan` model, `AmortizationService`, Filament
`LoanResource`, API `LoanResource`, Vue `MarketplacePage` +
`InvestmentDetailPage`.

---

## 2. Findings

### 2.1 Greenfield — no prior APR implementation

Zero matches for `apr` / `annual_percentage` / `effective_rate` / `гпр`
in `app/` or `resources/`. F5 is a clean add.

### 2.2 Two rate columns already exist on `Loan` (since F1 migrations)

| Column | Meaning | Current use |
|---|---|---|
| `interest_rate` | **Investor yield** (e.g. 8.00%). What the investor earns. | Drives `AmortizationService` — schedule is generated at this rate. API exposes it as "Доходност". |
| `interest_rate_annual` | **Borrower rate** (e.g. 10.50%). What the borrower is contractually told to pay. Difference = originator spread. | **Metadata-only, per F1-L6** — nothing in any computation or UI reads this column. |

Filament admin form ([LoanResource.php:76-77](app/Filament/Resources/LoanResource.php:76)) exposes both inputs with labels "Доходност (%)" + "Лихва кредитополучател (%)" — so admins ALREADY enter a borrower rate at loan creation. It's just that the rest of the system ignores it.

### 2.3 Investor-facing display — 3 sites show `interest_rate`

| File:line | Context | Label |
|---|---|---|
| [MarketplacePage.vue:241](resources/js/views/MarketplacePage.vue:241) | Loan-list table row | "Доходност" header |
| [MarketplacePage.vue:276](resources/js/views/MarketplacePage.vue:276) | Loan card (mobile/card view) | Large accent-coloured % |
| [InvestmentDetailPage.vue:225](resources/js/views/InvestmentDetailPage.vue:225) | Loan detail stat block | "Доходност" label |

All three show `interest_rate` (investor yield). None show the borrower rate.

### 2.4 Filament admin — existing rate columns

- Table ([LoanResource.php:93](app/Filament/Resources/LoanResource.php:93)) shows `interest_rate` as "Доходност" column. No borrower-rate or APR column.
- Form ([LoanResource.php:74-77](app/Filament/Resources/LoanResource.php:74)) captures both `interest_rate` + `interest_rate_annual` at loan creation.

### 2.5 API Resource — investor payload

[app/Http/Resources/LoanResource.php:22](app/Http/Resources/LoanResource.php:22) returns `interest_rate` + `term_months` + other loan facts. **Does NOT include `interest_rate_annual`** — consistent with F1-L6 "metadata only". Adding an `apr` field here is the clean integration point for Vue.

### 2.6 Amortization math — annuity at investor rate

[AmortizationService.php:55](app/Services/AmortizationService.php:55) — monthly rate = `interest_rate / 100 / 12`. Standard annuity formula `M = P * r * (1+r)^n / ((1+r)^n - 1)`. For a vanilla loan with no fees, the nominal interest rate equals the APR (property of annuity amortization).

### 2.7 No borrower-facing UI

Confirmed: `resources/js/views/Borrower*` does not exist. Per
[CLAUDE.md:19](CLAUDE.md:19): *"Borrower — NOT a user, exists only as data"*. So "borrower-facing APR disclosure" in this platform is really **investor-facing transparency about the borrower's APR** — the platform surfaces the borrower's cost of credit for the investor's due diligence, not for the borrower themselves.

### 2.8 F4 fees don't affect borrower APR

F4 added `fees_withdrawal_*` — investor-side only (admin charges investor when investor withdraws platform balance to bank). Zero borrower-side fees exist in v1. Confirms the user's brief.

---

## 3. The formula question — IMPORTANT

The user's handoff says:

> **APR = [(Total Cost − Principal) / Principal] × (12 / Term months) × 100**

**This is the "simple flat rate" formula, NOT APR.** Proof by example —
for a vanilla 12-month annuity loan with principal 10 000 € at 10%
nominal annual interest:

- Monthly annuity payment M ≈ 879.16 €
- Total paid = 12 × M ≈ 10 549.92 €
- Total interest paid = 549.92 €
- **User's formula gives:** (549.92 / 10 000) × (12 / 12) × 100 = **5.50%**
- **Nominal rate is 10.00%.**
- **EU CCD APR (IRR of cash flows) is 10.00%.**

The user's formula returns roughly HALF the nominal rate because it
ignores that principal reduces over the term (amortizing balance).

Three options for F5:

| Option | Formula | v1 result | Accuracy | Impl cost |
|---|---|---|---|---|
| **A. Nominal-rate pass-through** | `APR = interest_rate_annual` (or `interest_rate` — see §4 Q1) | Matches user's "v1 APR = effective interest rate" reasoning | Exact for no-fee annuity loans; equals EU CCD APR | **1 line.** Trivial. |
| B. IRR / EU CCD formula | Numerical solution of `Σ Ck / (1+X)^tk = Σ Dl / (1+X)^sl` | Matches nominal rate today; diverges when fees add | True EU CCD compliance | Newton-Raphson solver in bcmath — ~1 day |
| C. User's simple-flat formula | `(sum(interest) / principal) × 12 / term_months` | ~half nominal rate | Doesn't match ANY regulatory definition | 5 minutes — but produces a misleading number |

**Strong recommendation: Option A for v1, document an Option B migration path for when fees are added.** Option A is correct (for no-fee annuity loans, nominal = APR), trivial to ship, and can be swapped for Option B when F4's origination/service/late fees land — at which point the choice of formula becomes financially material.

If the client specifically wants Option C (matches the handoff text literally), ship it but flag the misleading-number risk.

---

## 4. Open questions — blocking Step 1

### Q1 — Which rate source powers APR?

Choices:
- **Q1a.** `interest_rate` (investor yield; 8%). Inconsistent — "APR" implies borrower cost, not investor return.
- **Q1b.** `interest_rate_annual` (borrower rate; 10.50%). **Recommended** — matches the semantic meaning of APR + activates F1-L6 metadata-only column. Unlocks a dormant field.
- **Q1c.** Display BOTH — "Доходност 8%" (investor) AND "ГПР 10.50%" (borrower) side by side. Most transparent; largest Vue/Filament change.

My recommendation: **Q1c**. Activates a latent column cleanly, preserves the existing "Доходност" semantic for investors, adds ГПР as a new *borrower-cost* data point for due diligence. Each page shows two numbers with distinct meanings — no overwrite of established copy.

### Q2 — Formula

Per §3:
- **A. Nominal-rate pass-through.** Recommended for v1.
- B. IRR solver. Over-engineered for v1.
- C. User's handoff formula. Misleading.

### Q3 — Label (Bulgarian copy)

- **Q3a. "ГПР"** — Годишен Процент на Разходите. Legal-regulatory standard in Bulgaria. Recommended for borrower-cost display.
- Q3b. "APR" — English term. Recognisable by fintech-literate users; not the legal term.
- Q3c. Both — "ГПР (APR)" in the admin panel (internal + regulatory audience), just "ГПР" in investor-facing Vue.

Recommendation: **Q3a** in Vue, **Q3c** in Filament labels and tooltips.

### Q4 — Precision

- Q4a. 1 decimal ("12.5%")
- **Q4b. 2 decimals ("12.54%")** — Recommended per the client's own note. Matches `decimal:2` cast on `interest_rate_annual`. Also matches Bulgarian CCD disclosure expectations (2 decimals standard).

### Q5 — Display scope

- Q5a. Admin: always visible in LoanResource table + detail. Recommended.
- Q5b. Investor: always visible in Marketplace + InvestmentDetail. Recommended — transparency applies pre-investment too.
- Q5c. Alternative: only on "investible" statuses (`published`, `funding`, `funded`). Less consistent; the investor detail page is visited post-funding too.

### Q6 — Live vs frozen

- **Q6a. Live computed.** APR derived on-the-fly from `interest_rate_annual` + future fees config. Recommended. Cheap (pure calc, no query). No frozen snapshot to drift.
- Q6b. Frozen at loan activation. Requires a new `apr_at_activation` column. More DB state. Warranted only if regulation requires "APR at disclosure time" preservation.

### Q7 — Already answered — implement now. ✓

### Q8 — NEW — Missing test-DB seed scenario

The current seeder creates loans with `interest_rate_annual` set. Once F5 ships, my F5 feature tests can rely on seeded borrower rates. No new seed changes needed.

### Q9 — NEW — Should F5 also backfill `interest_rate_annual` for seeded loans that lack it?

Spot-check of [DatabaseSeeder.php:97-107](database/seeders/DatabaseSeeder.php:97) — every seeded loan has `'annual'` set (10.5, 13.0, 15.0 etc.). So no backfill needed.

---

## 5. Proposed changelist (conditional on answers above)

Assumes Q1c + Q2A + Q3a + Q4b + Q5a/b + Q6a.

### New files (2)

- `app/Services/APRCalculatorService.php` — pure calculator. In v1 returns `number_format((float)$loan->interest_rate_annual, 2)`. Class + method signature that lets F4 fee integration be a drop-in later (add a `Loan` param + an optional fees param → call FeeService in the signature later).
- `tests/Unit/Services/APRCalculatorServiceTest.php` — calc tests + edge cases.

### Edited files (~7)

- `app/Models/Loan.php` — `public function apr(): string` accessor returning `APRCalculatorService::compute($this)`. Keeps Vue + Filament clean (just calls `$loan->apr`).
- `app/Http/Resources/LoanResource.php` — add `'apr'` field to investor API payload. Add tests to `LoanTest::test_loans_index_includes_apr_in_response`.
- `resources/js/views/MarketplacePage.vue` — add "ГПР" column alongside "Доходност" in the table; add "ГПР X%" second line in the card view.
- `resources/js/views/InvestmentDetailPage.vue` — add "ГПР" stat block alongside "Доходност".
- `app/Filament/Resources/LoanResource.php` — add "ГПР (APR)" column in the admin table; add "ГПР" computed read-only placeholder in the form detail section.
- `CLAUDE.md` — add `## Phase F5 — APR Display` section at the end of the F-phase block.
- `DECISIONS.md` — F5-01 (formula choice: nominal pass-through, IRR deferred), possibly F5-02 (ГПР labeling), F5-03 (Q1c dual-display decision).

### Test additions (~10–15 tests)

- `APRCalculatorServiceTest`: valid rate → 2-decimal string; zero rate → "0.00"; unpadded DB value normalised; edge-case term=1 month.
- `LoanApiIncludesAprTest` (extend existing `LoanTest`): `apr` field present + value matches `interest_rate_annual` formatted.
- `LoanResourceFilamentAprColumnTest`: column renders without error (Livewire smoke).
- `LoanApr` accessor test: `$loan->apr()` returns expected string for various combinations.

No DB migration, no state machine change, no cron, no notifications, no queue workers — per F5 scope.

### Files NOT changed

- `AmortizationService.php` — unchanged; APR derives from the annualised rate, not the schedule.
- `FeeService.php` — unchanged in v1 (no borrower-side fees). Future: `APRCalculatorService` takes a `FeeService` dep.
- `WithdrawalService.php` — irrelevant to APR.

---

## 6. Proposed step structure (conditional on scope confirm)

| Step | Deliverable | Est. effort |
|---|---|---|
| 0 | This audit (done, pre-commit) | — |
| 1 | `APRCalculatorService` + `Loan::apr()` accessor + unit tests | 1.5 h |
| 2 | Filament integration — LoanResource table column + detail placeholder + Livewire smoke | 1 h |
| 3 | Vue integration — MarketplacePage + InvestmentDetailPage ГПР display | 1 h |
| 4 | API — `LoanResource::toArray()` `apr` field + contract test | 0.5 h |
| 5 | Docs — CLAUDE.md Phase F5, DECISIONS.md entries, README update, this audit finalized | 1 h |
| 6 | Merge sequence mirrored on F4 pattern — ff-merge to main + push | 0.5 h |

**Total estimate:** ~5.5 hours from Step 1 through merge. Smaller than any previous F-phase by design — APR is largely display plumbing over an existing column.

---

## 7. Known risks / flags

- **Formula choice drift.** If fees become borrower-side in v1.1, v2+, APR calculation must switch to IRR. The `APRCalculatorService` signature should accept a `Loan $loan` and compute internally — so the caller doesn't need to know the formula. This lets the future upgrade be a pure service-internals change. DECISIONS.md F5-01 should pin this.
- **Dual-display confusion.** Showing BOTH "Доходност" (8%) AND "ГПР" (10.50%) on the same investor page risks confusion — "why two different rates?". Mitigation: helper text under each. "Доходност: вашата годишна доходност от инвестицията" and "ГПР: годишен процент на разходите за кредитополучателя (регулаторна ставка)".
- **F1-L6 activation.** Currently `interest_rate_annual` has no callers. F5 makes it load-bearing. Any existing loan with NULL or zero for this field will surface in Vue/Filament as "0.00%" — potentially embarrassing. Mitigation: `APRCalculatorService` returns empty/dash when the column is zero/null; UI shows "—" instead of "0.00%".
- **Seeder honesty.** Post-F5, the seeded loans will actually display their `interest_rate_annual` to investors. Verify that seeded values are reasonable (spot-check: 10.5, 13.0, 14.0, 17.0, 14.0 — all > interest_rate values in the seeder, which is correct semantically).

---

## 8. Worktree workflow notes

This session runs in the parent checkout directly (no git worktree).
Per the F4 retrospective (`AUDIT_REPORT_PHASE_F4.md` "Worktree workflow
notes"), doing F5 in the parent avoids the vendor-junction /
`APP_BASE_PATH` dance entirely. No scaffolding needed.

---

## 9. Next steps — pending user confirmation

1. User reviews this report.
2. User answers Q1 (rate source), Q2 (formula), Q3 (label), Q6 (live vs frozen). Q4 + Q5 have strong recommendations already.
3. User confirms changelist + step structure.
4. Proceed to Step 1.

*End of Step 0 Discovery Report. Awaiting user input.*
