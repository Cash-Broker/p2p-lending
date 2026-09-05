import { describe, it, expect } from 'vitest'
import { showCountdown, daysLeft, deletionLabel } from './deletionState'

const now = new Date('2026-09-05T10:00:00Z')

describe('deletionState', () => {
  it('shows the countdown strip only for a scheduled closure', () => {
    expect(showCountdown(null)).toBe(false)
    expect(showCountdown({ deletion: null })).toBe(false)
    expect(showCountdown({ deletion: { state: 'awaiting_confirmation' } })).toBe(false)
    expect(showCountdown({ deletion: { state: 'scheduled', scheduled_for: '2026-09-12T04:00:00Z' } })).toBe(true)
  })

  it('counts the days left and never goes negative', () => {
    expect(daysLeft('2026-09-12T04:00:00Z', now)).toBe(7)
    expect(daysLeft('2026-09-05T12:00:00Z', now)).toBe(1)
    expect(daysLeft('2026-09-01T00:00:00Z', now)).toBe(0)
    expect(daysLeft('garbage', now)).toBe(0)
  })

  it('labels the two open states in Bulgarian', () => {
    expect(deletionLabel({ state: 'awaiting_confirmation', requested_at: '2026-09-05T09:00:00Z' }, now)).toContain('чака потвърждение')
    expect(deletionLabel({ state: 'scheduled', scheduled_for: '2026-09-12T04:00:00Z' }, now)).toContain('след 7 дни')
    expect(deletionLabel({ state: 'scheduled', scheduled_for: '2026-09-01T00:00:00Z' }, now)).toContain('днес')
    expect(deletionLabel(null, now)).toBe('')
  })
})
