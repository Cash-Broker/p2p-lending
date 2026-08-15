// Stale-tab self-refresh (2026-08-15): the backend stamps every API response
// with X-Build (fingerprint of the deployed Vite manifest). The first value
// this tab sees becomes its baseline; when a later response carries a
// DIFFERENT fingerprint, a deploy happened while the tab was open — we mark
// it and the router reloads the page on the next navigation (never mid-form).

let baseline = null
let stale = false

/** Feed an X-Build header value from any API response. */
export function observeBuild(build) {
  if (!build) return
  if (baseline === null) {
    baseline = build
    return
  }
  if (build !== baseline) {
    stale = true
  }
}

/** True when a newer build was seen — reload at the next safe moment. */
export function isStale() {
  return stale
}

/** Test hook. */
export function resetBuildState() {
  baseline = null
  stale = false
}
