import { describe, expect, it } from 'vitest'
import { KYC_MAX_FILE_BYTES, validateKycFile } from './kycFile'

const file = (name, type, size = 1024) => ({ name, type, size })

describe('validateKycFile', () => {
  // ── Accepted formats ──

  it.each([
    ['JPEG', file('id-front.jpg', 'image/jpeg')],
    ['PNG', file('id-front.png', 'image/png')],
    ['WEBP', file('id-front.webp', 'image/webp')],
    ['PDF', file('scan.pdf', 'application/pdf')],
    ['uppercase extension', file('ID-FRONT.JPG', 'image/jpeg')],
  ])('accepts %s', (_label, f) => {
    expect(validateKycFile(f)).toBeNull()
  })

  // ── Permissive pass-through (server content-sniffing is the authority) ──

  it('accepts the nonstandard image/jpg MIME some Android pickers report', () => {
    expect(validateKycFile(file('photo.jpg', 'image/jpg'))).toBeNull()
  })

  it('accepts an empty MIME type when the extension is allowed (WebView pickers)', () => {
    expect(validateKycFile(file('photo.jpg', ''))).toBeNull()
  })

  it('passes through extensionless content-URI names with empty MIME (server decides)', () => {
    expect(validateKycFile(file('capture', ''))).toBeNull()
  })

  it('passes through unknown type + unknown extension combos (server decides)', () => {
    expect(validateKycFile(file('photo.xyz', 'application/x-something'))).toBeNull()
  })

  // ── HEIC/HEIF — the dominant real-world mobile rejection ──

  it.each([
    ['HEIC by MIME', file('photo.heic', 'image/heic')],
    ['HEIF by MIME', file('photo.heif', 'image/heif')],
    ['HEIC sequence MIME', file('photo.heic', 'image/heic-sequence')],
    ['HEIC by extension, empty MIME', file('IMG_0001.heic', '')],
    ['HEIF by extension, empty MIME', file('IMG_0001.heif', '')],
  ])('rejects %s with the HEIC message', (_label, f) => {
    expect(validateKycFile(f)).toContain('HEIC')
  })

  it('does not misread image/jpeg as HEIC (the hei[cf] regex must not overmatch)', () => {
    expect(validateKycFile(file('photo.jpeg', 'image/jpeg'))).toBeNull()
  })

  // ── Known-bad formats blocked early ──

  it.each([
    ['GIF', file('anim.gif', 'image/gif')],
    ['BMP', file('scan.bmp', 'image/bmp')],
    ['SVG', file('img.svg', 'image/svg+xml')],
    ['AVIF', file('photo.avif', 'image/avif')],
    ['GIF by extension only', file('anim.gif', '')],
  ])('rejects %s with the format message', (_label, f) => {
    expect(validateKycFile(f)).toContain('JPG, PNG, WEBP или PDF')
  })

  // ── Selfie mode (allowPdf: false) ──

  it('rejects a PDF selfie with a photo-specific message', () => {
    expect(validateKycFile(file('selfie.pdf', 'application/pdf'), { allowPdf: false })).toContain('не PDF')
  })

  it('rejects a PDF selfie detected by extension only', () => {
    expect(validateKycFile(file('selfie.pdf', ''), { allowPdf: false })).toContain('не PDF')
  })

  it('accepts a JPEG selfie in selfie mode', () => {
    expect(validateKycFile(file('selfie.jpg', 'image/jpeg'), { allowPdf: false })).toBeNull()
  })

  // ── Size limit (mirrors server max:10240 KB) ──

  it('accepts a file at exactly the 10 MB boundary', () => {
    expect(validateKycFile(file('id.jpg', 'image/jpeg', KYC_MAX_FILE_BYTES))).toBeNull()
  })

  it('rejects a file one byte over the 10 MB boundary', () => {
    expect(validateKycFile(file('id.jpg', 'image/jpeg', KYC_MAX_FILE_BYTES + 1))).toContain('10 MB')
  })

  it('reports HEIC before size for an oversized HEIC (format is the actionable problem)', () => {
    expect(validateKycFile(file('big.heic', 'image/heic', KYC_MAX_FILE_BYTES + 1))).toContain('HEIC')
  })
})
