import { describe, it, expect } from 'vitest'
import { requiresIdempotencyKey } from './idempotency'

describe('requiresIdempotencyKey', () => {
  it('marks the invest POST (with or without a query string)', () => {
    expect(requiresIdempotencyKey('post', '/loans/42/invest')).toBe(true)
    expect(requiresIdempotencyKey('POST', '/loans/42/invest?debug=1')).toBe(true)
  })

  it('marks the withdrawal POST — a retried request must not reserve money twice', () => {
    expect(requiresIdempotencyKey('post', '/withdrawal')).toBe(true)
  })

  it('leaves reads and other posts alone', () => {
    expect(requiresIdempotencyKey('get', '/withdrawal')).toBe(false)
    expect(requiresIdempotencyKey('get', '/withdrawal/history')).toBe(false)
    expect(requiresIdempotencyKey('post', '/withdrawal/history')).toBe(false)
    expect(requiresIdempotencyKey('post', '/loans/42/invest/quote')).toBe(false)
    expect(requiresIdempotencyKey('post', '/profile/ibans')).toBe(false)
    expect(requiresIdempotencyKey(undefined, undefined)).toBe(false)
  })
})
