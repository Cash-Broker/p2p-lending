// Pure math for the «какво-ако» slider. DISPLAY-ONLY projection at the
// «само лихва» plan: simple yearly interest, amount × rate%. Honest by
// construction — it mirrors exactly what the interest-only offer pays.

/** Yearly interest for `amount` at `ratePct` (% годишно), as a number. */
export function projectYearlyInterest(amount, ratePct) {
  const a = parseFloat(amount) || 0
  const r = parseFloat(ratePct) || 0
  if (a <= 0 || r <= 0) return 0
  return (a * r) / 100
}

/** Slider bounds: from the 50 € minimum up to a dream-sized max. */
export function sliderMax(available) {
  const a = parseFloat(available) || 0
  // At least 2 000 € of dream even for small balances; otherwise 2× the
  // free balance rounded up to a clean 500 step.
  return Math.max(2000, Math.ceil((a * 2) / 500) * 500)
}

// bcmath TRUNCATES at its scale (bcdiv/bcmul scale 2 cut, never round) — the
// replicas below mirror that so the dream number equals the engine's schedule
// total to the cent, not a smooth closed form a few cents above it.
// The pre-floor micro-round kills IEEE dust (66.14 stored as 66.13999…).
const truncCents = (v) => Math.floor(Math.round(v * 1e6) / 1e4) / 100
const trunc10 = (v) => Math.floor(Math.round(v * 1e12) / 100) / 1e10

/**
 * 12-month profit projection for one payout structure. Replicates the backend
 * OfferProjectionService/AmortizationService loop exactly (monthly rate =
 * annual/100/12 truncated at scale 10, per-row scale-2 truncation, last
 * annuity row absorbs drift) so the projection matches what a real 12-month
 * investment's schedule would actually total:
 * - interest_only: truncated flat monthly interest × 12
 * - amortizing: 12-month annuity — Σ per-row interest (principal returns
 *   monthly, hence visibly lower than interest-only at the same rate — honest)
 * - capitalized: monthly compounding, rounded once at maturity
 */
export function projectTwelveMonthProfit(amount, ratePct, payoutType = 'interest_only') {
  const a = truncCents(parseFloat(amount) || 0)
  const r = parseFloat(ratePct) || 0
  if (a <= 0 || r <= 0) return 0
  const m = trunc10(r / 100 / 12)
  switch (payoutType) {
    case 'amortizing': {
      const monthlyPayment = truncCents((a * m) / (1 - Math.pow(1 + m, -12)))
      let remaining = a
      let interestSum = 0
      for (let i = 1; i <= 12; i++) {
        const interest = truncCents(remaining * m)
        const principalPart = i === 12 ? remaining : truncCents(monthlyPayment - interest)
        interestSum += interest
        remaining = truncCents(remaining - principalPart)
      }
      return truncCents(interestSum)
    }
    case 'capitalized':
      return truncCents(a * Math.pow(1 + m, 12)) - a
    default:
      return truncCents(a * m) * 12
  }
}
