/**
 * Push-only service worker (2026-08-17).
 *
 * ⚠ DELIBERATELY NO CACHING. This worker exists solely to receive Web Push
 * and show notifications. It must never intercept fetches or cache assets —
 * the SPA's freshness is managed by the X-Build self-refresh mechanism
 * (utils/buildVersion.js), and a caching SW would resurrect the stale-bundle
 * problems that mechanism exists to kill. Do not add a `fetch` handler.
 */

self.addEventListener('install', () => {
  // Activate the updated worker immediately — no caches to warm up.
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim())
})

self.addEventListener('push', (event) => {
  if (!event.data) return

  let payload
  try {
    payload = event.data.json()
  } catch {
    return // Unparseable push — drop silently, never throw in the SW.
  }

  const title = payload.title || 'Vamaasset'
  const options = {
    body: payload.body || '',
    icon: payload.icon || '/logo/logo-mark.png',
    badge: payload.badge || '/logo/logo-mark.png',
    // tag collapses same-topic notifications instead of stacking duplicates
    tag: payload.tag || undefined,
    renotify: Boolean(payload.renotify),
    data: {
      // Where a tap should land (SPA path or /admin URL).
      url: (payload.data && payload.data.url) || '/',
    },
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || '/'

  event.waitUntil(
    (async () => {
      let targetPath = '/'
      try {
        targetPath = new URL(url, self.location.origin).pathname
      } catch {
        // Malformed payload url — fall through to the root.
      }

      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

      // Already on the target screen: focus it and ask the page to refresh
      // its own data. A push always means "something changed", so focusing a
      // stale screen would show the OLD figures — and a blind navigate()
      // would reload over anything the user was in the middle of.
      for (const client of windows) {
        try {
          if (new URL(client.url).pathname === targetPath) {
            await client.focus()
            client.postMessage({ type: 'vama-push-refresh' })

            return
          }
        } catch {
          // Unparseable client url — treat as non-match.
        }
      }

      // Otherwise reuse a window, but await navigate(): it REJECTS for clients
      // this worker doesn't control (includeUncontrolled admits those), and a
      // swallowed rejection would leave the tap doing nothing at all.
      for (const client of windows) {
        try {
          await client.focus()
          await client.navigate(url)

          return
        } catch {
          // Try the next window, else fall through to a fresh one.
        }
      }

      return self.clients.openWindow(url)
    })(),
  )
})
