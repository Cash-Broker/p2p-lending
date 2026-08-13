import { describe, expect, it } from 'vitest'
import { formatEuro, tickerValue } from './earningsTicker'

describe('tickerValue', () => {
  it('returns the base amount at zero elapsed time', () => {
    expect(tickerValue('6.665000', '0.0000051440', 0)).toBeCloseTo(6.665, 6)
  })

  it('advances by rate × elapsed seconds', () => {
    // 60s at ~0.44 €/day pace → +0.00030864.
    expect(tickerValue('6.665000', '0.0000051440', 60_000)).toBeCloseTo(6.66530864, 6)
  })

  it('clamps negative elapsed time to the base (clock skew guard)', () => {
    expect(tickerValue('6.665000', '0.0000051440', -5_000)).toBeCloseTo(6.665, 6)
  })

  it('treats malformed inputs as zero instead of NaN', () => {
    expect(tickerValue(undefined, undefined, 1000)).toBe(0)
    expect(tickerValue('abc', 'xyz', 1000)).toBe(0)
    expect(tickerValue('5.00', null, 60_000)).toBeCloseTo(5, 6)
  })
})

describe('formatEuro', () => {
  const bare = (s) => s.replace(/\s/g, '') // bg-BG groups thousands with a space

  it('formats with a Bulgarian decimal comma and two decimals', () => {
    expect(bare(formatEuro(6.665))).toBe('6,67')
    expect(bare(formatEuro(0))).toBe('0,00')
  })

  it('keeps two decimals for whole numbers', () => {
    expect(bare(formatEuro(1234))).toBe('1234,00')
  })

  it('supports a custom decimal count', () => {
    expect(bare(formatEuro(6.66512, 4))).toBe('6,6651')
  })

  it('renders non-finite input as zero', () => {
    expect(bare(formatEuro(NaN))).toBe('0,00')
    expect(bare(formatEuro(Infinity))).toBe('0,00')
  })
})
