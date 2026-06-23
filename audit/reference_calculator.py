#!/usr/bin/env python3
"""
Phase 2 Financial Correctness Audit — Python reference oracle.

Independent implementation of the platform's financial calculations,
using decimal.Decimal for arbitrary-precision arithmetic. Emits a JSON
fixture that the PHP comparison harness
(tests/Audit/Phase2FinancialCorrectnessTest.php) loads to assert the
platform's services produce byte-identical output.

Mirrored platform semantics:

* bcmath truncates at the given scale (ROUND_DOWN). Emulated via
  Decimal.quantize with ROUND_DOWN.
* AmortizationService uses SCALE=10 for rate intermediates and scale=2
  for monetary amounts. Monthly payment computed at scale 2 (the
  `bcdiv(numerator, denominator, 2)` at the end). Last installment
  principal = "whatever is left" so Σ principals == loan amount exactly.
* BuybackCalculationService and EarlyRepaymentCalculationService both
  use last-investor-remainder pro-rata: N-1 investors get quantized
  shares at scale 2 using a scale-10 ratio; the Nth investor gets
  `total − Σ (first N-1)`. Guarantees Σ distributions == total exactly.
* F2 buyback coverage: `principal_only` or `principal_plus_interest`.
* F3 early repayment total: outstanding_principal (all unpaid) +
  unpaid_interest (only the "late + next upcoming" boundary, per
  DECISIONS.md F3 "schedule-boundary interest").

Usage:

    python reference_calculator.py --sanity       # spot-check
    python reference_calculator.py --generate     # write JSON fixture

The `--sanity` mode prints the 1000 EUR / 12 months / 10% schedule
so it can be eyeballed against an industry calculator before the
oracle is trusted for the full matrix.
"""

from __future__ import annotations

import argparse
import json
import sys
from decimal import Decimal, getcontext, ROUND_DOWN
from pathlib import Path

# Full precision for internal arithmetic; final values get explicitly
# quantized to the appropriate bcmath scale before being compared.
getcontext().prec = 50


# ──────────────────────────────────────────────────────────────────────
# bcmath-semantic helpers.
#
# bcmath operations TRUNCATE the result at the specified scale. They do
# NOT round half-up or half-even. Replicating this behaviour with
# Decimal.quantize(..., rounding=ROUND_DOWN) is the foundation of the
# oracle — if any of these helpers are wrong, every downstream
# calculation is polluted. See --sanity below.
# ──────────────────────────────────────────────────────────────────────

def _to_decimal(x) -> Decimal:
    return x if isinstance(x, Decimal) else Decimal(str(x))


def _trunc(value: Decimal, scale: int) -> Decimal:
    """Truncate to N decimal places (emulates bcmath)."""
    quantum = Decimal(10) ** -scale
    return value.quantize(quantum, rounding=ROUND_DOWN)


def money_str(value) -> str:
    """Consistent wire-format for monetary values: always 2 decimals.

    Matches how the platform serializes decimal:2 columns — `Decimal(0)`
    renders as `'0.00'`, `Decimal('5000')` renders as `'5000.00'`, never
    `'0'` or `'5000'`.
    """
    return str(_trunc(_to_decimal(value), 2))


def bcadd(a, b, scale: int) -> Decimal:
    return _trunc(_to_decimal(a) + _to_decimal(b), scale)


def bcsub(a, b, scale: int) -> Decimal:
    return _trunc(_to_decimal(a) - _to_decimal(b), scale)


def bcmul(a, b, scale: int) -> Decimal:
    return _trunc(_to_decimal(a) * _to_decimal(b), scale)


def bcdiv(a, b, scale: int) -> Decimal:
    bd = _to_decimal(b)
    if bd == 0:
        raise ZeroDivisionError("bcmath bcdiv by zero")
    return _trunc(_to_decimal(a) / bd, scale)


