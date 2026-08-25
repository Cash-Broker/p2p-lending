import { describe, it, expect } from 'vitest'
import { needsPhonePrompt } from './phoneGate'

const investor = (overrides = {}) => ({
  role: 'investor',
  email_verified_at: '2026-08-25T10:00:00.000000Z',
  phone: null,
  ...overrides,
})

describe('needsPhonePrompt', () => {
  it('never prompts a guest', () => {
    expect(needsPhonePrompt(null)).toBe(false)
    expect(needsPhonePrompt(undefined)).toBe(false)
  })

  it('never prompts an admin — the requirement targets investor accounts', () => {
    expect(needsPhonePrompt({ role: 'admin', email_verified_at: '2026-01-01', phone: null })).toBe(false)
  })

  it('waits for email verification — the save endpoint 403s before it', () => {
    expect(needsPhonePrompt(investor({ email_verified_at: null }))).toBe(false)
  })

  it('prompts a verified investor whose phone is missing', () => {
    expect(needsPhonePrompt(investor({ phone: null }))).toBe(true)
    expect(needsPhonePrompt(investor({ phone: undefined }))).toBe(true)
  })

  it('treats empty and whitespace-only phones as missing', () => {
    expect(needsPhonePrompt(investor({ phone: '' }))).toBe(true)
    expect(needsPhonePrompt(investor({ phone: '   ' }))).toBe(true)
  })

  it('stays silent once a phone is on file', () => {
    expect(needsPhonePrompt(investor({ phone: '+359 88 123 4567' }))).toBe(false)
    expect(needsPhonePrompt(investor({ phone: '0888123456' }))).toBe(false)
  })

  it('applies to legal-entity accounts exactly like individuals', () => {
    expect(needsPhonePrompt(investor({ account_type: 'legal_entity', phone: null }))).toBe(true)
    expect(needsPhonePrompt(investor({ account_type: 'legal_entity', phone: '+359 2 981 2345' }))).toBe(false)
  })
})
