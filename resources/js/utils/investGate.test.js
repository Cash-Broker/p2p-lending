import { describe, it, expect } from 'vitest'
import { canInvest } from './investGate'

describe('canInvest', () => {
  it('allows published and funding loans under 100 %', () => {
    expect(canInvest({ status: 'published', funded_percentage: 0 })).toBe(true)
    expect(canInvest({ status: 'funding', funded_percentage: 40 })).toBe(true)
  })

  it('refuses a closed partially funded loan even though its percentage is below 100', () => {
    expect(canInvest({ status: 'repaid', funded_percentage: 40 })).toBe(false)
    expect(canInvest({ status: 'bought_back', funded_percentage: 40 })).toBe(false)
  })

  it('refuses a fully funded loan and every non-fundable status', () => {
    expect(canInvest({ status: 'funding', funded_percentage: 100 })).toBe(false)
    expect(canInvest({ status: 'active', funded_percentage: 100 })).toBe(false)
    expect(canInvest({ status: 'draft', funded_percentage: 0 })).toBe(false)
  })

  it('is safe on missing data', () => {
    expect(canInvest(null)).toBe(false)
    expect(canInvest(undefined)).toBe(false)
    expect(canInvest({ status: 'funding' })).toBe(true)
  })
})
