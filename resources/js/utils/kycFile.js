/**
 * Client-side pre-check for KYC document uploads.
 *
 * Mirrors the server rules (mimes:jpg,jpeg,png,webp,pdf + max:10240) so the
 * common mobile failures — HEIC photos from iPhones/Samsungs and oversized
 * camera shots — are caught instantly, in Bulgarian, before burning a request
 * (KYC submission is rate-limited).
 *
 * The check is deliberately PERMISSIVE: it only rejects on positive evidence
 * of a wrong format. Pickers in the wild report nonstandard MIME types
 * (image/jpg), empty types, or extensionless content-URI names — all of those
 * pass through to the server, whose content-sniffing validation is the
 * authority. A false rejection here would make KYC unsubmittable on that
 * device; a false accept just costs one request.
 */

export const KYC_MAX_FILE_BYTES = 10 * 1024 * 1024

// HEIC/HEIF are ACCEPTED: naming them explicitly in the accept list makes iOS
// hand over the ORIGINAL photo instead of transcoding on the fly — that
// transcoder produces black/broken files on some devices (observed in prod).
// The server converts HEIC to JPEG (KycImageNormalizer).
const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf']
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf']

// Common formats the server will definitely reject — safe to block early.
const REJECTED_TYPES = ['image/gif', 'image/bmp', 'image/tiff', 'image/svg+xml', 'image/avif']
const REJECTED_EXTENSIONS = ['gif', 'bmp', 'tif', 'tiff', 'svg', 'avif']

/**
 * HEIC picks upload fine (the server converts them) but many browsers —
 * including some iOS Safari versions with blob URLs — render them black or
 * not at all, so the UI shows a "file accepted" note instead of a preview.
 */
export function isHeicFile(file) {
  const ext = ((file.name || '').split('.').pop() || '').toLowerCase()
  return /hei[cf]/.test((file.type || '').toLowerCase()) || ext === 'heic' || ext === 'heif'
}

/**
 * @param {{ name: string, type: string, size: number }} file
 * @param {{ allowPdf?: boolean }} opts — the selfie is a photo, never a PDF
 * @returns {string|null} Bulgarian error message, or null when the file may be submitted.
 */
export function validateKycFile(file, { allowPdf = true } = {}) {
  const name = file.name || ''
  const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
  const type = (file.type || '').toLowerCase()

  if (!allowPdf && (type === 'application/pdf' || ext === 'pdf')) {
    return 'Селфито трябва да е снимка (JPG, PNG или WEBP), не PDF.'
  }

  const typeOk = ALLOWED_TYPES.includes(type)
  const extOk = ALLOWED_EXTENSIONS.includes(ext)
  const knownBad = REJECTED_TYPES.includes(type) || REJECTED_EXTENSIONS.includes(ext)
  if (!typeOk && !extOk && knownBad) {
    return 'Файлът трябва да е JPG, PNG, WEBP или PDF.'
  }

  if (file.size > KYC_MAX_FILE_BYTES) {
    return 'Файлът не може да е по-голям от 10 MB.'
  }

  return null
}