def bccomp(a, b, scale: int) -> int:
    """Returns -1/0/1 like PHP bccomp."""
    at = _trunc(_to_decimal(a), scale)
    bt = _trunc(_to_decimal(b), scale)
    if at < bt:
        return -1
    if at > bt:
        return 1
    return 0


def bcpow_int(base, exp_int: int, scale: int) -> Decimal:
    """
    Integer-exponent power with final truncation. Mirrors
    `bcpow($base, (string) $intExp, $scale)`. bcmath computes the full
    integer power at internal precision and truncates ONCE at the end.
    """
    return _trunc(_to_decimal(base) ** int(exp_int), scale)


# ──────────────────────────────────────────────────────────────────────
# Amortization — annuity formula per AmortizationService.
# ──────────────────────────────────────────────────────────────────────

AMORTIZATION_SCALE = 10  # Matches AmortizationService::SCALE


def monthly_payment(principal, interest_rate_pct, term_months: int) -> Decimal:
    """
    M = P * r * (1+r)^n / ((1+r)^n - 1)
      where r = annual% / 100 / 12
    Returns quantized(M, 2) via bcdiv at scale 2 (platform truncates
    the final payment when it stores it).

    Zero-interest case: equal principal split, no interest component.
    """
    P = _to_decimal(principal)
    rate_pct = _to_decimal(interest_rate_pct)
    n = int(term_months)

    r = bcdiv(bcdiv(rate_pct, "100", AMORTIZATION_SCALE), "12", AMORTIZATION_SCALE)

    if bccomp(r, "0", AMORTIZATION_SCALE) == 0:
        return bcdiv(P, str(n), 2)

    one_plus_r = bcadd("1", r, AMORTIZATION_SCALE)
    pow_n = bcpow_int(one_plus_r, n, AMORTIZATION_SCALE)
    numerator = bcmul(bcmul(P, r, AMORTIZATION_SCALE), pow_n, AMORTIZATION_SCALE)
    denominator = bcsub(pow_n, "1", AMORTIZATION_SCALE)
    return bcdiv(numerator, denominator, 2)


def amortization_schedule(principal, interest_rate_pct, term_months: int) -> dict:
    """
    Mirror of AmortizationService::generateSchedule.
    Returns:
      {
        monthly_payment: str,
        schedule: [{principal, interest, total}, ...],
        principal_sum: str,      # always == principal (invariant)
        interest_sum: str,
        total_paid: str,
      }
    """
    P = _to_decimal(principal)
    rate_pct = _to_decimal(interest_rate_pct)
    n = int(term_months)

    r = bcdiv(bcdiv(rate_pct, "100", AMORTIZATION_SCALE), "12", AMORTIZATION_SCALE)
    M = monthly_payment(P, rate_pct, n)

    schedule = []
    remaining = P
    for i in range(1, n + 1):
        interest_i = bcmul(remaining, r, 2)
        if i == n:
            principal_i = remaining  # last installment absorbs drift
        else:
            principal_i = bcsub(M, interest_i, 2)
        total_i = bcadd(principal_i, interest_i, 2)
        schedule.append(
            {
                "principal": money_str(principal_i),
                "interest": money_str(interest_i),
                "total": money_str(total_i),
            }
        )
        remaining = bcsub(remaining, principal_i, 2)

    principal_sum = sum((_to_decimal(row["principal"]) for row in schedule), Decimal(0))
    interest_sum = sum((_to_decimal(row["interest"]) for row in schedule), Decimal(0))
    total_paid = sum((_to_decimal(row["total"]) for row in schedule), Decimal(0))

    return {
        "monthly_payment": money_str(M),
        "schedule": schedule,
        "principal_sum": money_str(principal_sum),
        "interest_sum": money_str(interest_sum),
        "total_paid": money_str(total_paid),
    }


# ──────────────────────────────────────────────────────────────────────
# Pro-rata distribution — last-investor-remainder.
# Matches BuybackCalculationService::distribute and
# EarlyRepaymentCalculationService::distribute.
# ──────────────────────────────────────────────────────────────────────

PRORATA_SCALE = 10  # Rate math scale (matches service internals).


