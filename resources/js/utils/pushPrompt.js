// Should we ASK this investor about notifications? (Yordan/Reni 2026-08-17:
// «трябва да ги питаме дали искат да получават известия» — the Профил card
// alone is passive, existing investors would never find it.)
//
// Pure decision logic, no browser calls — the caller passes the current
// permission state so this stays unit-testable.

const SNOOZE_KEY = 'vama_push_prompt_snoozed_until'

/** «Не сега» buys 30 days of silence — asking again next login would nag. */
export const SNOOZE_DAYS = 30

/**
 * True only when asking is both possible and polite:
 * - the browser can do push at all ('unsupported' → nothing to offer);
 * - the person has NOT decided yet ('granted' → already on, 'denied' → the
 *   browser will not re-prompt anyway, and pestering cannot change it);
 * - they haven't dismissed the banner inside the snooze window.
 */
export function shouldAskAboutPush(permission, snoozedUntil, nowMs = Date.now()) {
  if (permission !== 'default') return false
  const until = Number(snoozedUntil)
  if (Number.isFinite(until) && until > nowMs) return false
  return true
}

/** Timestamp to store when the investor picks «Не сега». */
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
    // Storage unavailable: the banner reappears next visit. Acceptable.
  }
}
