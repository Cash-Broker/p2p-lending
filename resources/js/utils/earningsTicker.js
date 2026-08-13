// Pure helpers for the dashboard «Спечелени» ticker. DISPLAY-ONLY math —
// the backend's bcmath figures are the reference; floats here only animate
// the number between two server snapshots, no money decision reads them.

/**
 * Value of the live ticker `elapsedMs` after the base snapshot was received.
 * Negative elapsed (clock weirdness) clamps to the base value.
 */
export function tickerValue(baseAmount, perSecondRate, elapsedMs) {
  const base = parseFloat(baseAmount) || 0
  const rate = parseFloat(perSecondRate) || 0
  const elapsedSeconds = Math.max(0, elapsedMs || 0) / 1000
  return base + rate * elapsedSeconds
}

/** bg-BG money formatting, always with a fixed number of decimals. */
export function formatEuro(value, decimals = 2) {
  const numeric = Number.isFinite(value) ? value : 0
  return numeric.toLocaleString('bg-BG', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}

/**
 * Badge text for a pace figure («+0,44» / «< 0,01»), or null when nothing is
 * accruing (or the intro animation hasn't reached the badges yet). The sign
 * lives HERE: prefixing '+' in a template would garble the sub-stotinka
 * branch into '+< 0,01'. `progress` (0..1) scales the number during the
 * count-up intro; sub-stotinka rates don't count up — they fade in whole.
 */
export function animatedRateText(rate, progress = 1) {
  const numeric = parseFloat(rate) || 0
  if (numeric <= 0 || progress <= 0) return null
  if (numeric < 0.01) return '< 0,01'
  const scaled = numeric * Math.min(1, progress)
  // Early intro frames of a small rate would round to «+0,00» — the exact
  // display the sub-stotinka branch exists to avoid. Hold the badge until
  // it has at least a stotinka to show.
  if (scaled < 0.005) return null
  return `+${formatEuro(scaled)}`
}

/**
 * Eased (easeOutCubic) 0..1 progress for the load-in count-up animation.
 * Negative elapsed (stage not started) → 0; past the duration → 1.
 */
export function countUpProgress(elapsedMs, durationMs) {
  if (!(durationMs > 0)) return 1
  const p = Math.min(1, Math.max(0, elapsedMs / durationMs))
  return 1 - Math.pow(1 - p, 3)
}
