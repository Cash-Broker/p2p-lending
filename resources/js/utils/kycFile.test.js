import { describe, expect, it } from 'vitest'
import {
  KYC_MAX_PDF_BYTES,
  KYC_MAX_SOURCE_BYTES,
  classifyKycPick,
  imageErrorMessage,
  isMostlyBlack,
} from './kycFile'

const file = (name, type, size = 1024) => ({ name, type, size })

describe('classifyKycPick', () => {
  // ── Images: everything decodable goes to the re-encode pipeline ──

  it.each([
    ['JPEG', file('id.jpg', 'image/jpeg')],
    ['PNG', file('id.png', 'image/png')],
    ['nonstandard image/jpg MIME', file('photo.jpg', 'image/jpg')],
    ['empty MIME (WebView pickers)', file('photo.jpg', '')],
    ['extensionless content-URI name', file('capture', '')],
    ['GIF (canvas will rasterize it)', file('scan.gif', 'image/gif')],
  ])('routes %s to the image pipeline', (_label, f) => {
    expect(classifyKycPick(f)).toEqual({ kind: 'image', heic: false })
  })

  it.each([
    ['HEIC by MIME', file('photo.heic', 'image/heic')],
    ['HEIF by MIME', file('photo.heif', 'image/heif')],
    ['HEIC by extension, empty MIME', file('IMG_0001.heic', '')],
  ])('flags %s as heic for the decode-failure message', (_label, f) => {
    expect(classifyKycPick(f)).toEqual({ kind: 'image', heic: true })
  })

  it('does not flag image/jpeg as heic (the hei[cf] regex must not overmatch)', () => {
    expect(classifyKycPick(file('photo.jpeg', 'image/jpeg')).heic).toBe(false)
  })

  it('rejects an oversized image before decoding', () => {
    const result = classifyKycPick(file('huge.jpg', 'image/jpeg', KYC_MAX_SOURCE_BYTES + 1))
    expect(result.kind).toBe('rejected')
    expect(result.error).toContain('25 MB')
  })

  it('accepts an image at exactly the pre-decode boundary', () => {
    expect(classifyKycPick(file('big.jpg', 'image/jpeg', KYC_MAX_SOURCE_BYTES)).kind).toBe('image')
  })

  // ── PDFs: pass through unchanged, so the server's 10 MB rule applies ──

  it('passes a PDF through for document uploads', () => {
    expect(classifyKycPick(file('scan.pdf', 'application/pdf'))).toEqual({ kind: 'pdf' })
  })

  it('detects PDFs by extension when the MIME type is empty', () => {
    expect(classifyKycPick(file('scan.pdf', ''))).toEqual({ kind: 'pdf' })
  })

  it('rejects a PDF over the server limit', () => {
    const result = classifyKycPick(file('scan.pdf', 'application/pdf', KYC_MAX_PDF_BYTES + 1))
    expect(result.kind).toBe('rejected')
    expect(result.error).toContain('10 MB')
  })

  it('rejects a PDF selfie with a photo-specific message', () => {
    const result = classifyKycPick(file('selfie.pdf', 'application/pdf'), { allowPdf: false })
    expect(result.kind).toBe('rejected')
    expect(result.error).toContain('не PDF')
  })
})

describe('isMostlyBlack', () => {
  const rgba = (pixels) => new Uint8ClampedArray(pixels.flatMap(([r, g, b]) => [r, g, b, 255]))
  const solid = (r, g, b, count) => rgba(Array.from({ length: count }, () => [r, g, b]))

  it('detects a fully black frame (broken iOS HEIC transcode signature)', () => {
    expect(isMostlyBlack(solid(0, 0, 0, 64))).toBe(true)
  })

  it('detects a near-black frame below the luminance threshold', () => {
    expect(isMostlyBlack(solid(10, 10, 10, 64))).toBe(true)
  })

  it('does not flag a dark but real photo', () => {
    expect(isMostlyBlack(solid(40, 40, 40, 64))).toBe(false)
  })

  it('does not flag a frame with meaningful bright content', () => {
    const mostlyBlackButReal = new Uint8ClampedArray([
      ...solid(0, 0, 0, 60),
      ...solid(255, 255, 255, 4), // 6% bright pixels — a real (terrible) photo
    ])
    expect(isMostlyBlack(mostlyBlackButReal)).toBe(false)
  })

  it('treats an empty frame as black', () => {
    expect(isMostlyBlack(new Uint8ClampedArray([]))).toBe(true)
  })
})

describe('imageErrorMessage', () => {
  it('explains the all-black case with recovery steps', () => {
    expect(imageErrorMessage('black')).toContain('черна')
  })

  it('names HEIC when the unreadable pick was HEIC', () => {
    expect(imageErrorMessage('decode', true)).toContain('HEIC')
  })

  it('suggests the iCloud recovery for generic decode failures', () => {
    expect(imageErrorMessage('decode')).toContain('iCloud')
  })

  it('does not blame the format for encode failures (image already decoded fine)', () => {
    expect(imageErrorMessage('encode', true)).not.toContain('HEIC')
    expect(imageErrorMessage('encode', true)).toContain('обработката')
  })
})
