import { describe, expect, it } from 'vitest'
import { formatCountdown, remainingMs } from './promoCountdown'

describe('remainingMs', () => {
  it('returns the positive distance to the deadline', () => {
    const now = Date.parse('2026-08-14T10:00:00Z')
    expect(remainingMs('2026-08-14T11:00:00Z', now)).toBe(3_600_000)
  })

  it('clamps past deadlines to zero', () => {
    const now = Date.parse('2026-08-14T10:00:00Z')
    expect(remainingMs('2026-08-14T09:59:00Z', now)).toBe(0)
  })

  it('treats malformed dates as expired', () => {
    expect(remainingMs('not-a-date', Date.parse('2026-08-14T10:00:00Z'))).toBe(0)
  })
})

describe('formatCountdown', () => {
  it('formats MM:SS under an hour', () => {
    expect(formatCountdown(59 * 60000 + 26000)).toBe('59:26')
    expect(formatCountdown(5000)).toBe('00:05')
  })

  it('formats H:MM:SS above an hour', () => {
    expect(formatCountdown(3_600_000 + 83_000)).toBe('1:01:23')
  })

  it('never goes negative and handles garbage', () => {
    expect(formatCountdown(-5000)).toBe('00:00')
    expect(formatCountdown(undefined)).toBe('00:00')
  })
})
