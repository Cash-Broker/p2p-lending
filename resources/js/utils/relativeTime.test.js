import { describe, expect, it } from 'vitest'
import { formatRelativeBg } from './relativeTime'

describe('formatRelativeBg', () => {
  const now = Date.parse('2026-08-14T12:00:00Z')

  it('formats minutes, hours and days in Bulgarian', () => {
    expect(formatRelativeBg('2026-08-14T11:52:00Z', now)).toBe('преди 8 мин')
    expect(formatRelativeBg('2026-08-14T09:00:00Z', now)).toBe('преди 3 ч')
    expect(formatRelativeBg('2026-08-13T11:00:00Z', now)).toBe('преди 1 ден')
    expect(formatRelativeBg('2026-08-10T11:00:00Z', now)).toBe('преди 4 дни')
  })

  it('clamps future/just-now to «по-малко от минута»', () => {
    expect(formatRelativeBg('2026-08-14T12:00:30Z', now)).toBe('преди по-малко от минута')
  })

  it('returns null for garbage', () => {
    expect(formatRelativeBg('not-a-date', now)).toBeNull()
  })
})
