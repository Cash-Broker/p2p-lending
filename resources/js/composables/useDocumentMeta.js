import { onMounted, onUnmounted, watch, isRef } from 'vue'

const SITE_NAME = 'Vamaasset'
const SITE_URL = 'https://vamaasset.bg'
const DEFAULT_TITLE = 'Vamaasset — P2P инвестиции в кредити'
const DEFAULT_DESCRIPTION = 'Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции. Прозрачност, диверсификация и контрол върху портфейла Ви.'
const DEFAULT_OG_IMAGE = `${SITE_URL}/logo/logo.png`

/**
 * Update <title> and the meta tags Vue routes care about (description,
 * canonical, OG title/description/url, Twitter title/description) when a
 * page mounts. Restores defaults on unmount so that returning to the
 * homepage doesn't keep a stale subpage title in the tab.
 *
 * Pre-launch: search engines are blocked at three layers (robots.txt,
 * X-Robots-Tag header, <meta name=robots> noindex), so these dynamic
 * updates are mostly cosmetic for the browser tab + social sharing
 * preview cards. Wiring them now means launch day is a robots.txt flip,
 * not a per-page coding sprint.
 *
 * Usage:
 *   import { useDocumentMeta } from '@/composables/useDocumentMeta'
 *   useDocumentMeta({
 *     title: 'Общи условия',
 *     description: 'Общите условия на Vamaasset, в сила от 4 май 2026 г.',
 *     path: '/legal/terms',
 *   })
 */
export function useDocumentMeta(options = {}) {
  const apply = () => {
    const opts = unwrap(options)

    const title = opts.title
      ? `${opts.title} — ${SITE_NAME}`
      : DEFAULT_TITLE
    const description = opts.description ?? DEFAULT_DESCRIPTION
    const path = opts.path ?? (typeof window !== 'undefined' ? window.location.pathname : '/')
    const url = `${SITE_URL}${path}`
    const ogImage = opts.ogImage ?? DEFAULT_OG_IMAGE

    if (typeof document === 'undefined') return

    document.title = title
    setMeta('name', 'description', description)
    setLink('canonical', url)

    setMeta('property', 'og:title', opts.title ? `${opts.title} — ${SITE_NAME}` : DEFAULT_TITLE)
    setMeta('property', 'og:description', description)
    setMeta('property', 'og:url', url)
    setMeta('property', 'og:image', ogImage)

    setMeta('name', 'twitter:title', opts.title ? `${opts.title} — ${SITE_NAME}` : DEFAULT_TITLE)
    setMeta('name', 'twitter:description', description)
    setMeta('name', 'twitter:image', ogImage)
  }

  const restore = () => {
    if (typeof document === 'undefined') return
    document.title = DEFAULT_TITLE
    setMeta('name', 'description', DEFAULT_DESCRIPTION)
    setLink('canonical', SITE_URL + '/')
    setMeta('property', 'og:title', DEFAULT_TITLE)
    setMeta('property', 'og:description', DEFAULT_DESCRIPTION)
    setMeta('property', 'og:url', SITE_URL + '/')
    setMeta('property', 'og:image', DEFAULT_OG_IMAGE)
    setMeta('name', 'twitter:title', DEFAULT_TITLE)
    setMeta('name', 'twitter:description', DEFAULT_DESCRIPTION)
    setMeta('name', 'twitter:image', DEFAULT_OG_IMAGE)
  }

  onMounted(apply)
  onUnmounted(restore)

  // If the caller passes refs (e.g. an i18n title), re-apply on change.
  if (
    isRef(options.title) ||
    isRef(options.description) ||
    isRef(options.path) ||
    isRef(options.ogImage)
  ) {
    watch(
      [
        toRefOr(options.title),
        toRefOr(options.description),
        toRefOr(options.path),
        toRefOr(options.ogImage),
      ],
      apply,
      { flush: 'post' },
    )
  }
}

// ── helpers ───────────────────────────────────────────────────────────────

function setMeta(attr, key, content) {
  if (content === undefined || content === null) return
  let el = document.head.querySelector(`meta[${attr}="${key}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute('content', String(content))
}

function setLink(rel, href) {
  let el = document.head.querySelector(`link[rel="${rel}"]`)
  if (!el) {
    el = document.createElement('link')
    el.setAttribute('rel', rel)
    document.head.appendChild(el)
  }
  el.setAttribute('href', href)
}

function unwrap(opts) {
  return {
    title:       isRef(opts.title)       ? opts.title.value       : opts.title,
    description: isRef(opts.description) ? opts.description.value : opts.description,
    path:        isRef(opts.path)        ? opts.path.value        : opts.path,
    ogImage:     isRef(opts.ogImage)     ? opts.ogImage.value     : opts.ogImage,
  }
}

function toRefOr(v) {
  return isRef(v) ? v : () => v
}
