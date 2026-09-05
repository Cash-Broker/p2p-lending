/**
 * SEC-22: the investor-facing state of an account-deletion request.
 * `deletion` is the block from /api/user: { state, requested_at, confirmed_at, scheduled_for } | null.
 */
export function showCountdown(user) {
  return user?.deletion?.state === 'scheduled' && !!user.deletion.scheduled_for
}

export function daysLeft(scheduledFor, now = new Date()) {
  const target = scheduledFor instanceof Date ? scheduledFor : new Date(scheduledFor)
  if (Number.isNaN(target.getTime())) return 0
  const diff = Math.ceil((target.getTime() - now.getTime()) / 86_400_000)
  return Math.max(0, diff)
}

export function deletionLabel(deletion, now = new Date()) {
  if (!deletion) return ''
  if (deletion.state === 'awaiting_confirmation') {
    return `Заявката за закриване чака потвърждение по имейл (изпратена ${formatDate(deletion.requested_at)}).`
  }
  if (deletion.state === 'scheduled') {
    const days = daysLeft(deletion.scheduled_for, now)
    const when = days === 0 ? 'днес' : `след ${days} ${days === 1 ? 'ден' : 'дни'}`
    return `Акаунтът ви ще бъде закрит на ${formatDate(deletion.scheduled_for)} (${when}).`
  }
  return ''
}

function formatDate(value) {
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('bg-BG')
}
