// Web Push client plumbing (2026-08-17). The service worker (public/sw.js)
// is push-only — NO caching, see the warning at its top.
import api from '../api/axios'

/** VAPID application server key, base64url → Uint8Array (subscribe format). */
export function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const output = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i)
  return output
}

export function pushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/** 'granted' | 'denied' | 'default' | 'unsupported' */
export function permissionState() {
  return pushSupported() ? Notification.permission : 'unsupported'
}

async function registration() {
  await navigator.serviceWorker.register('/sw.js')

  // pushManager.subscribe() requires an ACTIVE worker: on a first-ever
  // enrolment register() resolves while the worker is still installing, and
  // subscribing right then rejects with InvalidStateError (review
  // 2026-08-17). `ready` resolves only once a worker is active.
  return navigator.serviceWorker.ready
}

/** Is THIS browser currently subscribed (locally — server state may differ). */
export async function isSubscribed() {
  if (!pushSupported()) return false
  const reg = await navigator.serviceWorker.getRegistration('/sw.js')
  if (!reg) return false
  return Boolean(await reg.pushManager.getSubscription())
}

/**
 * Ask permission (browser prompt on first call), subscribe this device and
 * register it with the backend for the LOGGED-IN user. Throws on refusal so
 * the caller can show honest UI.
 */
export async function enablePush({ confirm = true } = {}) {
  if (!pushSupported()) throw new Error('unsupported')

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('denied')

  const key = document.querySelector('meta[name="vapid-public-key"]')?.content
  if (!key) throw new Error('missing-vapid-key')

  const reg = await registration()
  const subscription = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(key),
  })

  const json = subscription.toJSON()
  try {
    await api.post('/push/subscribe', {
      endpoint: json.endpoint,
      keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
      // Only a user-initiated enrolment earns the «здравей» confirmation;
      // a silent re-enrolment after logout passes confirm: false.
      confirm,
    })
  } catch (e) {
    // The browser subscription exists but the SERVER never stored it — this
    // device would never receive anything. Drop the orphan so the UI can't
    // claim «включени» for a capability the backend can't deliver
    // (review 2026-08-17).
    try {
      await subscription.unsubscribe()
    } catch {
      // Nothing more to do — the send path prunes dead endpoints anyway.
    }
    throw new Error('server-registration-failed')
  }

  return true
}

/**
 * Make sure THIS account has a subscription row for this device.
 *
 * Uniqueness is (endpoint + account), so one browser can serve several
 * accounts at once — Reni's phone runs both the admin panel and her investor
 * profile and both streams must arrive (2026-08-17). Nothing is stolen from
 * another account here; this only registers/refreshes the current one's row.
 * Silent by design.
 */
export async function assertOwnership() {
  try {
    if (!pushSupported() || Notification.permission !== 'granted') return

    const reg = await navigator.serviceWorker.getRegistration('/sw.js')
    let subscription = await reg?.pushManager.getSubscription()

    // Permission granted but no subscription — e.g. the browser dropped it, or
    // an older build's logout unsubscribed it. Re-enrol SILENTLY: no prompt is
    // needed once permission is granted, and without this the device stayed
    // permanently unnotified while both the card and the banner believed it was
    // already on (review 2026-08-17).
    if (!subscription) {
      await enablePush({ confirm: false })

      return
    }

    const json = subscription.toJSON()
    await api.post('/push/subscribe', {
      endpoint: json.endpoint,
      keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
      // Silent re-assert: never fires the «здравей» confirmation.
      confirm: false,
    })
  } catch {
    // Best-effort: a failed claim just leaves the previous owner in place.
  }
}

/**
 * Unsubscribe this device locally AND forget it server-side. Never throws —
 * used on logout, where cleanup must not block leaving.
 */
export async function disablePush() {
  try {
    if (!pushSupported()) return
    const reg = await navigator.serviceWorker.getRegistration('/sw.js')
    const subscription = await reg?.pushManager.getSubscription()
    if (!subscription) return

    // Drop only THIS account's row. The browser subscription itself is shared
    // infrastructure for the whole origin: unsubscribing it on logout also
    // killed the OTHER account registered on the same browser — Reni holds an
    // admin and an investor account and her investor logout silenced the admin
    // stream until she reopened /admin (2026-08-17). No server row ⇒ no pushes
    // for the account that left, which is all the logout has to guarantee.
    await api.delete('/push/subscribe', { data: { endpoint: subscription.endpoint } })
  } catch {
    // Session already dead / offline — the row is pruned on the next send
    // when the push service reports the endpoint gone (404/410).
  }
}
