import { describe, expect, it } from 'vitest'
import { KYC_MAX_FILE_BYTES, isHeicFile, validateKycFile } from './kycFile'

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

  // ── HEIC/HEIF — accepted so iOS hands over the ORIGINAL photo ──
  // (naming HEIC in accept prevents iOS's pick-time transcode, which produces
  // black/broken files on some devices; the SERVER converts HEIC to JPEG)

  it.each([
    ['HEIC by MIME', file('photo.heic', 'image/heic')],
    ['HEIF by MIME', file('photo.heif', 'image/heif')],
    ['HEIC by extension, empty MIME', file('IMG_0001.heic', '')],
    ['HEIF by extension, empty MIME', file('IMG_0001.heif', '')],
  ])('accepts %s (server converts to JPEG)', (_label, f) => {
    expect(validateKycFile(f)).toBeNull()
  })

  it('accepts a HEIC selfie too', () => {
    expect(validateKycFile(file('selfie.heic', 'image/heic'), { allowPdf: false })).toBeNull()
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

  it('applies the size limit to HEIC picks as well', () => {
    expect(validateKycFile(file('big.heic', 'image/heic', KYC_MAX_FILE_BYTES + 1))).toContain('10 MB')
  })
})

describe('isHeicFile', () => {
  it.each([
    ['by MIME', file('photo.heic', 'image/heic')],
    ['HEIF by MIME', file('photo.x', 'image/heif')],
    ['by extension with empty MIME', file('IMG_0001.HEIC', '')],
  ])('detects HEIC %s', (_label, f) => {
    expect(isHeicFile(f)).toBe(true)
  })

  it.each([
    ['JPEG', file('photo.jpg', 'image/jpeg')],
    ['extensionless empty-MIME pick', file('capture', '')],
  ])('does not flag %s', (_label, f) => {
    expect(isHeicFile(f)).toBe(false)
  })
})
