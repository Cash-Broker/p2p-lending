import { describe, expect, it } from 'vitest'
import { animatedRateText, countUpProgress, formatEuro, splitEuroParts, tickerValue } from './earningsTicker'

describe('splitEuroParts', () => {
  const bare = (s) => s.replace(/\s/g, '') // bg-BG groups thousands with a space

  it('splits whole euros from the two stotinki digits (Reni final spec 2026-08-17)', () => {
    // Катя read «1,8227» as хиляди → euros big, dot, стотинки small, and
    // NOTHING beyond the stotinki («махни стотните след 1.82»).
    const parts = splitEuroParts(1.8227)
    expect(parts.main).toBe('1')
    expect(parts.micro).toBe('82')
  })

  it('recomposes exactly to the 2-decimal formatting (no digit lost)', () => {
    for (const v of [0, 5.9999999, 106.003456, 1234.5678, 0.0001]) {
      const parts = splitEuroParts(v)
      expect(`${parts.main},${parts.micro}`).toBe(formatEuro(v, 2))
      expect(parts.micro).toHaveLength(2)
    }
  })

  it('keeps the thousands grouping inside the euro part', () => {
    const parts = splitEuroParts(1060.82)
    expect(bare(parts.main)).toBe('1060')
    expect(parts.micro).toBe('82')
  })

  it('handles zero and rounding across the euro boundary', () => {
    expect(splitEuroParts(0)).toEqual({ main: '0', micro: '00' })
    // 5.999 → formatEuro(…, 2) = 6,00 — the euro part must round WITH it.
    expect(splitEuroParts(5.999)).toEqual({ main: '6', micro: '00' })
  })
})

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

describe('animatedRateText', () => {
  it('prefixes normal rates with a plus sign at full progress', () => {
    expect(animatedRateText('0.4443')).toBe('+0,44')
    expect(animatedRateText('0.4443', 1)).toBe('+0,44')
  })

  it('scales the number by the intro progress', () => {
    expect(animatedRateText('0.50', 0.5)).toBe('+0,25')
    expect(animatedRateText('0.50', 2)).toBe('+0,50') // clamped
  })

  it('holds the badge instead of flashing «+0,00» on early frames of a small rate', () => {
    // Regression: 0.02 × 0.08 ≈ 0.0016 used to render '+0,00'.
    expect(animatedRateText('0.02', 0.08)).toBeNull()
    expect(animatedRateText('0.02', 1)).toBe('+0,02')
  })

  it('renders sub-stotinka rates WITHOUT the plus (no "+<" collision) and without scaling', () => {
    // Regression: the template used to hardcode '+' producing '+< 0,01'.
    expect(animatedRateText('0.0043', 1)).toBe('< 0,01')
    expect(animatedRateText('0.0043', 0.3)).toBe('< 0,01')
  })

  it('returns null when nothing is accruing or the stage has not started', () => {
    expect(animatedRateText('0.0000')).toBeNull()
    expect(animatedRateText(undefined)).toBeNull()
    expect(animatedRateText('-1')).toBeNull()
    expect(animatedRateText('0.4443', 0)).toBeNull()
  })
})

describe('countUpProgress', () => {
  it('is 0 before the stage starts and 1 after it ends', () => {
    expect(countUpProgress(-500, 1000)).toBe(0)
    expect(countUpProgress(0, 1000)).toBe(0)
    expect(countUpProgress(1000, 1000)).toBe(1)
    expect(countUpProgress(5000, 1000)).toBe(1)
  })

  it('eases out — more than linear halfway through', () => {
    const half = countUpProgress(500, 1000)
    expect(half).toBeGreaterThan(0.5)
    expect(half).toBeLessThan(1)
  })

  it('treats a non-positive duration as instantly complete', () => {
    expect(countUpProgress(100, 0)).toBe(1)
  })
})
