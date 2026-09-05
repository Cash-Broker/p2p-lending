import axios from 'axios'
import { observeBuild } from '../utils/buildVersion'
import { shouldForceRelogin, expiredLoginUrl } from '../utils/sessionGuard'
import { requiresIdempotencyKey } from '../utils/idempotency'
// Static on purpose (auth.js also imports this module — the cycle is safe:
// both sides only touch the other's export inside runtime functions). The
// expiry check below must run SYNCHRONOUSLY in the rejection chain: a lazy
// import() resolves in a later microtask, by which time fetchUser()'s catch
// may already have nulled the user and the redirect would never fire.
import { useAuthStore } from '../stores/auth'

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
 * The backend rejects POST /loans/{id}/invest without this header (422);
 * POST /withdrawal accepts it and returns the same request on a retry
 * (audit 2026-09-01, PAY-03). The list lives in utils/idempotency.js.
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
  if (requiresIdempotencyKey(method, url)) {
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

    // Session expiry (401) / CSRF-cookie expiry (419) while the SPA still
    // thinks it is logged in ⇒ hard re-boot into /login. Critical for the
    // installed PWA (Reni 2026-08-16): the standalone window survives far past
    // SESSION_LIFETIME and has NO address bar to hard-refresh, so without this
    // every screen dead-ends in «Опитай отново» → 401 → «Опитай отново».
    // location.assign (not router.push) on purpose: a full page load also
    // reboots a stale bundle and re-runs the auth guard from zero.
    try {
      const auth = useAuthStore()
      if (shouldForceRelogin(error.response?.status, window.location.pathname, !!auth.user, error.config?.url ?? '')) {
        auth.user = null
        // The hard redirect is a same-tab navigation — sessionStorage
        // survives it. Drop the welcome-back block so the post-relogin
        // dashboard adopts the SERVER's fresh since-last-visit figures
        // (they cover exactly the absence that expired the session).
        try { sessionStorage.removeItem('vama_welcome_back') } catch { /* storage off */ }
        window.location.assign(expiredLoginUrl(window.location.pathname, window.location.search))
        // Never settle: the page is unloading. Rejecting here would paint the
        // callers' «Опитай отново» error states for a beat before /login lands
        // — the exact screen this redirect exists to kill.
        return new Promise(() => {})
      }
    } catch {
      // Pinia not active yet (request fired before app boot) — nothing to do.
    }

    observeBuild(error.response?.headers?.['x-build'])
    return Promise.reject(error)
  },
)

export default api
