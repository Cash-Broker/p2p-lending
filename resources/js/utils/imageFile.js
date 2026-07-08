import { isMostlyBlack } from './kycFile'

/**
 * DOM half of the KYC upload pipeline: decode a picked photo and re-encode
 * it to a clean, downscaled JPEG.
 *
 * Why not upload the original? iOS Safari's on-the-fly HEIC transcode
 * produces broken or fully black files for some gallery picks, and Android
 * "high efficiency" HEIF photos aren't decodable server-side. Re-encoding in
 * the browser (Safari decodes HEIC natively) means we upload only JPEGs we
 * KNOW render, and dramatically shrinks multi-MB camera shots.
 *
 * The <img> + decode() path is used (not createImageBitmap) because it
 * applies EXIF orientation consistently across browsers — ID documents must
 * not arrive sideways.
 */

const MAX_EDGE = 2048 // plenty for an ID document or a face
const JPEG_QUALITY = 0.85
const PROBE_SIZE = 32

/**
 * @param {File} file a picked image file
 * @returns {Promise<{ file: File } | { error: 'decode'|'black'|'encode' }>}
 */
export async function reencodeImageFile(file) {
  let url = null
  try {
    url = URL.createObjectURL(file)
    const img = new Image()
    img.src = url
    try {
      await img.decode() // throws for unreadable/corrupt sources
    } catch {
      // Chromium rejects decode() for very high-megapixel images (100MP+
      // Samsung camera modes) even though they are valid and drawImage
      // handles them fine (crbug 40261318) — fall back to the load event
      // before giving up.
      await new Promise((resolve, reject) => {
        if (img.complete) {
          img.naturalWidth ? resolve() : reject(new Error('unreadable'))
          return
        }
        img.onload = () => resolve()
        img.onerror = () => reject(new Error('unreadable'))
      })
    }

    const w = img.naturalWidth
    const h = img.naturalHeight
    if (!w || !h) return { error: 'decode' }

    const scale = Math.min(1, MAX_EDGE / Math.max(w, h))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(w * scale))
    canvas.height = Math.max(1, Math.round(h * scale))
    const ctx = canvas.getContext('2d')
    // White backing: JPEG has no alpha — without this, transparent areas of
    // PNG/WebP sources composite to BLACK (and would also trip the black
    // probe below for mostly-transparent scans).
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)

    // Small probe canvas — enough to recognise the all-black signature of a
    // broken iOS HEIC transcode without scanning megapixels.
    const probe = document.createElement('canvas')
    probe.width = PROBE_SIZE
    probe.height = PROBE_SIZE
    const probeCtx = probe.getContext('2d')
    probeCtx.drawImage(canvas, 0, 0, PROBE_SIZE, PROBE_SIZE)
    if (isMostlyBlack(probeCtx.getImageData(0, 0, PROBE_SIZE, PROBE_SIZE).data)) {
      return { error: 'black' }
    }

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY))
    if (!blob) return { error: 'encode' }

    const stem = (file.name || 'photo').replace(/\.[^.]+$/, '') || 'photo'
    return { file: new File([blob], `${stem}.jpg`, { type: 'image/jpeg' }) }
  } catch {
    return { error: 'decode' }
  } finally {
    if (url) URL.revokeObjectURL(url)
  }
}
