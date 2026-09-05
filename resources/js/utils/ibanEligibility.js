/**
 * SEC-01: which saved IBANs may receive a withdrawal right now, and why not.
 *
 * The server is authoritative (WithdrawalService re-checks under lock); this
 * only decides how the option renders. Missing fields (an older API shape)
 * fail OPEN on the client — the server will still refuse.
 */
export function ibanOptionState(iban, now = new Date()) {
  if (!iban || typeof iban !== 'object') return { selectable: false, suffix: '' }
  if (iban.confirmed === undefined) return { selectable: true, suffix: '' }

  if (!iban.confirmed) {
    return { selectable: false, suffix: iban.confirmation_expired ? ' — линкът изтече' : ' — непотвърден' }
  }

  if (iban.withdrawable_now === true) return { selectable: true, suffix: '' }

  const from = iban.withdrawable_from ? new Date(iban.withdrawable_from) : null
  if (from && !Number.isNaN(from.getTime()) && from > now) {
    return { selectable: false, suffix: ` — теглене от ${formatDateTimeBg(from)}` }
  }

  return { selectable: true, suffix: '' }
}

export function formatDateTimeBg(date) {
  return `${date.toLocaleDateString('bg-BG')} ${date.toLocaleTimeString('bg-BG', { hour: '2-digit', minute: '2-digit' })}`
}
