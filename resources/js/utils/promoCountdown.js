// Pure helpers for the flash-promo countdown. DISPLAY-ONLY — the backend's
// ends_at is the authority; these only animate the time between polls.

/** Milliseconds remaining until the ISO deadline (never below 0). */
export function remainingMs(endsAtIso, nowMs) {
  const end = new Date(endsAtIso).getTime()
  if (!Number.isFinite(end)) return 0
  return Math.max(0, end - nowMs)
}

/**
 * «59:26» under an hour, «1:23:45» above it — casino-clock format, always
 * padded, never negative.
 */
export function formatCountdown(ms) {
  const totalSeconds = Math.max(0, Math.floor((ms || 0) / 1000))
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60
  const mm = String(minutes).padStart(2, '0')
  const ss = String(seconds).padStart(2, '0')
  return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`
}
