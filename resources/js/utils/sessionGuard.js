// Session-expiry recovery for the installed-PWA case (Reni 2026-08-16): the
// standalone app keeps the SPA alive far past SESSION_LIFETIME, so the next
// API call 401s with no address bar to hard-refresh. The axios interceptor
// asks this predicate whether to force a clean re-login boot.

/** Paths where a 401 is part of the normal guest flow — never bounce from them. */
const GUEST_SAFE_PATHS = ['/login', '/register', '/forgot-password', '/reset-password']

/**
 * Requests whose 401 must NOT trigger the expiry bounce. `/logout` is an
 * explicit exit — it must land on a banner-free /login. `/push/subscribe` is
 * device housekeeping that runs ON the logout path: bouncing there swallowed
 * the whole logout (the interceptor returns a never-settling promise, so
 * auth.logout() was never reached — review 2026-08-17).
 */
const HOUSEKEEPING_ENDPOINTS = ['/logout', '/push/subscribe']

/**
 * Should this API failure force a hard redirect to /login?
 *
 * True only when ALL hold:
 * - status is 401 (session gone) or 419 (CSRF token gone — same root cause);
 * - the SPA still BELIEVES it is authenticated (hasUser) — a guest getting a
 *   401 from the boot-time /user probe is normal, not an expiry;
 * - the failing call is not housekeeping (logout / push cleanup) — those must
 *   never hijack the flow they are part of;
 * - we are not already on an auth screen (no redirect loops).
 */
export function shouldForceRelogin(status, currentPath, hasUser, requestUrl = '') {
  if (status !== 401 && status !== 419) return false
  if (!hasUser) return false
  if (HOUSEKEEPING_ENDPOINTS.some((p) => String(requestUrl).endsWith(p))) return false
  return !GUEST_SAFE_PATHS.some((p) => String(currentPath).startsWith(p))
}

/** Login URL that survives the bounce: expired hint + way back to where she was. */
export function expiredLoginUrl(path, search = '') {
  const redirect = encodeURIComponent(`${path}${search}`)
  return `/login?expired=1&redirect=${redirect}`
}
