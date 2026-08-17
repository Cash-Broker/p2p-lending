// What (if anything) should the dashboard say about notifications?
// (Yordan/Reni 2026-08-17: «трябва да ги питаме» — the Профил card is passive
// and existing investors would never find it.)
//
// Pure decision logic, no browser calls — the caller passes the observed
// state, so every branch is unit-testable.

const SNOOZE_KEY = 'vama_push_prompt_snoozed_until'

/** Dismissing buys 30 days of silence — asking again next login would nag. */
export const SNOOZE_DAYS = 30

/**
 * One of:
 *   'ask'         — undecided: invite them (our copy first, browser prompt on click)
 *   'denied'      — the browser is blocking us; only site settings can undo it,
 *                   so explain instead of vanishing silently (Yordan hit exactly
 *                   this: «гугъл хром ме кара да активирам известията ръчно»)
 *   'ios-install' — iPhone/iPad in a Safari TAB: iOS grants Web Push only to a
 *                   home-screen install, so say that rather than show nothing
 *   null          — nothing to say (already on, snoozed, or truly unsupported)
 */
export function promptMode({
  permission,
  snoozedUntil = null,
  isIos = false,
  isStandalone = false,
  nowMs = Date.now(),
} = {}) {
  const until = Number(snoozedUntil)
  if (Number.isFinite(until) && until > nowMs) return null

  if (permission === 'granted') return null
  if (permission === 'denied') return 'denied'
  if (permission === 'default') return 'ask'

  // 'unsupported'
  return isIos && !isStandalone ? 'ios-install' : null
}

/** Timestamp to store when the invitation is dismissed. */
export function snoozeUntil(nowMs = Date.now(), days = SNOOZE_DAYS) {
  return nowMs + days * 24 * 60 * 60 * 1000
}

export function readSnooze() {
  try {
    return localStorage.getItem(SNOOZE_KEY)
  } catch {
    return null // private mode — treat as "never snoozed"
  }
}

export function writeSnooze(value) {
  try {
    localStorage.setItem(SNOOZE_KEY, String(value))
  } catch {
    // Storage unavailable: the strip reappears next visit. Acceptable.
  }
}

/** iPhone/iPad, including iPadOS which reports itself as a Mac with touch. */
export function detectIos(userAgent = navigator.userAgent, maxTouchPoints = navigator.maxTouchPoints) {
  return /iPad|iPhone|iPod/.test(userAgent) || (/Macintosh/.test(userAgent) && maxTouchPoints > 1)
}

/** Running as an installed app (home-screen / standalone window). */
export function detectStandalone() {
  return (
    window.navigator.standalone === true ||
    (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches)
  )
}
