// The landing header/footer are no longer homepage-only: /loans and
// /originators (Reni 2026-08-20) render them too. A bare `#faq` anchor there
// would just stick a hash on a page that has no such section, so section links
// resolve to the homepage + hash whenever we are somewhere else. HomePage.vue
// does the scrolling (deliberately not a global router scrollBehavior — see
// the note in router/index.js).

/** Height of the fixed landing header (h-16) plus breathing room. */
export const SECTION_SCROLL_OFFSET = 80

/**
 * router-link target for an in-page landing section.
 *
 * @param {string} id           section id without the `#`
 * @param {string} currentPath  route path we are rendering on
 */
export function sectionLink(id, currentPath) {
  const hash = `#${id}`

  return currentPath === '/' ? { hash } : { path: '/', hash }
}

/**
 * Absolute scroll position that puts a section below the fixed header.
 *
 * @param {number} elementTop    section top, relative to the viewport
 * @param {number} currentScroll current window.scrollY
 */
export function sectionScrollTop(elementTop, currentScroll, offset = SECTION_SCROLL_OFFSET) {
  return Math.max(0, elementTop + currentScroll - offset)
}
