// Session-expiry recovery for the installed-PWA case (Reni 2026-08-16): the
// standalone app keeps the SPA alive far past SESSION_LIFETIME, so the next
// API call 401s with no address bar to hard-refresh. The axios interceptor
// asks this predicate whether to force a clean re-login boot.

/** Paths where a 401 is part of the normal guest flow — never bounce from them. */
const GUEST_SAFE_PATHS = ['/login', '/register', '/forgot-password', '/reset-password']

/**
 * Should this API failure force a hard redirect to /login?
 *
 * True only when ALL hold:
 * - status is 401 (session gone) or 419 (CSRF token gone — same root cause);
 * - the SPA still BELIEVES it is authenticated (hasUser) — a guest getting a
 *   401 from the boot-time /user probe is normal, not an expiry;
 * - the failing call is not the logout POST itself — an explicit «Изход» that
 *   401s must end on a banner-free /login (the store clears state and
 *   AppLayout navigates), not on «сесията изтече, ще ви върнем обратно»;
 * - we are not already on an auth screen (no redirect loops).
 */
export function shouldForceRelogin(status, currentPath, hasUser, requestUrl = '') {
  if (status !== 401 && status !== 419) return false
  if (!hasUser) return false
  if (String(requestUrl).endsWith('/logout')) return false
  return !GUEST_SAFE_PATHS.some((p) => String(currentPath).startsWith(p))
}

/** Login URL that survives the bounce: expired hint + way back to where she was. */
export function expiredLoginUrl(path, search = '') {
  const redirect = encodeURIComponent(`${path}${search}`)
  return `/login?expired=1&redirect=${redirect}`
}
