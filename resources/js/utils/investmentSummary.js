// Pure math for the portfolio investment-detail modal. Display-only sums over
// the per-investment schedule rows the API already returns (decimal strings) —
// integer-cent arithmetic so the shown totals are exact, never 0.30000000004.

const toCents = (v) => {
  const n = Math.round(parseFloat(v) * 100)
  return Number.isFinite(n) ? n : 0
}

const fromCents = (c) => (c / 100).toFixed(2)

/**
 * Aggregate a schedule ([{due_date, principal, interest, total, status}]) into
 * the figures the modal shows. Returns null for empty/missing schedules
 * (legacy positions and offer investments before loan activation).
 */
export function summarizeSchedule(schedule) {
  if (!Array.isArray(schedule) || schedule.length === 0) return null

  let totalCents = 0
  let interestCents = 0
  let receivedCents = 0
  let paidCount = 0

  let liveCount = 0

  for (const row of schedule) {
    // Вноска, отменена от предсрочно погасяване, няма да бъде плащана —
    // главницата вече е върната. Ако я броим в очакваното, прогресът лъже
    // надолу до безкрайност (2026-08-18).
    if (row.status === 'closed') continue

    liveCount += 1
    totalCents += toCents(row.total)
    interestCents += toCents(row.interest)
    if (row.status === 'paid') {
      receivedCents += toCents(row.total)
      paidCount += 1
    }
  }

  if (liveCount === 0) return null

  return {
    totalExpected: fromCents(totalCents),
    totalInterest: fromCents(interestCents),
    received: fromCents(receivedCents),
    paidCount,
    count: liveCount,
    // Amount-based, not row-count-based: honest for amortizing plans where
    // installments differ in size. Guard the /0 for a zero-sum schedule.
    progressPct: totalCents > 0 ? Math.round((receivedCents / totalCents) * 100) : 0,
  }
}

/**
 * First unpaid installment in due-date order, or null when everything is paid.
 * Late/default rows count as "next" too — they are what the investor is owed.
 * `closed` rows never will be: they were cancelled by an early repayment.
 */
export function nextUnpaidInstallment(schedule) {
  if (!Array.isArray(schedule)) return null
  const unpaid = schedule.filter((r) => r.status !== 'paid' && r.status !== 'closed')
  if (!unpaid.length) return null
  return unpaid.reduce((a, b) => (String(a.due_date) <= String(b.due_date) ? a : b))
}

/**
 * Parse a YYYY-MM-DD string as LOCAL midnight. new Date('YYYY-MM-DD') is UTC
 * midnight per spec — an off-by-one for viewers west of UTC. Returns null for
 * anything that isn't a date-only string.
 */
export function parseDateOnlyLocal(dateStr) {
  if (typeof dateStr !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return null
  const [y, m, d] = dateStr.split('-').map(Number)
  return new Date(y, m - 1, d)
}

/**
 * Whole calendar days from today to a YYYY-MM-DD date (negative = overdue).
 * Math.round absorbs DST hour shifts. Null for unparseable input.
 */
export function daysUntilDue(dateStr, nowMs = Date.now()) {
  const due = parseDateOnlyLocal(dateStr)
  if (!due) return null
  const now = new Date(nowMs)
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  return Math.round((due - today) / 86_400_000)
}

/**
 * BG relative-day label for a due date: «днес», «утре», «след N дни»,
 * «преди N дни».
 */
export function dueLabelBg(dateStr, nowMs = Date.now()) {
  const diffDays = daysUntilDue(dateStr, nowMs)
  if (diffDays === null) return null
  if (diffDays === 0) return 'днес'
  if (diffDays === 1) return 'утре'
  if (diffDays > 1) return `след ${diffDays} дни`
  return diffDays === -1 ? 'преди 1 ден' : `преди ${-diffDays} дни`
}
