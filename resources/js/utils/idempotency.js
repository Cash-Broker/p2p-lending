/**
 * Which SPA requests must carry an X-Idempotency-Key.
 *
 * invest:     the backend refuses the POST without it (422).
 * withdrawal: audit 2026-09-01 (PAY-03) — a retried POST /withdrawal used to
 *             reserve the same money twice; with the key the server returns
 *             the first request instead of creating a second hold.
 *
 * Pure function so the list is unit-tested; axios.js only calls it.
 */
const IDEMPOTENT_POSTS = [/\/loans\/[^/]+\/invest$/, /\/withdrawal$/]

export function requiresIdempotencyKey(method, url) {
  if ((method || '').toLowerCase() !== 'post') return false
  const path = (url || '').split('?')[0]
  return IDEMPOTENT_POSTS.some((pattern) => pattern.test(path))
}
