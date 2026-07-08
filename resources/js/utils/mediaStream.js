/**
 * Classify the health of a MediaStream's tracks for the KYC selfie capture.
 *
 * Mobile browsers kill the camera in two different ways when the user
 * switches apps (e.g. leaves to photograph their ID card):
 *  - some END the track (readyState 'ended') — detectable and permanent;
 *  - iOS Safari usually MUTES it instead (readyState stays 'live',
 *    muted=true): the <video> freezes to a black frame and capturing would
 *    bake a BLACK selfie. Muted can recover after a play() nudge.
 *
 * @param {Array<{readyState: string, muted: boolean}>} tracks
 * @returns {'ended'|'muted'|'ok'}
 */
export function streamHealth(tracks) {
  if (!tracks || tracks.length === 0) return 'ended'
  if (tracks.some((t) => t.readyState === 'ended')) return 'ended'
  if (tracks.every((t) => t.muted)) return 'muted'
  return 'ok'
}
