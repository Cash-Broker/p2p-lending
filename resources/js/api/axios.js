import axios from 'axios'
import { observeBuild } from '../utils/buildVersion'

/**
 * Generate a UUID v4.
 * Uses crypto.randomUUID() on modern browsers, with a fallback for older ones
 * that still have crypto.getRandomValues (IE is not supported — we're modern only).
 */
function generateUUID() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)
    bytes[6] = (bytes[6] & 0x0f) | 0x40 // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80 // variant 10
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
  }

  // Last-resort fallback — non-cryptographic but always available
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  withXSRFToken: true,
})

/**
 * Auto-inject X-Idempotency-Key on money-moving POST requests that require it.
 *
 * The backend rejects POST /loans/{id}/invest without this header (422).
 * The header lets the backend deduplicate client retries — if a request is sent
 * twice with the same key, the second call returns the original investment
 * instead of creating a duplicate.
 *
 * A fresh UUID is generated per request unless the caller explicitly sets one.
 * Callers that need true retry-safe semantics (same UUID across retries) should
 * set the header themselves before the POST.
 */
api.interceptors.request.use((config) => {
  const method = (config.method || '').toLowerCase()
  const url = config.url || ''
  const requiresIdempotencyKey = method === 'post' && /\/loans\/[^/]+\/invest$/.test(url)

  if (requiresIdempotencyKey) {
    const headers = config.headers || {}
    const hasKey = !!(headers['X-Idempotency-Key'] || headers['x-idempotency-key'])
    if (!hasKey) {
      config.headers = { ...headers, 'X-Idempotency-Key': generateUUID() }
    }
  }

  return config
})

/**
 * When a gated action is refused because the user hasn't accepted the current
 * Terms/Privacy, surface the re-consent modal. Lazy-imported to avoid a circular
 * dependency (the store imports this axios instance); Pinia is active by the
 * time any request runs.
 */
api.interceptors.response.use(
  (response) => {
    // Deploy detection — a changed X-Build marks this tab stale; the router
    // silently reloads it on the next navigation (see utils/buildVersion).
    observeBuild(response.headers?.['x-build'])
    return response
  },
  (error) => {
    if (error.response?.status === 403 && error.response.data?.error === 'consent_required') {
      import('../stores/consent').then(({ useConsentStore }) => useConsentStore().forcePrompt())
    }
    observeBuild(error.response?.headers?.['x-build'])
    return Promise.reject(error)
  },
)

export default api
