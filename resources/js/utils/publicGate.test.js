import { describe, it, expect } from 'vitest'
import {
  publicPageState,
  loansPageRedirect,
  awaitsKycUpload,
  STATE_GUEST,
  STATE_ADMIN,
  STATE_PENDING,
  STATE_REJECTED,
  STATE_APPROVED,
} from './publicGate'

describe('publicPageState', () => {
  it('treats a missing user as a guest', () => {
    expect(publicPageState(null)).toBe(STATE_GUEST)
    expect(publicPageState(undefined)).toBe(STATE_GUEST)
  })

  it('recognises the admin regardless of KYC', () => {
    expect(publicPageState({ role: 'admin', kyc_status: 'pending' })).toBe(STATE_ADMIN)
    expect(publicPageState({ role: 'admin', kyc_status: 'approved' })).toBe(STATE_ADMIN)
  })

  it('opens the page only for an approved investor', () => {
    expect(publicPageState({ role: 'investor', kyc_status: 'approved' })).toBe(STATE_APPROVED)
  })

  it('keeps every pre-approval KYC state on the waiting screen', () => {
    for (const status of ['pending', 'submitted', 'in_review', null, undefined]) {
      expect(publicPageState({ role: 'investor', kyc_status: status })).toBe(STATE_PENDING)
    }
  })

  it('separates a rejected verification from one still in the queue', () => {
    expect(publicPageState({ role: 'investor', kyc_status: 'rejected' })).toBe(STATE_REJECTED)
  })
})

describe('loansPageRedirect', () => {
  it('sends an approved investor to their own positions', () => {
    expect(loansPageRedirect({ role: 'investor', kyc_status: 'approved' })).toBe('/portfolio')
  })

  it('moves nobody else — guest, admin and unapproved investor stay on the page', () => {
    expect(loansPageRedirect(null)).toBeNull()
    // The admin is a normal visitor on a public route (the global guard ejects
    // admins from meta.auth routes only) — no hard bounce into Filament.
    expect(loansPageRedirect({ role: 'admin', kyc_status: 'approved' })).toBeNull()
    expect(loansPageRedirect({ role: 'investor', kyc_status: 'submitted' })).toBeNull()
    expect(loansPageRedirect({ role: 'investor', kyc_status: 'rejected' })).toBeNull()
  })
})

describe('awaitsKycUpload', () => {
  it('is true for a fresh registration that has uploaded nothing', () => {
    expect(awaitsKycUpload({ kyc_status: 'pending' })).toBe(true)
    // Missing status is a fresh profile too, not an error.
    expect(awaitsKycUpload({})).toBe(true)
    expect(awaitsKycUpload(null)).toBe(true)
  })

  it('is false once the documents are in the review queue', () => {
    expect(awaitsKycUpload({ kyc_status: 'submitted' })).toBe(false)
    expect(awaitsKycUpload({ kyc_status: 'in_review' })).toBe(false)
    expect(awaitsKycUpload({ kyc_status: 'rejected' })).toBe(false)
  })
})
