import { describe, expect, it } from 'vitest'
import { isInAppBrowser } from './browser'

// Real-world user agent strings (trimmed to the discriminating parts).
const IN_APP = {
  'Viber Android': 'Mozilla/5.0 (Linux; Android 13; SM-A536B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.0.0 Mobile Safari/537.36 Viber',
  'Viber iOS (no app token — bare WKWebView UA)': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
  'Facebook iOS': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/460.0.0.36.106;FBBV/577025192]',
  'Facebook Android': 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/455.0.0.30.107;]',
  'Instagram Android': 'Mozilla/5.0 (Linux; Android 13; SM-S911B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/121.0.0.0 Mobile Safari/537.36 Instagram 320.0.0.42.101',
  'Generic Android WebView': 'Mozilla/5.0 (Linux; Android 12; M2101K6G Build/SKQ1.210908.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/119.0.0.0 Mobile Safari/537.36',
  'LINE iOS': 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Line/13.16.1',
}

const REAL_BROWSERS = {
  'Safari iOS': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
  'Chrome iOS': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/123.0.6312.52 Mobile/15E148 Safari/604.1',
  'Firefox iOS': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/124.0 Mobile/15E148 Safari/605.1.15',
  'Chrome Android': 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Mobile Safari/537.36',
  'Samsung Internet': 'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
  'Chrome desktop': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  'Firefox desktop': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
  'Safari macOS': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
}

describe('isInAppBrowser', () => {
  for (const [name, ua] of Object.entries(IN_APP)) {
    it(`detects ${name}`, () => {
      expect(isInAppBrowser(ua)).toBe(true)
    })
  }

  for (const [name, ua] of Object.entries(REAL_BROWSERS)) {
    it(`does not flag ${name}`, () => {
      expect(isInAppBrowser(ua)).toBe(false)
    })
  }

  it('handles missing user agent', () => {
    expect(isInAppBrowser('')).toBe(false)
    expect(isInAppBrowser(undefined)).toBe(false)
    expect(isInAppBrowser(null)).toBe(false)
  })

  // "Outline" must not match the LINE token ("Line/" requires the slash and
  // a word boundary).
  it('does not flag UA strings merely containing "line"', () => {
    expect(isInAppBrowser('Mozilla/5.0 (Windows NT 10.0) Outline/1.0 Chrome/123.0 Safari/537.36')).toBe(false)
  })
})
