import { describe, it, expect } from 'vitest'
import { urlBase64ToUint8Array } from './push'

describe('urlBase64ToUint8Array', () => {
  it('decodes a base64url VAPID key to raw bytes', () => {
    // 'Hello' → 'SGVsbG8=' standard, 'SGVsbG8' unpadded base64url.
    expect(Array.from(urlBase64ToUint8Array('SGVsbG8'))).toEqual([72, 101, 108, 108, 111])
  })

  it('restores stripped padding', () => {
    // 'Hi' → 'SGk=' — one '=' must be added back before atob.
    expect(Array.from(urlBase64ToUint8Array('SGk'))).toEqual([72, 105])
  })

  it('translates the URL-safe alphabet (- → +, _ → /)', () => {
    // Bytes 0xFB 0xFF decode from '+/8=' in standard base64 → '-_8' url-safe.
    expect(Array.from(urlBase64ToUint8Array('-_8'))).toEqual([251, 255])
  })

  it('produces the 65-byte P-256 public key a real VAPID key encodes', () => {
    // Shape check on a real generated key: uncompressed EC point, 0x04 prefix.
    const key = 'BAzxbu_hYConVQMLQY0Bp8Xf4pM6Y3Bkk8Fh1XkzY5oGVMxLnLxrPqCQR6DGBSl3nQpEg4Wsg1uDy2Y3xkH5Vqk'
    const bytes = urlBase64ToUint8Array(key)
    expect(bytes.length).toBe(65)
    expect(bytes[0]).toBe(0x04)
  })
})
