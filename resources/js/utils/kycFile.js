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

const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf']
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf']

// Common formats the server will definitely reject — safe to block early.
const REJECTED_TYPES = ['image/gif', 'image/bmp', 'image/tiff', 'image/svg+xml', 'image/avif']
const REJECTED_EXTENSIONS = ['gif', 'bmp', 'tif', 'tiff', 'svg', 'avif']

/**
 * @param {{ name: string, type: string, size: number }} file
 * @param {{ allowPdf?: boolean }} opts — the selfie is a photo, never a PDF
 * @returns {string|null} Bulgarian error message, or null when the file may be submitted.
 */
export function validateKycFile(file, { allowPdf = true } = {}) {
  const name = file.name || ''
  const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
  const type = (file.type || '').toLowerCase()

  if (/hei[cf]/.test(type) || ext === 'heic' || ext === 'heif') {
    return 'Снимки във формат HEIC/HEIF не се поддържат. Направете нова снимка директно от тук или изберете снимка във формат JPEG/PNG.'
  }

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
