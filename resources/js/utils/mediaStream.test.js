import { describe, expect, it } from 'vitest'
import { streamHealth } from './mediaStream'

const track = (readyState = 'live', muted = false) => ({ readyState, muted })

describe('streamHealth', () => {
  it('reports ok for a live unmuted track', () => {
    expect(streamHealth([track()])).toBe('ok')
  })

  it('reports ended when there are no tracks', () => {
    expect(streamHealth([])).toBe('ended')
    expect(streamHealth(null)).toBe('ended')
    expect(streamHealth(undefined)).toBe('ended')
  })

  it('reports ended when any track has ended', () => {
    expect(streamHealth([track('ended')])).toBe('ended')
    expect(streamHealth([track('live'), track('ended')])).toBe('ended')
  })

  // The iOS backgrounding case: readyState stays 'live' but the track is
  // muted — the video is frozen/black and capturing would produce a black
  // selfie. This MUST NOT be classified 'ok'.
  it('reports muted when all live tracks are muted (iOS backgrounding)', () => {
    expect(streamHealth([track('live', true)])).toBe('muted')
  })

  it('ended takes precedence over muted', () => {
    expect(streamHealth([track('ended', true)])).toBe('ended')
  })

  it('reports ok when at least one track is unmuted', () => {
    expect(streamHealth([track('live', true), track('live', false)])).toBe('ok')
  })
})
