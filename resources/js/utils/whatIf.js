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