def pro_rata(total, investor_amounts: list) -> dict:
    """
    POST-FIX model — principal is returned as each investor's EXACT outstanding
    capital (see BuybackCalculationService::distribute /
    EarlyRepaymentCalculationService::distribute /
    InvestorDistributionService::outstandingPrincipalByUser).

    With no prior repayments, an investor's outstanding == their funding amount,
    so each investor receives exactly their amount and Σ == funded. This makes
    every investor whole to the cent and zeroes their `invested` on close — no
    last-investor-remainder splitting, no drift. `total` is the buyback /
    early-repayment principal and is expected to equal Σ amounts.

    Returns:
      {
        total: str,
        funded: str,
        distributions: [str, ...],   # == per-investor outstanding (amounts)
        sum_distributions: str,      # invariant: == total == funded
      }
    """
    T = _to_decimal(total)
    amounts = [_to_decimal(a) for a in investor_amounts]
    F = sum(amounts, Decimal(0))

    distributions = [money_str(a) for a in amounts]
    distributed = sum((_to_decimal(money_str(a)) for a in amounts), Decimal(0))

    return {
        "total": money_str(T),
        "funded": str(_trunc(F, 2)),
        "distributions": distributions,
        "sum_distributions": money_str(distributed),
    }


# ──────────────────────────────────────────────────────────────────────
# F2 Buyback totals.
# ──────────────────────────────────────────────────────────────────────

def buyback_total(schedule: list, paid_indices: list, coverage: str) -> dict:
    """
    schedule: list of {principal, interest} (from amortization_schedule).
    paid_indices: 0-based indices of already-paid installments.
    coverage: 'principal_only' or 'principal_plus_interest'.

    Mirrors BuybackCalculationService::calculateTotal.
    """
    if coverage not in ("principal_only", "principal_plus_interest"):
        raise ValueError(f"unknown coverage: {coverage}")

    unpaid_principal = Decimal(0)
    unpaid_interest = Decimal(0)
    for i, row in enumerate(schedule):
        if i in paid_indices:
            continue
        unpaid_principal = bcadd(unpaid_principal, row["principal"], 2)
        if coverage == "principal_plus_interest":
            unpaid_interest = bcadd(unpaid_interest, row["interest"], 2)

    total = bcadd(unpaid_principal, unpaid_interest, 2)
    return {
        "coverage": coverage,
        "unpaid_principal": money_str(unpaid_principal),
        "unpaid_interest": money_str(unpaid_interest),
        "total": money_str(total),
    }


# ──────────────────────────────────────────────────────────────────────
# F3 Early repayment totals — schedule-boundary logic.
#
# Per DECISIONS.md F3:
#   outstanding_principal = Σ schedule.principal where status IN (pending, late)
#   unpaid_interest       = Σ schedule.interest where status IN (pending, late)
#                             AND due_date <= next_upcoming_schedule.due_date
#   total                 = outstanding + unpaid_interest
#
# In fixture terms: we parameterise by the index of the "boundary"
# installment. Interest is summed for late installments (indices < boundary
# that are marked late) + the boundary installment itself.
# ──────────────────────────────────────────────────────────────────────

