/**
 * Can the investor still put money into this loan?
 *
 * PAY-30 (owner 2026-09-03): a partially funded loan that closed (`repaid`
 * from `funding`) still reports funded_percentage < 100, so the percentage
 * alone would keep rendering the invest form and the API would answer 422.
 * The status is the first word; the percentage the second.
 */
export const FUNDABLE_STATUSES = ['published', 'funding']

export function canInvest(loan) {
  if (!loan || typeof loan !== 'object') return false
  if (!FUNDABLE_STATUSES.includes(loan.status)) return false
  const pct = Number(loan.funded_percentage)
  return Number.isFinite(pct) ? pct < 100 : true
}
