import { beforeEach, describe, expect, it } from 'vitest'
import { isStale, observeBuild, resetBuildState } from './buildVersion'

describe('buildVersion', () => {
  beforeEach(() => resetBuildState())

  it('adopts the first build as baseline without going stale', () => {
    observeBuild('abc123')
    observeBuild('abc123')
    expect(isStale()).toBe(false)
  })

  it('marks the tab stale when a different build appears', () => {
    observeBuild('abc123')
    observeBuild('def456')
    expect(isStale()).toBe(true)
  })

  it('stays stale even if the old build shows up again (race)', () => {
    observeBuild('abc123')
    observeBuild('def456')
    observeBuild('abc123')
    expect(isStale()).toBe(true)
  })

  it('ignores missing headers', () => {
    observeBuild(undefined)
    observeBuild('abc123')
    observeBuild(null)
    expect(isStale()).toBe(false)
  })
})
