import { describe, expect, it } from 'vitest'
import { projectYearlyInterest, sliderMax } from './whatIf'

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
