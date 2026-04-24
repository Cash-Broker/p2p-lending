# Phase 2 — Financial Correctness Audit

**Branch:** `feature/phase2-financial-audit` (from `main` at `fa069d0`)
**Base commit:** `fa069d0` (F5 merged — 454 passed + 4 skipped = 458 tests, 1300 assertions)
**Session start:** 2026-04-24
**Audit scope:** Independent mathematical verification of platform
calculations. **Not** adding features — finding precision bugs,
rounding errors, invariant violations.

**Status:** 🟡 WIP. Step 1 complete (Python oracle + JSON fixture
generated, sanity-checked). Steps 2–6 pending.

---

## 1. Methodology

### Oracle choice

**Python `decimal.Decimal` at `prec=50`.** Independent language stack
from the platform's PHP bcmath — if both agree bit-for-bit on a
different implementation path, that's strong evidence of correctness.

Oracle helpers (`bcadd`, `bcsub`, `bcmul`, `bcdiv`, `bcpow_int`,
`bccomp`) replicate bcmath's truncation semantics via
`Decimal.quantize(..., rounding=ROUND_DOWN)` at the same scale the
platform uses (10 for rate intermediates, 2 for monetary amounts).

The oracle is NOT a re-implementation from the platform source —
each formula was re-derived from the textbook / CCD spec and coded
against the documented platform semantics (SCALE constants,
last-installment absorption, last-investor-remainder). If both
converge on the same output, the formula is correct in both places.

### Sanity check (pre-generation)

Before the fixture is trusted, the oracle is spot-checked against a
canonical case (`1000 EUR / 12 months / 10% annual`) and a classic
pro-rata case (`100 EUR / 3 equal investors`). Results:

```
monthly_payment = 87.91         (bcmath-truncated from 87.9158...)
principal_sum   = 1000.00       (invariant: ==amount)
interest_sum    = 54.95
total_paid      = 1054.95

pro-rata 100/3  = [33.33, 33.33, 33.34]  sum = 100.00
```

Hand-verified against standard annuity calculators (modulo expected
truncation difference: online calculators round-half-up to 87.92;
platform + oracle truncate to 87.91 per shared bcmath semantics).

### Fixture

`audit/fixtures/phase2_cases.json` — 45 cases across 5 kinds:

| Kind | Count | Focus |
|---|---|---|
| amortization | 18 | 4×6×4 axis matrix + mandatory edge cases (1000/3mo/12%, 1-month loan, 0.01 EUR precision floor, zero-interest) |
| prorata | 12 | Typical + last-investor-remainder stress + 99-investor equal + 0.01%/99.99% extreme uneven |
| buyback | 5 | Fresh / partially paid / nearly-complete × coverage types |
| early_repayment | 5 | Boundary-only / with-late-installments / double-late |
| apr | 5 | Nominal pass-through + null fallback |

User-mandatory edge cases ALL included:

- `AM-edge-1000-3mo-12pct` — rounding-drift classic
- `AM-edge-1-month` — degenerate annuity
- `AM-edge-precision-floor` — 0.01 EUR loan
- `PR-edge-99inv-equal` — large pro-rata stress
- `PR-edge-extreme-uneven` — 0.01/99.99 split
- `BB-edge-partial-6-paid-full-coverage` — partial-paid buyback
- `ER-edge-late-plus-early` — late + early repayment combo

### Invariants asserted in Python (before JSON write)

Catches oracle bugs before they propagate to the PHP comparison:
- Every amortization: `Σ principals == loan.amount` (exact string match).
- Every pro-rata: `Σ distributions == total` (exact string match).

Oracle `generate_fixture()` raises `AssertionError` if any case fails
these → no fixture shipped with a broken oracle.

---

## 2. Step completion

| Step | Status | Deliverable |
|---|---|---|
| 0 — Branch + audit doc scaffold | ✅ | `feature/phase2-financial-audit`, this doc, `audit/` + `tests/Audit/` dirs |
| 1 — Python oracle + JSON fixture | ✅ | [audit/reference_calculator.py](audit/reference_calculator.py), [audit/fixtures/phase2_cases.json](audit/fixtures/phase2_cases.json) (45 cases) |
| 2 — PHPUnit comparison harness | 🟡 pending | `tests/Audit/Phase2FinancialCorrectnessTest.php` |
| 3 — Run + collect discrepancies | 🟡 pending | Findings log below |
| 4 — Root-cause analysis per finding | 🟡 pending | Per-finding sub-sections |
| 5 — Fixes + regression tests | 🟡 pending | Fix commits + new tests |
| 6 — Finalize this report | 🟡 pending | Executive summary + limitations |

---

## 3. Findings (WIP — populated during Steps 3–5)

### CRITICAL (> 0.01 EUR drift OR invariant violation)

_None yet._

### HIGH (consistent precision issue)

_None yet._

### MEDIUM (edge-case-specific)

_None yet._

### LOW (documentation / cleanup)

_None yet._

---

## 4. Fix summary (WIP)

| # | Severity | Area | Commit |
|---|---|---|---|

---

## 5. Cumulative drift check (Step 3)

Per audit policy: sum of all individual drifts across the fixture
must be < 1 EUR (CRITICAL if exceeded — catches systematic-small-drift
patterns that individually pass but aggregate materially).

_Populated during Step 3._

---

## 6. Executive summary (Step 6 final)

_Populated in Step 6 after all findings are resolved._

---

## 7. Commands reference

```sh
# Sanity check oracle output against canonical case
python audit/reference_calculator.py --sanity

# Regenerate JSON fixture (run after any oracle edit)
python audit/reference_calculator.py --generate

# Run comparison harness (Step 2+)
APP_BASE_PATH="$(pwd)" php artisan test --testsuite=Audit
```

---

*End of work-in-progress report. Step 2 is the next action.*
