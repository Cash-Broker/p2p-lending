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
