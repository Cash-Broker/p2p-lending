// Landing-nav gating (Reni 2026-08-20). «Кредити» and «Оригинатори» sit in the
// public navigation, but an outside visitor loads NOTHING behind them — they
// get an invitation to register. A registered AND approved investor clicking
// «Кредити» lands on their own positions «докато не направим публични нещата»;
// «Оригинатори» loads no data for anyone (see OriginatorsPage.vue).
//
// Pure decision helpers so the rule is unit-testable — the router guard and
// the two views only render what these return.

export const STATE_GUEST = 'guest'
export const STATE_ADMIN = 'admin'
export const STATE_PENDING = 'pending'
export const STATE_REJECTED = 'rejected'
export const STATE_APPROVED = 'approved'

/**
 * Which audience is looking at a gated public page?
 *
 * `user` is the /api/user payload (null for guests). KYC status is read for
 * display and routing convenience only — the server middleware stays
 * authoritative for everything that touches money.
 */
export function publicPageState(user) {
  if (!user) return STATE_GUEST
  if (user.role === 'admin') return STATE_ADMIN
  if (user.kyc_status === 'approved') return STATE_APPROVED
  if (user.kyc_status === 'rejected') return STATE_REJECTED

  return STATE_PENDING
}

/**
 * Where a click on «Кредити» must land, or null to render the page in place.
 *
 * Only the approved investor is moved: /portfolio is the single source of
 * truth for "моите кредити", and money must not be re-rendered on a public
 * page. Everyone else — guest, admin, investor awaiting approval — stays and
 * gets the panel that fits them, exactly like on every other public route
 * (the global guard ejects admins from `meta.auth` routes only).
 */
export function loansPageRedirect(user) {
  return publicPageState(user) === STATE_APPROVED ? '/portfolio' : null
}

/**
 * Registered but not approved: has the investor still to UPLOAD documents, or
 * are they already in the review queue? Decides whether the panel asks for an
 * action or for patience. `pending` is the state of a fresh registration.
 */
export function awaitsKycUpload(user) {
  return (user?.kyc_status ?? 'pending') === 'pending'
}
