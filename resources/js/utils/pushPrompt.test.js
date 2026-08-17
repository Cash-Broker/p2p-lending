import { describe, it, expect } from 'vitest'
import { shouldAskAboutPush, snoozeUntil, SNOOZE_DAYS } from './pushPrompt'

describe('shouldAskAboutPush', () => {
  const now = new Date(2026, 7, 17, 12, 0).getTime()

  it('asks an undecided investor who has never snoozed', () => {
    expect(shouldAskAboutPush('default', null, now)).toBe(true)
  })

  it('never asks when the decision is already made', () => {
    // granted → already on; denied → the browser will not re-prompt, and
    // nagging cannot change it (only site settings can).
    expect(shouldAskAboutPush('granted', null, now)).toBe(false)
    expect(shouldAskAboutPush('denied', null, now)).toBe(false)
    expect(shouldAskAboutPush('unsupported', null, now)).toBe(false)
  })

  it('stays silent inside the snooze window and returns after it', () => {
    const until = snoozeUntil(now)
    expect(shouldAskAboutPush('default', until, now)).toBe(false)
    expect(shouldAskAboutPush('default', until, until - 1000)).toBe(false)
    expect(shouldAskAboutPush('default', until, until + 1000)).toBe(true)
  })

  it('treats a corrupt snooze value as "never snoozed"', () => {
    expect(shouldAskAboutPush('default', 'nonsense', now)).toBe(true)
    expect(shouldAskAboutPush('default', undefined, now)).toBe(true)
  })
})

describe('snoozeUntil', () => {
  it('is 30 days out by default', () => {
    const now = 1_000_000
    expect(snoozeUntil(now)).toBe(now + SNOOZE_DAYS * 86_400_000)
    expect(SNOOZE_DAYS).toBe(30)
  })
})
