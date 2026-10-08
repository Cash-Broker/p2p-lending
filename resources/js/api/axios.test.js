import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import api from './axios'

/**
 * The shared instance's request interceptor is the only thing that puts an
 * X-Idempotency-Key on the money-moving POSTs (the backend 422s an invest
 * without one; a withdrawal without one can reserve the same money twice on a
 * retry). An axios upgrade rewrites the path between the interceptor and the
 * adapter (1.20.0 snapshots the config into a null-prototype object), so these
 * tests drive real requests through the real dispatch chain and capture what
 * the adapter would have put on the wire.
 */
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

let sent
let originalAdapter

beforeEach(() => {
  sent = []
  originalAdapter = api.defaults.adapter
  api.defaults.adapter = async (config) => {
    sent.push(config)
    return { data: {}, status: 200, statusText: 'OK', headers: {}, config, request: {} }
  }
})

afterEach(() => {
  api.defaults.adapter = originalAdapter
})

describe('api request interceptor — X-Idempotency-Key', () => {
  it('stamps a fresh UUID v4 on every invest POST', async () => {
    await api.post('/loans/7/invest', { amount: '100.00', loan_offer_id: 1 })
    await api.post('/loans/7/invest', { amount: '100.00', loan_offer_id: 1 })

    const [first, second] = sent.map((config) => config.headers.get('X-Idempotency-Key'))
    expect(first).toMatch(UUID_V4)
    expect(second).toMatch(UUID_V4)
    expect(first).not.toBe(second)
  })

  it('stamps the withdrawal POST', async () => {
    await api.post('/withdrawal', { amount: '50.00', saved_iban_id: 3 })

    expect(sent[0].headers.get('X-Idempotency-Key')).toMatch(UUID_V4)
  })

  it('keeps a key the caller set itself — a retry must reuse it', async () => {
    await api.post('/withdrawal', { amount: '50.00' }, { headers: { 'X-Idempotency-Key': 'caller-key-1' } })
    await api.post('/loans/7/invest', { amount: '100.00' }, { headers: { 'x-idempotency-key': 'caller-key-2' } })

    expect(sent[0].headers.get('X-Idempotency-Key')).toBe('caller-key-1')
    expect(sent[1].headers.get('X-Idempotency-Key')).toBe('caller-key-2')
  })

  it('leaves reads and unrelated POSTs without a key', async () => {
    await api.get('/withdrawal')
    await api.post('/profile/ibans', { iban: 'BG80BNBG96611020345678' })

    expect(sent[0].headers.get('X-Idempotency-Key')).toBeUndefined()
    expect(sent[1].headers.get('X-Idempotency-Key')).toBeUndefined()
  })

  it('sends a JSON body to the API', async () => {
    await api.post('/loans/7/invest', { amount: '100.00' })

    expect(sent[0].url).toBe('/loans/7/invest')
    expect(sent[0].baseURL).toBe('/api')
    expect(JSON.parse(sent[0].data)).toEqual({ amount: '100.00' })
  })

  it('passes the KYC FormData through untouched when the caller asks for multipart', async () => {
    // The instance default is application/json — without the explicit
    // multipart header axios would JSON-stringify the files away.
    const formData = new FormData()
    formData.append('biometric_consent', '1')

    await api.post('/profile/kyc', formData, { headers: { 'Content-Type': 'multipart/form-data' } })

    expect(sent[0].data).toBe(formData)
  })
})
