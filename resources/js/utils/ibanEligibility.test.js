import { describe, it, expect } from 'vitest'
import { ibanOptionState } from './ibanEligibility'

const now = new Date('2026-09-05T10:00:00Z')

describe('ibanOptionState', () => {
  it('blocks an unconfirmed IBAN and says why', () => {
    expect(ibanOptionState({ confirmed: false, confirmation_expired: false }, now)).toEqual({ selectable: false, suffix: ' — непотвърден' })
    expect(ibanOptionState({ confirmed: false, confirmation_expired: true }, now)).toEqual({ selectable: false, suffix: ' — линкът изтече' })
  })

  it('blocks a confirmed IBAN still inside the cooling-off and shows when it opens', () => {
    const state = ibanOptionState({ confirmed: true, withdrawable_now: false, withdrawable_from: '2026-09-06T08:30:00Z' }, now)
    expect(state.selectable).toBe(false)
    expect(state.suffix).toContain('теглене от')
  })

  it('allows a confirmed IBAN past the cooling-off', () => {
    expect(ibanOptionState({ confirmed: true, withdrawable_now: true }, now)).toEqual({ selectable: true, suffix: '' })
    expect(ibanOptionState({ confirmed: true, withdrawable_now: false, withdrawable_from: '2026-09-01T00:00:00Z' }, now).selectable).toBe(true)
  })

  it('fails open on the old API shape and closed on garbage', () => {
    expect(ibanOptionState({ id: 1, iban: '****5678' }, now)).toEqual({ selectable: true, suffix: '' })
    expect(ibanOptionState(null, now).selectable).toBe(false)
  })
})
