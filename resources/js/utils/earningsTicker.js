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
 * Badge text for the daily pace («+0,44» / «< 0,01»), or null when nothing is
 * accruing. The sign lives HERE: prefixing '+' in a template would garble the
 * sub-stotinka branch into '+< 0,01'.
 */
export function formatDailyRate(rate) {
  const numeric = parseFloat(rate) || 0
  if (numeric <= 0) return null
  return numeric < 0.01 ? '< 0,01' : `+${formatEuro(numeric)}`
}
