/**
 * Pure, unit-testable pieces of the KYC upload pipeline.
 *
 * The pipeline (see imageFile.js for the DOM half): every picked photo is
 * DECODED in the browser and re-encoded to a clean JPEG before upload.
 * Motivation: iOS Safari's on-the-fly HEIC transcode hands sites broken or
 * fully BLACK files for some gallery photos (iCloud-offloaded originals are
 * the usual trigger) — those used to reach the server silently. Re-encoding
 * guarantees whatever we upload is decodable, and lets us detect and refuse
 * unreadable or all-black picks with a clear Bulgarian message instead.
 */

// Pre-decode cap — protects the canvas pipeline from absurd inputs. The
// re-encoded JPEG we actually upload is far smaller (max 2048px edge).
export const KYC_MAX_SOURCE_BYTES = 25 * 1024 * 1024

// PDFs skip re-encoding and go up as-is, so they must respect the server's
// max:10240 KB rule directly.
export const KYC_MAX_PDF_BYTES = 10 * 1024 * 1024

export function isPdfFile(file) {
  const type = (file.type || '').toLowerCase()
  return type === 'application/pdf' || (file.name || '').toLowerCase().endsWith('.pdf')
}

export function isHeicFile(file) {
  const type = (file.type || '').toLowerCase()
  const ext = ((file.name || '').split('.').pop() || '').toLowerCase()
  return /hei[cf]/.test(type) || ext === 'heic' || ext === 'heif'
}

/**
 * Classify a raw pick before any (expensive) decoding.
 *
 * @param {{ name: string, type: string, size: number }} file
 * @param {{ allowPdf?: boolean }} opts — the selfie is a photo, never a PDF
 * @returns {{ kind: 'pdf' } | { kind: 'image', heic: boolean } | { kind: 'rejected', error: string }}
 */
export function classifyKycPick(file, { allowPdf = true } = {}) {
  if (isPdfFile(file)) {
    if (!allowPdf) {
      return { kind: 'rejected', error: 'Селфито трябва да е снимка, не PDF.' }
    }
    if (file.size > KYC_MAX_PDF_BYTES) {
      return { kind: 'rejected', error: 'PDF файлът не може да е по-голям от 10 MB.' }
    }
    return { kind: 'pdf' }
  }

  if (file.size > KYC_MAX_SOURCE_BYTES) {
    return { kind: 'rejected', error: 'Файлът не може да е по-голям от 25 MB.' }
  }

  return { kind: 'image', heic: isHeicFile(file) }
}

/**
 * Detect an (almost) entirely black frame — the signature of iOS Safari's
 * broken HEIC transcode. Operates on RGBA pixel data from a small probe
 * canvas; real document/face photos never come close to the threshold.
 *
 * @param {Uint8ClampedArray|number[]} data RGBA pixel data
 */
export function isMostlyBlack(data, { luminanceThreshold = 18, blackFraction = 0.985 } = {}) {
  const total = Math.floor(data.length / 4)
  if (total === 0) return true

  let black = 0
  for (let i = 0; i < total * 4; i += 4) {
    const luminance = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2]
    if (luminance < luminanceThreshold) black++
  }
  return black / total >= blackFraction
}

/**
 * Bulgarian message for a failed image-processing attempt.
 *
 * @param {'decode'|'black'|'encode'} code
 * @param {boolean} heic whether the source pick looked like HEIC/HEIF
 */
export function imageErrorMessage(code, heic = false) {
  if (code === 'black') {
    return 'Снимката се разчита като изцяло черна — вероятно е повредена при избора от галерията. Отворете я в Галерията, уверете се, че се вижда, и я изберете отново (или направете нова снимка).'
  }
  if (code === 'encode') {
    // The image DID decode — re-picking it as JPEG won't help; size might.
    return 'Грешка при обработката на снимката. Опитайте отново или използвайте по-малка снимка.'
  }
  if (heic) {
    return 'Тази HEIC снимка не може да бъде прочетена от браузъра. Изберете я като JPEG/PNG или направете нова снимка.'
  }
  return 'Снимката не може да бъде прочетена. Ако е в iCloud, отворете я веднъж в Галерията (за да се изтегли) и опитайте пак, или направете нова снимка.'
}