def early_repayment_total(
    schedule: list,
    paid_indices: list,
    late_indices: list,
    boundary_index: int,
) -> dict:
    """
    Arguments:
      schedule: full amortization schedule.
      paid_indices: 0-based indices fully paid.
      late_indices: 0-based indices overdue but unpaid (subset of unpaid).
      boundary_index: 0-based index of the "next upcoming" installment.
                      Interest is summed for indices in late_indices +
                      boundary_index only. Principals from every unpaid
                      (pending + late) index counted.

    Invariants asserted:
      - paid and late are disjoint.
      - boundary_index is NOT in paid_indices (it's unpaid).
      - boundary_index is NOT in late_indices (late is strictly past-due).
    """
    if set(paid_indices) & set(late_indices):
        raise ValueError("paid and late indices overlap")
    if boundary_index in paid_indices:
        raise ValueError("boundary_index cannot be paid")
    if boundary_index in late_indices:
        raise ValueError("boundary_index cannot be late (must be upcoming)")

    outstanding_principal = Decimal(0)
    unpaid_interest = Decimal(0)

    interest_boundary_indices = set(late_indices) | {boundary_index}

    for i, row in enumerate(schedule):
        if i in paid_indices:
            continue
        outstanding_principal = bcadd(outstanding_principal, row["principal"], 2)
        if i in interest_boundary_indices:
            unpaid_interest = bcadd(unpaid_interest, row["interest"], 2)

    total = bcadd(outstanding_principal, unpaid_interest, 2)
    return {
        "outstanding_principal": money_str(outstanding_principal),
        "unpaid_interest": money_str(unpaid_interest),
        "total": money_str(total),
    }


# ──────────────────────────────────────────────────────────────────────
# F5 APR — nominal pass-through.
# ──────────────────────────────────────────────────────────────────────

def apr(interest_rate_annual_pct) -> str | None:
    """APR = 2-decimal pass-through of the borrower rate, or None."""
    rate = _to_decimal(interest_rate_annual_pct)
    if rate <= 0:
        return None
    return money_str(rate)


# ──────────────────────────────────────────────────────────────────────
# Sanity check — must match before we trust the full matrix.
# ──────────────────────────────────────────────────────────────────────

def sanity_check():
    """
    Print the 1000 EUR / 12 months / 10% schedule. Spot-check targets:

    * Monthly payment ~ 87.91 (platform truncates from 87.9158...)
    * Σ principals == 1000.00 exactly
    * Schedule has 12 rows
    * interest row 1 ≈ 8.33 (1000 × 0.00833333... = 8.333..., truncates to 8.33)
    * Last installment absorbs the accumulated rounding drift
    """
    print("=== SANITY: 1000 EUR / 12 months / 10.00% annual ===")
    result = amortization_schedule(Decimal("1000"), Decimal("10"), 12)
    print(f"monthly_payment = {result['monthly_payment']}")
    print(f"principal_sum   = {result['principal_sum']}  (must == 1000.00)")
    print(f"interest_sum    = {result['interest_sum']}")
    print(f"total_paid      = {result['total_paid']}")
    print()
    print(f"{'month':>5}  {'principal':>12}  {'interest':>10}  {'total':>10}")
    for i, row in enumerate(result["schedule"], 1):
        print(f"{i:>5}  {row['principal']:>12}  {row['interest']:>10}  {row['total']:>10}")

    # Distribution sanity: each investor is returned their exact outstanding.
    print()
    print("=== SANITY: 150 EUR returned to 3 investors of 50 each ===")
    prorata = pro_rata(Decimal("150"), [Decimal("50"), Decimal("50"), Decimal("50")])
    print(f"distributions = {prorata['distributions']}")
    print(f"sum           = {prorata['sum_distributions']}  (must == 150.00)")

    # 3 investors of 100 each → each made whole to 100.00 (no remainder split).
    print()
    print("=== SANITY: 300 EUR returned to 3 investors of 100 each ===")
    prorata = pro_rata(
        Decimal("300"),
        [Decimal("100"), Decimal("100"), Decimal("100")],
    )
    print(f"distributions = {prorata['distributions']}")
    print(f"sum           = {prorata['sum_distributions']}  (must == 300.00)")

    # Invariant assertions
    assert result["principal_sum"] == "1000.00", "FAIL: principal sum != 1000.00"
    assert prorata["sum_distributions"] == "300.00", "FAIL: prorata sum != 300.00"
    print()
    print("invariants: principal_sum == loan amount  [OK]")
    print("invariants: distribution sum == total     [OK]")


# ──────────────────────────────────────────────────────────────────────
# Fixture generation — ~50 test cases covering the matrix.
# ──────────────────────────────────────────────────────────────────────

