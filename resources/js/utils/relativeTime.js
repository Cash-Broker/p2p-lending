// Compact Bulgarian relative-time formatting for social-proof lines
// («последна инвестиция преди 8 мин»).

export function formatRelativeBg(iso, nowMs = Date.now()) {
  const then = new Date(iso).getTime()
  if (!Number.isFinite(then)) return null

  const diffMin = Math.floor(Math.max(0, nowMs - then) / 60000)
  if (diffMin < 1) return 'преди по-малко от минута'
  if (diffMin < 60) return `преди ${diffMin} мин`

  const diffH = Math.floor(diffMin / 60)
  if (diffH < 24) return `преди ${diffH} ч`

  const diffD = Math.floor(diffH / 24)
  return diffD === 1 ? 'преди 1 ден' : `преди ${diffD} дни`
}
