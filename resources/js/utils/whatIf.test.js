import { describe, expect, it } from 'vitest'
import { projectYearlyInterest, projectTwelveMonthProfit, sliderMax } from './whatIf'

describe('projectYearlyInterest', () => {
  it('computes simple yearly interest', () => {
    expect(projectYearlyInterest(1000, '16.00')).toBeCloseTo(160, 6)
    expect(projectYearlyInterest('500', 12)).toBeCloseTo(60, 6)
  })

  it('returns zero for garbage or non-positive input', () => {
    expect(projectYearlyInterest(0, 16)).toBe(0)
    expect(projectYearlyInterest('abc', 16)).toBe(0)
    expect(projectYearlyInterest(1000, undefined)).toBe(0)
  })
})

describe('projectTwelveMonthProfit', () => {
  // Expected values are the BACKEND ENGINE's schedule totals (verified via
  // OfferProjectionService::summary in tinker) — bcmath truncates per row,
  // so e.g. interest-only 1000 € @ 16% is 12 × 13.33 = 159.96, not 160.00.
  it('interest_only matches the engine schedule total (truncated monthly × 12)', () => {
    expect(projectTwelveMonthProfit(1000, '16.00', 'interest_only')).toBeCloseTo(159.96, 9)
    // and stays the default when no plan is given (legacy behavior)
    expect(projectTwelveMonthProfit(1000, '16.00')).toBeCloseTo(159.96, 9)
    // 12% divides evenly — no truncation loss: 12 × 10.00
    expect(projectTwelveMonthProfit(1000, '12.00', 'interest_only')).toBeCloseTo(120.0, 9)
  })

  it('amortizing matches the engine 12-month annuity loop to the cent', () => {
    // 1000 € @ 12%: M = trunc2(88.848788…) = 88.84, per-row truncated
    // interest sums to 66.14 (engine total_interest = 66.14, NOT the smooth
    // closed-form 66.19).
    expect(projectTwelveMonthProfit(1000, '12.00', 'amortizing')).toBeCloseTo(66.14, 9)
    // Visibly lower than interest-only at the same rate — principal returns monthly.
    expect(projectTwelveMonthProfit(1000, '12.00', 'amortizing'))
      .toBeLessThan(projectTwelveMonthProfit(1000, '12.00', 'interest_only'))
  })

  it('capitalized compounds monthly and rounds once at maturity', () => {
    // 1000 € @ 20%: m = trunc10(0.2/12) = 0.0166666666, maturity
    // trunc2(1219.391…) = 1219.39 → profit 219.39 (engine parity).
    expect(projectTwelveMonthProfit(1000, '20.00', 'capitalized')).toBeCloseTo(219.39, 9)
    // Compounding beats simple interest at the same rate.
    expect(projectTwelveMonthProfit(1000, '20.00', 'capitalized'))
      .toBeGreaterThan(projectTwelveMonthProfit(1000, '20.00', 'interest_only'))
  })

  it('survives dream-sized amounts without float drift (100M € cap)', () => {
    // Engine parity checked via tinker: 100,000,000 € @ 12% amortizing
    // total_interest = 6,618,546.36.
    expect(projectTwelveMonthProfit(100_000_000, '12.00', 'amortizing')).toBeCloseTo(6_618_546.36, 6)
  })

  it('returns zero for garbage or non-positive input on every plan', () => {
    for (const plan of ['amortizing', 'interest_only', 'capitalized']) {
      expect(projectTwelveMonthProfit(0, 16, plan)).toBe(0)
      expect(projectTwelveMonthProfit('abc', 16, plan)).toBe(0)
      expect(projectTwelveMonthProfit(1000, undefined, plan)).toBe(0)
    }
  })
})

describe('sliderMax', () => {
  it('gives a 2000 € dream floor for small balances', () => {
    expect(sliderMax('0.00')).toBe(2000)
    expect(sliderMax('400')).toBe(2000)
  })

  it('scales to 2× balance rounded to a clean 500 step', () => {
    expect(sliderMax('5000')).toBe(10000)
    expect(sliderMax('5100')).toBe(10500)
  })
})