def fixture_amortization_matrix() -> list[dict]:
    """Amortization test cases — core matrix + mandatory edge cases."""
    cases = []

    # Baseline & matrix samples (8 cases)
    baseline = [
        ("baseline_sanity", "1000", "10", 12),
        ("typical_10k_12pct_12mo", "10000", "12", 12),
        ("typical_100k_8pct_24mo", "100000", "8", 24),
        ("large_1m_5pct_60mo", "1000000", "5", 60),
        ("short_3mo_12pct", "1000", "12", 3),
        ("medium_10k_0pct_12mo", "10000", "0", 12),  # zero-interest edge
        ("high_24pct_10k_12mo", "10000", "24", 12),
        ("long_60mo_5pct_10k", "10000", "5", 60),
    ]
    for name, amt, rate, term in baseline:
        cases.append(
            {
                "case_id": f"AM-{name}",
                "kind": "amortization",
                "input": {"amount": amt, "interest_rate": rate, "term_months": term},
                "expected": amortization_schedule(Decimal(amt), Decimal(rate), term),
            }
        )

    # Mandatory edge cases (user-specified)
    # 1. 1000 / 3 months / 12% — rounding-drift classic
    cases.append(
        {
            "case_id": "AM-edge-1000-3mo-12pct",
            "kind": "amortization",
            "note": "Rounding-drift classic (user-specified edge)",
            "input": {"amount": "1000", "interest_rate": "12", "term_months": 3},
            "expected": amortization_schedule(Decimal("1000"), Decimal("12"), 3),
        }
    )

    # 2. 1 month loan — degenerate annuity
    cases.append(
        {
            "case_id": "AM-edge-1-month",
            "kind": "amortization",
            "note": "Degenerate annuity (1-month loan)",
            "input": {"amount": "5000", "interest_rate": "10", "term_months": 1},
            "expected": amortization_schedule(Decimal("5000"), Decimal("10"), 1),
        }
    )

    # 3. 0.01 EUR amount — precision floor
    cases.append(
        {
            "case_id": "AM-edge-precision-floor",
            "kind": "amortization",
            "note": "Precision floor (1 cent principal). Zero-interest forced — annuity of 0.01 @ 10%/12 underflows to unpayable.",
            "input": {"amount": "0.01", "interest_rate": "0", "term_months": 1},
            "expected": amortization_schedule(Decimal("0.01"), Decimal("0"), 1),
        }
    )

    # Additional matrix samples (thickening the coverage)
    matrix_extras = [
        ("AM-mx-1k-5pct-6mo",  "1000",  "5",  6),
        ("AM-mx-1k-24pct-6mo", "1000", "24",  6),
        ("AM-mx-100k-5pct-12mo", "100000", "5", 12),
        ("AM-mx-100k-12pct-36mo", "100000", "12", 36),
        ("AM-mx-1m-8pct-36mo", "1000000", "8", 36),
        ("AM-mx-10k-5pct-1mo", "10000", "5", 1),
        ("AM-mx-10k-0pct-60mo", "10000", "0", 60),
    ]
    for case_id, amt, rate, term in matrix_extras:
        cases.append(
            {
                "case_id": case_id,
                "kind": "amortization",
                "input": {"amount": amt, "interest_rate": rate, "term_months": term},
                "expected": amortization_schedule(Decimal(amt), Decimal(rate), term),
            }
        )

    return cases


