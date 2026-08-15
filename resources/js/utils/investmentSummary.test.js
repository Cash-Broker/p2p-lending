import { describe, it, expect } from 'vitest'
import { summarizeSchedule, nextUnpaidInstallment, dueLabelBg, daysUntilDue, parseDateOnlyLocal } from './investmentSummary'

const row = (due_date, principal, interest, status = 'pending') => ({
  due_date,
  principal,
  interest,
  total: (parseFloat(principal) + parseFloat(interest)).toFixed(2),
  status,
})

describe('summarizeSchedule', () => {
  it('returns null for empty or missing schedules', () => {
    expect(summarizeSchedule(null)).toBeNull()
    expect(summarizeSchedule(undefined)).toBeNull()
    expect(summarizeSchedule([])).toBeNull()
  })

  it('sums totals and interest exactly (cent math, no float drift)', () => {
    // 0.10 + 0.20 in floats is 0.30000000000000004 — the classic trap.
    const s = summarizeSchedule([
      row('2026-09-01', '0.00', '0.10', 'paid'),
      row('2026-10-01', '0.00', '0.20'),
    ])
    expect(s.totalExpected).toBe('0.30')
    expect(s.totalInterest).toBe('0.30')
    expect(s.received).toBe('0.10')
  })

  it('aggregates a mixed paid/pending schedule', () => {
    const s = summarizeSchedule([
      row('2026-09-01', '16.00', '2.00', 'paid'),
      row('2026-10-01', '16.20', '1.80', 'paid'),
      row('2026-11-01', '16.40', '1.60', 'late'),
      row('2026-12-01', '151.40', '1.40'),
    ])
    expect(s.totalExpected).toBe('206.80')
    expect(s.totalInterest).toBe('6.80')
    expect(s.received).toBe('36.00')
    expect(s.paidCount).toBe(2)
    expect(s.count).toBe(4)
    expect(s.progressPct).toBe(Math.round((3600 / 20680) * 100))
  })

  it('is 100% when everything is paid and 0% when nothing is', () => {
    const paid = summarizeSchedule([row('2026-09-01', '50.00', '1.00', 'paid')])
    expect(paid.progressPct).toBe(100)
    const none = summarizeSchedule([row('2026-09-01', '50.00', '1.00')])
    expect(none.progressPct).toBe(0)
  })

  it('survives malformed amounts without NaN', () => {
    const s = summarizeSchedule([{ due_date: '2026-09-01', total: null, interest: undefined, status: 'paid' }])
    expect(s.totalExpected).toBe('0.00')
    expect(s.received).toBe('0.00')
    expect(s.progressPct).toBe(0)
  })
})

describe('nextUnpaidInstallment', () => {
  it('returns the earliest unpaid row regardless of input order', () => {
    const later = row('2026-12-01', '10.00', '1.00')
    const earlier = row('2026-10-01', '10.00', '1.00', 'late')
    expect(nextUnpaidInstallment([later, earlier])).toBe(earlier)
  })

  it('skips paid rows', () => {
    const paid = row('2026-09-01', '10.00', '1.00', 'paid')
    const pending = row('2026-10-01', '10.00', '1.00')
    expect(nextUnpaidInstallment([paid, pending])).toBe(pending)
  })

  it('returns null when all rows are paid or schedule is missing', () => {
    expect(nextUnpaidInstallment([row('2026-09-01', '10.00', '1.00', 'paid')])).toBeNull()
    expect(nextUnpaidInstallment(null)).toBeNull()
    expect(nextUnpaidInstallment([])).toBeNull()
  })
})

describe('dueLabelBg', () => {
  // Fixed "now": 2026-08-15 21:30 local — evening on purpose, so a UTC-parsed
  // due date would already be "tomorrow" and betray an off-by-one.
  const now = new Date(2026, 7, 15, 21, 30).getTime()

  it('labels today / tomorrow / future / past', () => {
    expect(dueLabelBg('2026-08-15', now)).toBe('днес')
    expect(dueLabelBg('2026-08-16', now)).toBe('утре')
    expect(dueLabelBg('2026-09-01', now)).toBe('след 17 дни')
    expect(dueLabelBg('2026-08-14', now)).toBe('преди 1 ден')
    expect(dueLabelBg('2026-08-10', now)).toBe('преди 5 дни')
  })

  it('parses the date in local time (no UTC off-by-one in the evening)', () => {
    // If parsed as UTC midnight, 2026-08-16 would land on the 15th evening
    // in a UTC+3 timezone and read «днес» — must stay «утре».
    expect(dueLabelBg('2026-08-16', now)).toBe('утре')
  })

  it('crosses DST boundaries without drift (October rollback)', () => {
    const beforeDst = new Date(2026, 9, 20, 12, 0).getTime()
    expect(dueLabelBg('2026-10-27', beforeDst)).toBe('след 7 дни')
  })

  it('returns null for garbage', () => {
    expect(dueLabelBg(null, now)).toBeNull()
    expect(dueLabelBg('not-a-date', now)).toBeNull()
    expect(dueLabelBg('', now)).toBeNull()
  })
})

describe('daysUntilDue', () => {
  const now = new Date(2026, 7, 15, 21, 30).getTime()

  it('is negative for overdue, zero today, positive ahead', () => {
    expect(daysUntilDue('2026-08-10', now)).toBe(-5)
    expect(daysUntilDue('2026-08-15', now)).toBe(0)
    expect(daysUntilDue('2026-08-28', now)).toBe(13)
  })

  it('returns null for non-date input', () => {
    expect(daysUntilDue('2026-08-15T00:00:00Z', now)).toBeNull()
    expect(daysUntilDue(undefined, now)).toBeNull()
  })
})

describe('parseDateOnlyLocal', () => {
  it('parses YYYY-MM-DD as local midnight', () => {
    const d = parseDateOnlyLocal('2026-08-16')
    expect(d.getFullYear()).toBe(2026)
    expect(d.getMonth()).toBe(7)
    expect(d.getDate()).toBe(16)
    expect(d.getHours()).toBe(0)
  })

  it('rejects datetimes and garbage', () => {
    expect(parseDateOnlyLocal('2026-08-16T10:00:00Z')).toBeNull()
    expect(parseDateOnlyLocal(12345)).toBeNull()
    expect(parseDateOnlyLocal(null)).toBeNull()
  })
})
