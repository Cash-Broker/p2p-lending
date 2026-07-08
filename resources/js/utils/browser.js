/**
 * Detect in-app browsers (WebViews) embedded in messenger/social apps.
 *
 * These WebViews routinely refuse getUserMedia because the HOST APP never
 * forwards the camera permission — the user can tap "allow" forever and the
 * KYC selfie will still fail. Links to the platform are shared mostly over
 * Viber in Bulgaria, so this is a common real-world case, and the only fix
 * is opening the link in a real browser (Chrome/Safari).
 *
 * Detection is deliberately conservative: it is only consulted AFTER a camera
 * failure, to pick the right error message — a false positive can never block
 * a working camera.
 */

// Explicit app tokens: Facebook (FBAN/FBAV = iOS, FB_IAB/FB4A = Android),
// Instagram, Viber, LINE, WeChat, TikTok.
const IN_APP_MARKERS = /\b(FBAN|FBAV|FB_IAB|FB4A|Instagram|Viber|Line\/|MicroMessenger|musical_ly|Bytedance)\b/i

// Android WebView identifies itself with a "; wv)" token that real Chrome
// never carries.
const ANDROID_WEBVIEW = /;\s*wv\)/

export function isInAppBrowser(userAgent) {
  if (!userAgent) return false

  if (IN_APP_MARKERS.test(userAgent) || ANDROID_WEBVIEW.test(userAgent)) return true

  // iOS WKWebView: WebKit UA without the "Safari/" token. Every real iOS
  // browser (Safari, CriOS, FxiOS, EdgiOS) keeps "Safari/" in its UA; embedded
  // WebViews (incl. Viber's, whose iOS UA carries no "Viber" token) drop it.
  //
  // KNOWN COLLISION: an iOS home-screen web app (standalone PWA) sends this
  // exact same UA, and there getUserMedia DOES work (iOS 16.4+). The UA alone
  // cannot tell them apart — call sites must exclude standalone display mode
  // first (see isStandaloneDisplayMode). Don't "fix" this here against the
  // Viber-iOS test fixture; it is byte-identical to the PWA UA by nature.
  const isIos = /iPhone|iPad|iPod/i.test(userAgent)
  return isIos && /AppleWebKit/i.test(userAgent) && !/Safari\//i.test(userAgent)
}

/**
 * True when running as an installed/home-screen app (standalone display mode).
 * Used to veto the iOS-WKWebView heuristic above — a standalone PWA has the
 * same UA as an embedded WebView but a fully working camera.
 */
export function isStandaloneDisplayMode() {
  if (typeof window === 'undefined') return false
  if (window.navigator?.standalone === true) return true // iOS Safari
  return window.matchMedia?.('(display-mode: standalone)')?.matches === true
}