def fixture_prorata_matrix() -> list[dict]:
    """Pro-rata distribution test cases."""
    cases = []

    # Typical distributions
    typical = [
        ("PR-2inv-equal", "100", ["50", "50"]),
        ("PR-3inv-equal", "1000", ["333", "333", "334"]),
        ("PR-3inv-uneven", "1000", ["500", "300", "200"]),
        ("PR-5inv-equal", "10000", ["2000"] * 5),
        ("PR-5inv-weighted", "10000", ["5000", "2000", "1500", "1000", "500"]),
        ("PR-10inv-equal", "10000", ["1000"] * 10),
    ]
    for case_id, total, shares in typical:
        cases.append(
            {
                "case_id": case_id,
                "kind": "prorata",
                "input": {"total": total, "investor_amounts": shares},
                "expected": pro_rata(Decimal(total), [Decimal(s) for s in shares]),
            }
        )

    # Exact-outstanding distribution stress. NOTE: post-fix, the buyback /
    # early-repayment principal returned equals Σ(investor outstanding), so
    # `total` MUST equal Σ shares — distributing less than each investor's
    # outstanding is no longer a real scenario. These keep the large /
    # uneven investor-count stress while asserting each is made whole.
    stress = [
        ("PR-100-3-classic", "300", ["100", "100", "100"]),
        ("PR-1000-7-cases", "7000", ["1000"] * 7),
        ("PR-3inv-single-investor-share", "3", ["1", "1", "1"]),
    ]
    for case_id, total, shares in stress:
        cases.append(
            {
                "case_id": case_id,
                "kind": "prorata",
                "note": "Exact-outstanding distribution",
                "input": {"total": total, "investor_amounts": shares},
                "expected": pro_rata(Decimal(total), [Decimal(s) for s in shares]),
            }
        )

    # Mandatory edge cases
    # 4. 99 investors equal shares
    cases.append(
        {
            "case_id": "PR-edge-99inv-equal",
            "kind": "prorata",
            "note": "99 investors equal shares (large distribution stress)",
            "input": {"total": "9900", "investor_amounts": ["100"] * 99},
            "expected": pro_rata(Decimal("9900"), [Decimal("100")] * 99),
        }
    )

    # 5. 2 investors 0.01 / 99.99 split
    cases.append(
        {
            "case_id": "PR-edge-extreme-uneven",
            "kind": "prorata",
            "note": "Extreme uneven: 0.01 / 99.99",
            "input": {
                "total": "100",
                "investor_amounts": ["0.01", "99.99"],
            },
            "expected": pro_rata(
                Decimal("100"), [Decimal("0.01"), Decimal("99.99")]
            ),
        }
    )

    # Single investor
    cases.append(
        {
            "case_id": "PR-1inv-trivial",
            "kind": "prorata",
            "note": "Single investor — pro-rata no-op",
            "input": {"total": "1000", "investor_amounts": ["1000"]},
            "expected": pro_rata(Decimal("1000"), [Decimal("1000")]),
        }
    )

    return cases


def fixture_buyback_scenarios() -> list[dict]:
    """F2 buyback calculation scenarios."""
    cases = []

    # Start from a known schedule: 10k / 12% / 12mo
    sched = amortization_schedule(Decimal("10000"), Decimal("12"), 12)["schedule"]

    scenarios = [
        (
            "BB-fresh-loan-principal-only",
            [],
            "principal_only",
            "Fresh loan, no payments made, principal-only coverage",
        ),
        (
            "BB-fresh-loan-principal-plus-interest",
            [],
            "principal_plus_interest",
            "Fresh loan, no payments made, full coverage",
        ),
        (
            "BB-partial-3-paid-principal-only",
            [0, 1, 2],
            "principal_only",
            "3 installments paid, principal-only coverage",
        ),
        # 6. Loan partially paid THEN buyback (user-mandatory)
        (
            "BB-edge-partial-6-paid-full-coverage",
            [0, 1, 2, 3, 4, 5],
            "principal_plus_interest",
            "Loan half-paid then buyback (user-mandatory edge, full coverage)",
        ),
        (
            "BB-partial-11-paid-full-coverage",
            list(range(11)),  # everything but the last installment paid
            "principal_plus_interest",
            "Almost-complete loan bought back (last installment only)",
        ),
    ]
    for case_id, paid, coverage, note in scenarios:
        cases.append(
            {
                "case_id": case_id,
                "kind": "buyback",
                "note": note,
                "input": {
                    "schedule_from": {
                        "amount": "10000",
                        "interest_rate": "12",
                        "term_months": 12,
                    },
                    "paid_indices": paid,
                    "coverage": coverage,
                },
                "expected": buyback_total(sched, paid, coverage),
            }
        )

    return cases


