import { describe, it, expect } from 'vitest'
import { sectionLink, sectionScrollTop, SECTION_SCROLL_OFFSET } from './landingNav'

describe('sectionLink', () => {
  it('stays in place on the homepage', () => {
    expect(sectionLink('faq', '/')).toEqual({ hash: '#faq' })
  })

  it('routes back to the homepage from a landing subpage', () => {
    expect(sectionLink('how-it-works', '/loans')).toEqual({ path: '/', hash: '#how-it-works' })
    expect(sectionLink('advantages', '/originators')).toEqual({ path: '/', hash: '#advantages' })
  })
})

describe('sectionScrollTop', () => {
  it('lands the section below the fixed header', () => {
    // Section 1000px down the viewport, page not scrolled yet.
    expect(sectionScrollTop(1000, 0)).toBe(1000 - SECTION_SCROLL_OFFSET)
  })

  it('adds the current scroll offset — getBoundingClientRect is viewport-relative', () => {
    expect(sectionScrollTop(200, 1500)).toBe(1620)
  })

  it('never returns a negative position for a section already above the fold', () => {
    expect(sectionScrollTop(-500, 100)).toBe(0)
  })
})
