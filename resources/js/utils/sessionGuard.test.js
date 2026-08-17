import { describe, it, expect } from 'vitest'
import { shouldForceRelogin, expiredLoginUrl } from './sessionGuard'

describe('shouldForceRelogin', () => {
  it('fires on 401 and 419 for an authenticated user on an app page', () => {
    expect(shouldForceRelogin(401, '/dashboard', true)).toBe(true)
    expect(shouldForceRelogin(419, '/portfolio', true)).toBe(true)
  })

  it('ignores other statuses', () => {
    for (const status of [403, 404, 422, 429, 500, undefined, null]) {
      expect(shouldForceRelogin(status, '/dashboard', true)).toBe(false)
    }
  })

  it('never fires for guests — the boot-time /user 401 is normal', () => {
    expect(shouldForceRelogin(401, '/', false)).toBe(false)
    expect(shouldForceRelogin(401, '/invest', false)).toBe(false)
  })

  it('never fires on auth screens (no redirect loops)', () => {
    expect(shouldForceRelogin(401, '/login', true)).toBe(false)
    expect(shouldForceRelogin(401, '/register', true)).toBe(false)
    expect(shouldForceRelogin(401, '/forgot-password', true)).toBe(false)
    expect(shouldForceRelogin(419, '/reset-password/abc123', true)).toBe(false)
  })

  it('never fires for housekeeping calls — logout and push cleanup', () => {
    expect(shouldForceRelogin(401, '/dashboard', true, '/logout')).toBe(false)
    expect(shouldForceRelogin(419, '/portfolio', true, '/api/logout')).toBe(false)
    // Push cleanup runs ON the logout path: bouncing there swallowed the
    // whole logout (the interceptor never settles its promise).
    expect(shouldForceRelogin(401, '/dashboard', true, '/push/subscribe')).toBe(false)
    expect(shouldForceRelogin(419, '/profile', true, '/push/subscribe')).toBe(false)
    // …but the same page and status DO fire for any other endpoint
    expect(shouldForceRelogin(401, '/dashboard', true, '/dashboard')).toBe(true)
    expect(shouldForceRelogin(401, '/dashboard', true, '/user')).toBe(true)
  })
})

describe('expiredLoginUrl', () => {
  it('carries the expired hint and the way back', () => {
    expect(expiredLoginUrl('/portfolio')).toBe('/login?expired=1&redirect=%2Fportfolio')
  })

  it('preserves query strings in the redirect target', () => {
    expect(expiredLoginUrl('/invest/12', '?tab=schedule'))
      .toBe('/login?expired=1&redirect=%2Finvest%2F12%3Ftab%3Dschedule')
  })
})