def fixture_early_repayment_scenarios() -> list[dict]:
    """F3 early repayment calculation scenarios (schedule-boundary)."""
    cases = []

    sched = amortization_schedule(Decimal("10000"), Decimal("12"), 12)["schedule"]

    scenarios = [
        (
            "ER-fresh-boundary-0",
            [],
            [],
            0,
            "Fresh loan, close at first installment boundary",
        ),
        (
            "ER-partial-3-paid-boundary-3",
            [0, 1, 2],
            [],
            3,
            "3 paid, closing at boundary=installment 4 (index 3)",
        ),
        (
            "ER-partial-5-paid-boundary-5",
            [0, 1, 2, 3, 4],
            [],
            5,
            "5 paid, closing at boundary=installment 6",
        ),
        # 7. Loan partially paid on late status THEN early repayment (user-mandatory)
        (
            "ER-edge-late-plus-early",
            [0, 1, 2, 3],
            [4],
            5,
            "4 paid, installment 5 LATE, closing at boundary=6 — interest sums index-4 LATE + index-5 BOUNDARY",
        ),
        (
            "ER-double-late-plus-boundary",
            [0, 1, 2],
            [3, 4],
            5,
            "3 paid, indices 4+5 LATE, boundary=6 — interest sums 3 rows",
        ),
    ]
    for case_id, paid, late, boundary, note in scenarios:
        cases.append(
            {
                "case_id": case_id,
                "kind": "early_repayment",
                "note": note,
                "input": {
                    "schedule_from": {
                        "amount": "10000",
                        "interest_rate": "12",
                        "term_months": 12,
                    },
                    "paid_indices": paid,
                    "late_indices": late,
                    "boundary_index": boundary,
                },
                "expected": early_repayment_total(sched, paid, late, boundary),
            }
        )

    return cases


def fixture_apr_cases() -> list[dict]:
    """F5 APR pass-through tests."""
    cases = []
    samples = [
        ("APR-typical-10.50", "10.50", "10.50"),
        ("APR-typical-999.99", "999.99", "999.99"),
        ("APR-unpadded-12.5", "12.5", "12.50"),
        ("APR-zero-null", "0", None),
        ("APR-negative-null", "-1", None),
    ]
    for case_id, rate, expected in samples:
        cases.append(
            {
                "case_id": case_id,
                "kind": "apr",
                "input": {"interest_rate_annual": rate},
                "expected": {"apr": expected},
            }
        )
    return cases


def generate_fixture(out_path: Path):
    cases = (
        fixture_amortization_matrix()
        + fixture_prorata_matrix()
        + fixture_buyback_scenarios()
        + fixture_early_repayment_scenarios()
        + fixture_apr_cases()
    )

    # Invariant assertions before writing — catch oracle bugs here.
    for case in cases:
        exp = case["expected"]
        if case["kind"] == "amortization":
            assert exp["principal_sum"] == money_str(case["input"]["amount"]), \
                f"{case['case_id']}: principal_sum != amount"
        elif case["kind"] == "prorata":
            assert exp["sum_distributions"] == money_str(case["input"]["total"]), \
                f"{case['case_id']}: sum_distributions != total"

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps({"cases": cases}, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"Wrote {len(cases)} cases to {out_path}")
    summary = {}
    for c in cases:
        summary[c["kind"]] = summary.get(c["kind"], 0) + 1
    for kind, count in summary.items():
        print(f"  {kind}: {count}")


# ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--sanity", action="store_true", help="Run sanity spot-check")
    group.add_argument("--generate", action="store_true", help="Generate JSON fixture")
    parser.add_argument(
        "--out",
        type=Path,
        default=Path(__file__).parent / "fixtures" / "phase2_cases.json",
    )
    args = parser.parse_args()

    if args.sanity:
        sanity_check()
    elif args.generate:
        generate_fixture(args.out)


if __name__ == "__main__":
    main()
