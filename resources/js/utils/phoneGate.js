// Mandatory-phone gating (client decision 2026-08-25): every investor account
// must carry a contact phone. New registrations collect it in the form for
// both account types; accounts created BEFORE the requirement get a blocking
// modal (no dismiss) the next time they open the app.
//
// Pure decision helper so the rule is unit-testable — AppLayout only renders
// what this returns.

/**
 * Should the blocking «Добавете телефонен номер» modal be shown?
 *
 * `user` is the /api/user payload (null for guests). Unverified accounts are
 * excluded: the modal saves via PUT /api/profile, which sits behind the
 * `investor` middleware (verified email required) — prompting before
 * verification would dead-end in a 403, and the verify-email screen lives
 * outside AppLayout anyway.
 */
export function needsPhonePrompt(user) {
  if (!user) return false
  if (user.role === 'admin') return false
  if (!user.email_verified_at) return false

  return String(user.phone ?? '').trim() === ''
}
