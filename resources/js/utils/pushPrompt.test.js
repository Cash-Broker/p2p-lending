import { describe, it, expect } from 'vitest'
import { promptMode, snoozeUntil, detectIos, SNOOZE_DAYS } from './pushPrompt'

describe('promptMode', () => {
  const now = new Date(2026, 7, 17, 12, 0).getTime()

  it('invites an undecided investor', () => {
    expect(promptMode({ permission: 'default', nowMs: now })).toBe('ask')
  })

  it('says nothing when notifications are already on', () => {
    expect(promptMode({ permission: 'granted', nowMs: now })).toBeNull()
  })

  it('explains a browser block instead of vanishing', () => {
    // Yordan hit this: Chrome had the site blocked, the banner disappeared and
    // he was left wondering why he had to allow it "by hand".
    expect(promptMode({ permission: 'denied', nowMs: now })).toBe('denied')
  })

  it('tells iPhone users in a Safari tab to install the app', () => {
    expect(promptMode({ permission: 'unsupported', isIos: true, isStandalone: false, nowMs: now }))
      .toBe('ios-install')
    // Already installed (or a desktop browser without push) → stay quiet.
    expect(promptMode({ permission: 'unsupported', isIos: true, isStandalone: true, nowMs: now })).toBeNull()
    expect(promptMode({ permission: 'unsupported', isIos: false, nowMs: now })).toBeNull()
  })

  it('honours the snooze for every mode', () => {
    const until = snoozeUntil(now)
    for (const permission of ['default', 'denied', 'unsupported']) {
      expect(promptMode({ permission, snoozedUntil: until, isIos: true, nowMs: now })).toBeNull()
    }
    // …and speaks again once it lapses.
    expect(promptMode({ permission: 'default', snoozedUntil: until, nowMs: until + 1000 })).toBe('ask')
  })

  it('treats a corrupt snooze value as "never snoozed"', () => {
    expect(promptMode({ permission: 'default', snoozedUntil: 'nonsense', nowMs: now })).toBe('ask')
  })
})

describe('snoozeUntil', () => {
  it('is 30 days out by default', () => {
    expect(snoozeUntil(1_000_000)).toBe(1_000_000 + SNOOZE_DAYS * 86_400_000)
  })
})

describe('detectIos', () => {
  it('spots iPhone and iPad, including the Mac-shaped iPadOS UA', () => {
    expect(detectIos('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', 5)).toBe(true)
    // iPadOS reports a Macintosh UA but has touch points.
    expect(detectIos('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5)).toBe(true)
  })

  it('does not mistake a real Mac or a PC for iOS', () => {
    expect(detectIos('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 0)).toBe(false)
    expect(detectIos('Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 0)).toBe(false)
  })
})
