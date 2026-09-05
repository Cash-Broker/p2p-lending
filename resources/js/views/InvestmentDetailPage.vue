<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../api/axios'
import FreeCapacityBadge from '../components/FreeCapacityBadge.vue'
import { formatRelativeBg } from '../utils/relativeTime'
import { canInvest } from '../utils/investGate'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loan = ref(null)
const loading = ref(true)
const error = ref(null)

// Loan-event timeline — fetched lazily after the loan loads. Empty array means
// either no events OR the user has no investment in this loan (API returns
// 403; we silently treat as "no timeline available" so non-investors can still
// view the loan card).
const events = ref([])
const eventsLoading = ref(false)

// «Вашата инвестиция» (Reni 2026-08-17): the viewer's own positions in THIS
// loan — chosen plan, snapshotted rate, personal schedule. The loan header
// shows what is OFFERED; this card shows what SHE picked. Empty for
// non-investors — the card simply doesn't render.
const myInvestments = ref([])

const myTotalInvested = computed(() => {
  const cents = myInvestments.value
    .reduce((sum, inv) => sum + Math.round(parseFloat(inv.amount || 0) * 100), 0)
  return (cents / 100).toFixed(2)
})

// Invest form
const investAmount = ref('')
const investLoading = ref(false)
const investError = ref(null)
const investSuccess = ref(false)
const showConfirmModal = ref(false)
// Id of the just-created investment — the success panel links its
// concluded contract PDF.
const lastInvestmentId = ref(null)

// 3-offer feature — per-offer profit projection for the entered amount, so the
// client compares what they'd earn under each structure and picks one. The
// capacity is a single shared pool (one funded_amount); the offer only sets
// THIS investor's rate + payout structure, not a separate per-offer limit.
const offerQuotes = ref([])
const selectedOfferId = ref(null)
const quotesLoading = ref(false)
let quoteTimer = null
// Monotonic token — a late out-of-order response must never overwrite the
// projections of a newer amount.
let quoteSeq = 0

async function fetchQuotes() {
  if (!loan.value) return
  // Default the illustration to the full investable amount until the user types.
  const amount = parseFloat(investAmount.value) >= 50
    ? investAmount.value
    : (loan.value.investable_amount ?? loan.value.amount)
  const seq = ++quoteSeq
  quotesLoading.value = true
  try {
    const { data } = await api.get(`/loans/${loan.value.id}/offer-quotes`, { params: { amount } })
    if (seq !== quoteSeq) return
    offerQuotes.value = data.data
    // Keep the current selection if still valid, else default to the first offer.
    if (!offerQuotes.value.some(q => q.loan_offer_id === selectedOfferId.value)) {
      selectedOfferId.value = offerQuotes.value[0]?.loan_offer_id ?? null
    }
  } catch {
    if (seq === quoteSeq) offerQuotes.value = []
  } finally {
    if (seq === quoteSeq) quotesLoading.value = false
  }
}

// Debounce so typing an amount doesn't fire a request per keystroke.
watch(investAmount, () => {
  clearTimeout(quoteTimer)
  quoteTimer = setTimeout(fetchQuotes, 350)
})

const selectedQuote = computed(
  () => offerQuotes.value.find(q => q.loan_offer_id === selectedOfferId.value) || null,
)

// Header «Доходност»: the offers' range is the real pricing; the loan-level
// rate is nullable since 2026-08-10 and only backs legacy loans.
const headerRateDisplay = computed(() => {
  const r = loan.value?.offer_rate_range
  if (r && r.length === 2) {
    return r[0] === r[1] ? `${parseFloat(r[0])}%` : `${parseFloat(r[0])}–${parseFloat(r[1])}%`
  }
  return loan.value?.interest_rate ? `${loan.value.interest_rate}%` : '—'
})

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }
// «Задържана» (PAY-13) = due row while the loan's payouts are paused — derived
// server-side (`withheld`); the DB status stays `pending`. Same maps as the portfolio modal.
const scheduleStatusLabels = { pending: 'Предстои', paid: 'Платено', late: 'Закъснение', default: 'Просрочено', closed: 'Закрита предсрочно', withheld: 'Задържана' }
const scheduleStatusClasses = {
  pending: 'bg-gray-100 text-gray-500',
  paid: 'bg-green-50 text-green-600',
  late: 'bg-amber-50 text-amber-600',
  default: 'bg-red-50 text-red-600',
  closed: 'bg-blue-50 text-blue-600',
  withheld: 'bg-red-50 text-red-600',
}
const scheduleKey = (row) => (row.withheld ? 'withheld' : row.status)
const scheduleLabel = (row) => scheduleStatusLabels[scheduleKey(row)] || row.status
const scheduleClass = (row) => scheduleStatusClasses[scheduleKey(row)] || 'bg-gray-100 text-gray-500'

const riskBadgeClass = {
  A: 'bg-green-100 text-green-700 border-green-200',
  B: 'bg-lime-100 text-lime-700 border-lime-200',
  C: 'bg-amber-100 text-amber-700 border-amber-200',
  D: 'bg-orange-100 text-orange-700 border-orange-200',
  E: 'bg-red-100 text-red-700 border-red-200',
}
const riskLabels = {
  A: 'Нисък риск',
  B: 'Умерен риск',
  C: 'Среден риск',
  D: 'Повишен риск',
  E: 'Висок риск',
}

// Map event_type → human-readable BG label. Mirrors the constants in
// app/Models/LoanEvent.php; F2/F3/F4 placeholders included so unknown types
// from a future backend don't render as raw enum strings.
const eventTypeLabels = {
  went_late: 'Стана закъснял',
  recovered_from_late: 'Възстановен от late',
  went_default: 'Просрочен',
  buyback_triggered: 'Готов за изкупуване',
  buyback_completed: 'Buyback изпълнен',
  early_repayment_requested: 'Поискано предсрочно',
  early_repayment_completed: 'Изплатено предсрочно',
  fee_applied: 'Приложена такса',
  status_changed: 'Статус променен',
}
// PAY-13: pause/resume ride `status_changed` with metadata.kind.
const statusChangedKinds = {
  payouts_paused: { label: 'Плащанията са спрени', cls: 'bg-red-50 text-red-700 ring-red-200' },
  payouts_resumed: { label: 'Плащанията са възобновени', cls: 'bg-green-50 text-green-700 ring-green-200' },
}
const eventLabel = (evt) => statusChangedKinds[evt.metadata?.kind]?.label || eventTypeLabels[evt.event_type] || evt.event_type
const eventClass = (evt) => statusChangedKinds[evt.metadata?.kind]?.cls || eventTypeClass[evt.event_type] || 'bg-gray-50 text-gray-700 ring-gray-200'
const eventTypeClass = {
  went_late: 'bg-amber-50 text-amber-700 ring-amber-200',
  recovered_from_late: 'bg-green-50 text-green-700 ring-green-200',
  went_default: 'bg-red-50 text-red-700 ring-red-200',
  // F2 — buyback_triggered is informational (cron flagged, awaiting admin);
  // buyback_completed is a positive terminal outcome (investor received funds).
  buyback_triggered: 'bg-blue-50 text-blue-700 ring-blue-200',
  buyback_completed: 'bg-green-50 text-green-700 ring-green-200',
  // F3 — early_repayment_completed is a positive terminal outcome too
  // (borrower paid off early; investor received capital + accrued interest
  // through the current period boundary).
  early_repayment_completed: 'bg-green-50 text-green-700 ring-green-200',
}

// Coverage-type labels for buyback event metadata rendering.
const coverageLabels = {
  principal_only: 'Само главница',
  principal_plus_interest: 'Главница + лихва',
}

function formatDateTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('bg-BG', { dateStyle: 'medium', timeStyle: 'short' })
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/loans/${route.params.id}`)
    loan.value = data
    // Load the per-offer profit comparison for the default (full) amount.
    await fetchQuotes()
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Кредитът не е намерен.' : 'Грешка при зареждане.'
  } finally {
    loading.value = false
  }

  // Fetch lifecycle events. The endpoint enforces LoanPolicy::viewEvents —
  // a 403 just means "user has no position in this loan" which is normal
  // for marketplace browsing; treat silently.
  eventsLoading.value = true
  try {
    const { data } = await api.get(`/loans/${route.params.id}/events`)
    events.value = data.data
  } catch {
    // Intentional: 403 expected for non-investors
    // (privacy boundary). Hide timeline silently.
    events.value = []
  } finally {
    eventsLoading.value = false
  }

  // Own positions in this loan — secondary card, failures stay silent.
  try {
    const { data } = await api.get(`/loans/${route.params.id}/my-investments`)
    myInvestments.value = data.data
  } catch {
    myInvestments.value = []
  }
})

const availableBalance = computed(() => auth.user?.wallet?.available ?? '0.00')

// ── Live funding (engagement pack 2026-08-14): while the loan still takes
// money, re-poll every 30 s so the bar/count/social line move under the
// visitor's eyes; a funded jump briefly flashes the bar. Display-only. ──
const nowMs = ref(Date.now())
const fundingFlash = ref(false)
let livePollTimer = null
let liveClockTimer = null

async function refreshLiveFunding() {
  if (!loan.value || !['published', 'funding'].includes(loan.value.status)) return
  try {
    const { data } = await api.get(`/loans/${route.params.id}`)
    const funded = parseFloat(data.funded_amount)
    const current = parseFloat(loan.value.funded_amount)
    // Monotonic guard: funding only grows — a stale/out-of-order response
    // must never regress the bar (e.g. right after the viewer's own invest).
    if (funded < current) return
    if (funded > current) {
      fundingFlash.value = true
      setTimeout(() => { fundingFlash.value = false }, 1400)
    }
    // Merge only the live fields — never clobber offers/selection state.
    for (const key of ['funded_amount', 'funded_percentage', 'investors_count', 'last_invested_at', 'status', 'investable_amount']) {
      if (key in data) loan.value[key] = data[key]
    }
  } catch {
    // Secondary UI — a failed poll must never break the page.
  }
}

const lastInvestedAgo = computed(() => {
  if (!loan.value?.last_invested_at) return null
  return formatRelativeBg(loan.value.last_invested_at, nowMs.value)
})

onMounted(() => {
  livePollTimer = setInterval(refreshLiveFunding, 30_000)
  liveClockTimer = setInterval(() => { nowMs.value = Date.now() }, 30_000)
})

onBeforeUnmount(() => {
  clearInterval(livePollTimer)
  clearInterval(liveClockTimer)
})

const remaining = computed(() => {
  if (!loan.value) return '0.00'
  // Capacity is the investable pool (shared across all offers), not the nominal
  // loan amount — matches the server-side fundingCap.
  const cap = parseFloat(loan.value.investable_amount ?? loan.value.amount)
  return Math.max(0, cap - parseFloat(loan.value.funded_amount)).toFixed(2)
})

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

function openConfirm() {
  investError.value = null
  if (!selectedOfferId.value) {
    investError.value = 'Моля изберете оферта.'
    return
  }
  // The click-wrap contract must be concluded at terms the investor SAW.
  // Without a loaded quote there is no displayed rate — and no
  // expected_interest_rate for the backend's quote-vs-commit guard —
  // so investing is blocked until the quotes load.
  if (!selectedQuote.value) {
    investError.value = 'Условията не можаха да се заредят. Опитайте отново.'
    fetchQuotes()
    return
  }
  if (!investAmount.value || parseFloat(investAmount.value) < 50) {
    investError.value = 'Минималната инвестиция е 50.00 €.'
    return
  }
  if (parseFloat(investAmount.value) > parseFloat(availableBalance.value)) {
    investError.value = 'Недостатъчен свободен баланс.'
    return
  }
  if (parseFloat(investAmount.value) > parseFloat(remaining.value)) {
    investError.value = `Максимално за този кредит: ${remaining.value} €.`
    return
  }
  showConfirmModal.value = true
}

// Draft contract («ПРОЕКТ») for the currently selected offer + amount —
// opened in a new tab so the investor can read the document the invest
// click will conclude. Cookie session authenticates the request.
const contractPreviewUrl = computed(() => {
  if (!loan.value || !selectedOfferId.value) return null
  const amount = encodeURIComponent(investAmount.value || '50')
  return `/api/loans/${loan.value.id}/contract-preview?amount=${amount}&loan_offer_id=${selectedOfferId.value}`
})

async function confirmInvest() {
  investLoading.value = true
  investError.value = null
  try {
    const { data: investData } = await api.post(`/loans/${loan.value.id}/invest`, {
      amount: investAmount.value,
      loan_offer_id: selectedOfferId.value,
      // Quote-vs-commit guard: the backend rejects the commit if the offer
      // rate changed after this quote was displayed — the concluded
      // contract must carry exactly the terms the investor saw and agreed to.
      expected_interest_rate: selectedQuote.value?.interest_rate ?? null,
    })
    lastInvestmentId.value = investData.investment?.id ?? null
    investSuccess.value = true
    showConfirmModal.value = false
    // Refresh loan data and user wallet
    const { data } = await api.get(`/loans/${loan.value.id}`)
    loan.value = data
    await auth.fetchUser()
    // The fresh position must appear in «Вашата инвестиция» immediately.
    try {
      const { data: mine } = await api.get(`/loans/${loan.value.id}/my-investments`)
      myInvestments.value = mine.data
    } catch { /* secondary card — ignore */ }
  } catch (e) {
    showConfirmModal.value = false
    if (e.response?.status === 422) {
      const errors = e.response.data.errors || {}
      investError.value = Object.values(errors).flat()[0] || 'Грешка при инвестиране.'
      // Rate drifted between quote and commit — refresh the cards so the
      // re-confirmation happens against the CURRENT terms, not the stale ones.
      if (errors.expected_interest_rate) {
        fetchQuotes()
      }
    } else if (e.response?.status === 403) {
      investError.value = e.response.data.requires_kyc
        ? 'Необходима е KYC верификация за инвестиране.'
        : 'Нямате достъп до тази операция.'
    } else {
      investError.value = 'Грешка при инвестиране. Опитайте отново.'
    }
  } finally {
    investLoading.value = false
  }
}
</script>

<template>
  <div>
    <!-- Back -->
    <button @click="router.push('/invest')" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-navy-700 mb-6 transition-colors">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
      Назад към marketplace
    </button>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="rounded-2xl bg-red-50 border border-red-200 p-8 text-center">
      <p class="text-red-700 font-medium">{{ error }}</p>
      <button @click="router.push('/invest')" class="mt-4 px-4 py-2 bg-navy-700 text-white text-sm rounded-xl">Обратно</button>
    </div>

    <template v-else-if="loan">
      <div class="grid lg:grid-cols-3 gap-6">
        <!-- Left: Loan info -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Header card -->
          <div class="rounded-2xl border border-gray-100 bg-white p-6">
            <div class="flex items-start justify-between mb-4">
              <div>
                <h1 class="text-xl font-bold text-navy-700">{{ typeLabels[loan.type] || loan.type }} #{{ loan.id }}</h1>
                <p class="text-sm text-gray-500 mt-1">{{ loan.originator?.name }}</p>
              </div>
              <span class="px-3 py-1 rounded-full text-xs font-semibold"
                :class="{
                  'bg-accent-50 text-accent-500': ['published', 'funding', 'active'].includes(loan.status),
                  'bg-gray-100 text-gray-500': ['funded', 'repaid'].includes(loan.status),
                  'bg-amber-50 text-amber-600': loan.status === 'late',
                  'bg-red-50 text-red-600': loan.status === 'default',
                  'bg-blue-50 text-blue-600': loan.status === 'bought_back',
                }">
                {{ {
                  published: 'Отворен',
                  funding: 'Финансира се',
                  funded: 'Финансиран',
                  active: 'Активен',
                  late: 'Закъснение',
                  default: 'Просрочен',
                  bought_back: 'Изкупен',
                  repaid: 'Изплатен',
                }[loan.status] || loan.status }}
              </span>
            </div>

            <!-- PAY-13: the platform stopped fronting this loan's payouts -->
            <div
              v-if="loan.payouts_paused"
              class="mb-4 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700"
            >
              Плащанията по този кредит са <strong>временно спрени</strong><template v-if="loan.payouts_paused_at"> от {{ new Date(loan.payouts_paused_at).toLocaleDateString('bg-BG') }}</template>:
              кредитополучателят е в закъснение над допустимия срок. Дължимите Ви вноски остават в плана и ще бъдат изплатени
              след постъпване на плащане от кредитополучателя или при обратно изкупуване от оригинатора.
            </div>

            <!-- ГПР deliberately NOT shown — it is the borrower's cost of
                 credit, not investor information (Reni 2026-08-10). -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 py-4 border-y border-gray-100">
              <div>
                <p class="text-xs text-gray-400 mb-1">Сума</p>
                <p class="text-lg font-bold text-navy-700">{{ formatAmount(loan.amount) }} €</p>
              </div>
              <div title="Вашата годишна доходност от инвестицията в този кредит.">
                <p class="text-xs text-gray-400 mb-1">Доходност</p>
                <p class="text-lg font-bold text-accent-500">{{ headerRateDisplay }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-400 mb-1">Срок</p>
                <p class="text-lg font-bold text-navy-700">{{ loan.term_months }} мес.</p>
              </div>
              <div>
                <p class="text-xs text-gray-400 mb-1">Инвеститори</p>
                <p class="text-lg font-bold text-navy-700">{{ loan.investors_count ?? 0 }}</p>
              </div>
            </div>
            <p class="text-xs text-gray-400 mt-2 leading-relaxed">
              <strong class="text-accent-500">Доходност</strong> — какво печелите вие от тази инвестиция.
            </p>

            <!-- Funding progress + the big «свободно» figure (Reni 2026-08-14:
                 big clear digits at this spot — the small «Макс» in the invest
                 box was barely visible). Count-up + live dot + scarcity flip
                 live in FreeCapacityBadge. -->
            <div class="mt-4">
              <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2 mb-2">
                <span class="text-sm text-gray-500">Финансирано: {{ formatAmount(loan.funded_amount) }} от {{ formatAmount(loan.amount) }} €</span>
                <FreeCapacityBadge
                  v-if="['published', 'funding'].includes(loan.status) && parseFloat(remaining) > 0"
                  :remaining="remaining"
                  :cap="String(loan.investable_amount ?? loan.amount)"
                />
              </div>
              <div class="flex items-center gap-3">
                <div
                  class="funding-track flex-1 h-3 rounded-full bg-gray-100 overflow-hidden"
                  :class="{ 'funding-track--open': ['published', 'funding'].includes(loan.status), 'funding-track--flash': fundingFlash }"
                >
                  <div class="h-full rounded-full bg-accent-400 transition-all duration-500" :style="{ width: loan.funded_percentage + '%' }"></div>
                </div>
                <span class="text-sm font-semibold text-navy-700 shrink-0">{{ loan.funded_percentage }}%</span>
              </div>
              <!-- Social proof: живо, анонимно -->
              <p v-if="lastInvestedAgo" class="mt-2 flex items-center gap-1.5 text-xs text-gray-500">
                <span class="live-dot" aria-hidden="true"></span>
                {{ loan.investors_count ?? 0 }} {{ (loan.investors_count ?? 0) === 1 ? 'инвеститор' : 'инвеститори' }}
                · последна инвестиция {{ lastInvestedAgo }}
              </p>
            </div>
          </div>

          <!-- «Вашата инвестиция» — the viewer's own terms in this loan
               (Reni 2026-08-17): chosen plan, snapshotted rate, personal
               schedule with monthly installments. Accent border — this is
               HER money, the most important card on the page. -->
          <div v-if="myInvestments.length" class="rounded-2xl border-2 border-accent-400/50 bg-white p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
              <h3 class="text-base font-bold text-navy-700">{{ myInvestments.length === 1 ? 'Вашата инвестиция' : 'Вашите инвестиции' }}</h3>
              <span v-if="myInvestments.length > 1" class="text-sm text-gray-500">Общо <strong class="text-navy-700">{{ formatAmount(myTotalInvested) }} €</strong></span>
            </div>
            <p class="text-xs text-gray-400 mb-4">Вашите условия по този кредит — избрана оферта, доходност и погасителен план.</p>

            <div v-for="(inv, idx) in myInvestments" :key="inv.id" :class="idx > 0 ? 'mt-5 pt-5 border-t border-gray-100' : ''">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-2 mb-3">
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-accent-50 text-accent-600 ring-1 ring-accent-400/40">{{ inv.payout_label || 'Без оферта' }}</span>
                <span class="text-lg font-bold text-navy-700">{{ formatAmount(inv.amount) }} €</span>
                <span class="text-lg font-bold text-accent-500" title="Вашата годишна доходност — фиксирана при инвестирането">
                  {{ inv.interest_rate ? `${inv.interest_rate}%` : (loan.interest_rate ? `${loan.interest_rate}%` : '—') }}
                </span>
                <span class="text-xs text-gray-400">от {{ new Date(inv.invested_at).toLocaleDateString('bg-BG') }}</span>
                <a v-if="inv.has_contract" :href="`/api/investments/${inv.id}/contract`" target="_blank" rel="noopener"
                   class="ml-auto text-xs font-medium text-navy-700 underline hover:text-navy-900">Договор (PDF)</a>
              </div>

              <!-- Buyback settlement flips rows to 'paid' as a settlement
                   marker, not cash truth — never render them as received. -->
              <p v-if="loan.status === 'bought_back'" class="text-sm text-blue-700 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                Кредитът е изкупен от оригинатора — вашата част е върната в портфейла.
              </p>
              <div v-else-if="inv.schedule?.length" class="rounded-xl border border-gray-100 overflow-hidden">
                <p class="px-4 py-2 text-xs font-semibold text-navy-700 bg-gray-50/60 border-b border-gray-100">
                  Вашият погасителен план · {{ inv.schedule.length }} {{ inv.schedule.length === 1 ? 'вноска' : 'вноски' }}
                </p>
                <div class="max-h-56 overflow-y-auto overflow-x-auto overscroll-contain">
                  <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-gray-50">
                      <tr class="text-left text-gray-400">
                        <th class="px-3 py-2 font-medium">Дата</th>
                        <th class="px-3 py-2 font-medium text-right">Главница</th>
                        <th class="px-3 py-2 font-medium text-right">Лихва</th>
                        <th class="px-3 py-2 font-medium text-right">Общо</th>
                        <th class="px-3 py-2 font-medium text-right">Статус</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(row, i) in inv.schedule" :key="i" class="border-t border-gray-50">
                        <td class="px-3 py-2 text-gray-600 whitespace-nowrap">{{ new Date(row.due_date).toLocaleDateString('bg-BG') }}</td>
                        <td class="px-3 py-2 text-right text-navy-700 whitespace-nowrap">{{ formatAmount(row.principal) }}</td>
                        <td class="px-3 py-2 text-right text-accent-500 whitespace-nowrap">{{ formatAmount(row.interest) }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-navy-700 whitespace-nowrap">{{ formatAmount(row.total) }}</td>
                        <td class="px-3 py-2 text-right">
                          <span class="px-1.5 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap" :class="scheduleClass(row)">
                            {{ scheduleLabel(row) }}
                          </span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <p v-else-if="inv.loan_offer_id" class="text-xs text-gray-400">
                Персоналният погасителен план се генерира при активиране на кредита.
              </p>
              <p v-else class="text-xs text-gray-400">
                Погасителният план е общ за кредита — вижте таблицата по-долу.
              </p>
            </div>
          </div>

          <!-- Borrower profile + Originator -->
          <div class="grid sm:grid-cols-2 gap-6">
            <div class="rounded-2xl border border-gray-100 bg-white p-5" v-if="loan.anonymized_profile">
              <h3 class="text-sm font-bold text-navy-700 mb-3">Профил на кредитополучателя</h3>
              <!-- Prominent risk class badge -->
              <div class="flex items-center gap-3 p-3 rounded-xl border mb-4" :class="riskBadgeClass[loan.anonymized_profile.risk_class]">
                <span class="text-2xl font-extrabold">{{ loan.anonymized_profile.risk_class }}</span>
                <div>
                  <p class="text-sm font-bold">{{ riskLabels[loan.anonymized_profile.risk_class] }}</p>
                  <p class="text-xs opacity-75">Рисков клас</p>
                </div>
              </div>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-400">Регион</span><span class="text-navy-700">{{ loan.anonymized_profile.region }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Цел</span><span class="text-navy-700">{{ loan.anonymized_profile.loan_purpose }}</span></div>
                <div v-if="loan.anonymized_profile.collateral_type" class="flex justify-between"><span class="text-gray-400">Обезпечение</span><span class="text-navy-700">{{ loan.anonymized_profile.collateral_type }}</span></div>
                <div v-if="loan.anonymized_profile.age_group" class="flex justify-between"><span class="text-gray-400">Възрастова група</span><span class="text-navy-700">{{ loan.anonymized_profile.age_group }}</span></div>
              </div>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5" v-if="loan.originator">
              <h3 class="text-sm font-bold text-navy-700 mb-3">Оригинатор</h3>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-400">Име</span><span class="font-semibold text-navy-700">{{ loan.originator.name }}</span></div>
                <div class="flex justify-between">
                  <span class="text-gray-400">Buyback</span>
                  <span :class="loan.originator.buyback ? 'text-accent-500' : 'text-gray-400'" class="font-medium">{{ loan.originator.buyback ? 'Да' : 'Не' }}</span>
                </div>
                <!-- F2 — coverage type surfaces only when originator supports buyback.
                     Resolved server-side (originator override OR platform default). -->
                <div v-if="loan.originator.buyback && loan.originator.buyback_coverage" class="flex justify-between">
                  <span class="text-gray-400">Покритие</span>
                  <span class="font-medium text-navy-700">{{ coverageLabels[loan.originator.buyback_coverage] || loan.originator.buyback_coverage }}</span>
                </div>
                <!-- F2 — terminal date for bought-back loans. -->
                <div v-if="loan.bought_back_at" class="flex justify-between">
                  <span class="text-gray-400">Изкупен на</span>
                  <span class="font-medium text-blue-600">{{ formatDateTime(loan.bought_back_at) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Amortization schedule -->
          <div v-if="loan.amortization_schedule?.length" class="rounded-2xl border border-gray-100 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
              <h3 class="text-sm font-bold text-navy-700">Погасителен план</h3>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
                    <th class="px-5 py-2 font-medium">Дата</th>
                    <th class="px-5 py-2 font-medium">Главница</th>
                    <th class="px-5 py-2 font-medium">Лихва</th>
                    <th class="px-5 py-2 font-medium">Общо</th>
                    <th class="px-5 py-2 font-medium">Статус</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in loan.amortization_schedule" :key="row.id" class="border-t border-gray-50">
                    <td class="px-5 py-2 text-gray-600">{{ new Date(row.due_date).toLocaleDateString('bg-BG') }}</td>
                    <td class="px-5 py-2 text-navy-700">{{ formatAmount(row.principal) }} €</td>
                    <td class="px-5 py-2 text-accent-500">{{ formatAmount(row.interest) }} €</td>
                    <td class="px-5 py-2 font-semibold text-navy-700">{{ formatAmount(row.total) }} €</td>
                    <td class="px-5 py-2">
                      <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="scheduleClass(row)">
                        {{ scheduleLabel(row) }}
                      </span>
                      <!-- days_late visible only for late rows; the field is
                           always present but only meaningful when status='late'. -->
                      <span v-if="row.status === 'late' && row.days_late > 0" class="ml-1 text-xs text-amber-600">
                        +{{ row.days_late }}д
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Lifecycle timeline — only rendered when there are visible events
               (LoanPolicy::viewEvents gate hides this for users without a
               position in the loan). All copy is anonymised by design:
               investor sees WHAT happened and WHEN, never WHO triggered it. -->
          <div v-if="events.length" class="rounded-2xl border border-gray-100 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
              <h3 class="text-sm font-bold text-navy-700">Timeline на събития</h3>
              <p class="text-xs text-gray-400 mt-0.5">Хронология на статус-промените на този кредит</p>
            </div>
            <ul class="divide-y divide-gray-100">
              <li v-for="evt in events" :key="evt.id" class="px-5 py-3 flex items-start gap-3">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold ring-1 shrink-0"
                  :class="eventClass(evt)"
                >{{ eventLabel(evt) }}</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm text-navy-700">
                    <span v-if="evt.from_status && evt.to_status">{{ evt.from_status }} → {{ evt.to_status }}</span>
                    <span v-else class="text-gray-400">—</span>
                    <span class="text-xs text-gray-400 ml-2">({{ evt.triggered_by === 'system' ? 'автоматично' : 'администратор' }})</span>
                  </p>
                  <p class="text-xs text-gray-500 mt-0.5">{{ formatDateTime(evt.occurred_at) }}</p>
                  <!-- Sanitised metadata (whitelist enforced server-side). -->
                  <!-- F1 — late / recovered metadata -->
                  <p v-if="evt.metadata?.days_late_at_transition != null" class="text-xs text-amber-600 mt-1">
                    Закъснение към момента: {{ evt.metadata.days_late_at_transition }} дни
                  </p>
                  <p v-if="evt.metadata?.previous_became_late_at" class="text-xs text-gray-500 mt-1">
                    Предишно станал late на {{ formatDateTime(evt.metadata.previous_became_late_at) }}
                  </p>
                  <p v-if="evt.metadata?.transitioned_to" class="text-xs text-gray-500 mt-1">
                    Възстановен в статус: <span class="font-semibold">{{ evt.metadata.transitioned_to }}</span>
                  </p>

                  <!-- F2 — buyback_triggered (cron detection) metadata -->
                  <p v-if="evt.metadata?.coverage_type" class="text-xs text-gray-500 mt-1">
                    Покритие: <span class="font-semibold">{{ coverageLabels[evt.metadata.coverage_type] || evt.metadata.coverage_type }}</span>
                  </p>
                  <p v-if="evt.metadata?.days_since_became_late != null" class="text-xs text-amber-600 mt-1">
                    Дни закъснение: {{ evt.metadata.days_since_became_late }}
                  </p>
                  <p v-if="evt.metadata?.calculated_buyback_amount_at_detection" class="text-xs text-gray-500 mt-1">
                    Прогнозна сума при откриване: <span class="font-semibold">{{ formatAmount(evt.metadata.calculated_buyback_amount_at_detection) }} €</span>
                  </p>

                  <!-- F2 — buyback_completed (execution) metadata -->
                  <p v-if="evt.event_type === 'buyback_completed' && evt.metadata?.total_amount" class="text-xs text-blue-700 mt-1">
                    Изкупена сума: <span class="font-semibold">{{ formatAmount(evt.metadata.total_amount) }} €</span>
                    <span v-if="evt.metadata.total_principal && evt.metadata.total_interest" class="text-gray-500">
                      (главница: {{ formatAmount(evt.metadata.total_principal) }} €, лихва: {{ formatAmount(evt.metadata.total_interest) }} €)
                    </span>
                  </p>

                  <!-- F3 — early_repayment_completed (execution) metadata.
                       Same whitelist keys as F2 buyback (total_amount,
                       total_principal, total_interest, investor_count,
                       executed_at) plus F3-specific `from_status`. Filter by
                       event_type so the "Изкупена сума" label above doesn't
                       fire for F3 events. -->
                  <p v-if="evt.event_type === 'early_repayment_completed' && evt.metadata?.total_amount" class="text-xs text-green-700 mt-1">
                    Получена сума: <span class="font-semibold">{{ formatAmount(evt.metadata.total_amount) }} €</span>
                    <span v-if="evt.metadata.total_principal && evt.metadata.total_interest" class="text-gray-500">
                      (главница: {{ formatAmount(evt.metadata.total_principal) }} €, лихва: {{ formatAmount(evt.metadata.total_interest) }} €)
                    </span>
                  </p>
                  <p v-if="evt.metadata?.investor_count" class="text-xs text-gray-500 mt-1">
                    Разпределена към {{ evt.metadata.investor_count }} инвеститор(и).
                  </p>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <!-- Right: Invest panel -->
        <div class="space-y-6">
          <div class="rounded-2xl border border-gray-100 bg-white p-6 sticky top-24">
            <h3 class="text-base font-bold text-navy-700 mb-4">Инвестирай</h3>

            <!-- Success state -->
            <div v-if="investSuccess" class="text-center py-4">
              <div class="flex size-14 items-center justify-center rounded-2xl bg-accent-50 text-accent-500 mx-auto mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
              </div>
              <p class="text-sm font-semibold text-navy-700 mb-1">Успешна инвестиция!</p>
              <p class="text-xs text-gray-500 mb-4">Инвестирахте {{ formatAmount(investAmount) }} € в кредит #{{ loan.id }}</p>
              <a v-if="lastInvestmentId" :href="`/api/investments/${lastInvestmentId}/contract`" target="_blank" rel="noopener"
                 class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-navy-700 underline hover:text-navy-900">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                Договор за заем (PDF)
              </a>
              <br>
              <button @click="investSuccess = false; investAmount = ''" class="text-sm text-accent-500 font-medium">Инвестирай отново</button>
            </div>

            <!-- Invest form -->
            <template v-else-if="canInvest(loan)">
              <div class="flex items-center justify-between text-sm mb-4 p-3 rounded-xl bg-gray-50">
                <span class="text-gray-500">Свободен баланс</span>
                <span class="font-semibold text-navy-700">{{ formatAmount(availableBalance) }} €</span>
              </div>

              <div class="mb-4">
                <label class="block text-sm font-medium text-navy-700 mb-1">Сума (€)</label>
                <input
                  v-model="investAmount"
                  type="number"
                  min="50"
                  :max="remaining"
                  step="0.01"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                  :class="investError ? 'border-red-400' : ''"
                  placeholder="мин. 50.00"
                />
                <div class="flex items-center justify-between mt-1">
                  <p v-if="investError" class="text-xs text-red-500">{{ investError }}</p>
                  <p class="text-xs text-gray-400 ml-auto">Макс: {{ formatAmount(remaining) }} €</p>
                </div>
              </div>

              <!-- The chosen offer (the cards live full-width below the loan
                   info — Reni 2026-08-10: «трите отдолу, едно до друго») -->
              <div class="mb-4 rounded-xl border p-3 text-sm"
                   :class="selectedQuote ? 'border-accent-400 bg-accent-50' : 'border-gray-200 bg-gray-50'">
                <template v-if="selectedQuote">
                  <div class="flex items-center justify-between">
                    <span class="font-semibold text-navy-700">{{ selectedQuote.label }}</span>
                    <span class="font-bold text-accent-500">{{ selectedQuote.interest_rate }}%</span>
                  </div>
                  <p class="mt-1 text-xs text-gray-500">Печалба +{{ formatAmount(selectedQuote.total_interest) }} € · Общо {{ formatAmount(selectedQuote.total_repaid) }} €</p>
                </template>
                <p v-else class="text-xs text-gray-500">Изберете оферта от плановете по-долу.</p>
              </div>

              <button
                @click="openConfirm"
                :disabled="investLoading"
                class="w-full py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
              >
                {{ investLoading ? 'Обработка...' : 'Инвестирай' }}
              </button>
            </template>

            <!-- Fully funded -->
            <div v-else class="text-center py-4">
              <div class="flex size-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400 mx-auto mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
              </div>
              <p class="text-sm font-semibold text-gray-500">{{ loan.status === 'repaid' ? 'Кредитът е приключен' : loan.status === 'bought_back' ? 'Изкупен' : 'Напълно финансиран' }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- 3-те оферти — на цял ред, една до друга, с погасителния план под
           всяка (Рени 2026-08-10: «по-ясни, отдолу хоризонтално, под всеки
           да се зарежда погасителният план»). -->
      <div v-if="canInvest(loan) && !investSuccess && offerQuotes.length" class="mt-10">
        <div class="flex items-baseline justify-between mb-1">
          <h2 class="text-xl font-bold text-navy-700">Изберете оферта</h2>
          <span v-if="quotesLoading" class="text-sm text-gray-400">изчисляване…</span>
        </div>
        <p class="text-sm text-gray-500 mb-5">Печалбата е изчислена за въведената сума. Капацитетът на кредита е общ за трите оферти.</p>

        <div class="grid md:grid-cols-3 gap-5 items-start">
          <div
            v-for="q in offerQuotes" :key="q.loan_offer_id"
            class="rounded-2xl border bg-white transition-colors"
            :class="selectedOfferId === q.loan_offer_id ? 'border-accent-400 ring-2 ring-accent-400' : 'border-gray-200 hover:border-accent-300'"
          >
            <button type="button" class="w-full text-left p-5" @click="selectedOfferId = q.loan_offer_id">
              <div class="flex items-center justify-between">
                <span class="text-lg font-bold text-navy-700">{{ q.label }}</span>
                <span class="text-xl font-bold text-accent-500">{{ q.interest_rate }}%</span>
              </div>
              <p class="text-sm text-gray-500 mt-1 leading-snug">{{ q.description }}</p>
              <div class="mt-4 space-y-1.5">
                <div class="flex items-center justify-between">
                  <span class="text-sm text-gray-500">Печалба</span>
                  <span class="text-lg font-bold text-green-600">+{{ formatAmount(q.total_interest) }} €</span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-sm text-gray-500">Получавате общо</span>
                  <span class="text-lg font-semibold text-navy-700">{{ formatAmount(q.total_repaid) }} €</span>
                </div>
                <p class="text-sm text-gray-500">
                  <span v-if="q.monthly_payment">≈ {{ formatAmount(q.monthly_payment) }} € / месец</span>
                  <span v-else>Изплащане наведнъж на падежа</span>
                </p>
              </div>
              <div class="mt-4 rounded-xl py-2 text-center text-sm font-semibold"
                   :class="selectedOfferId === q.loan_offer_id ? 'bg-accent-400 text-white' : 'bg-gray-100 text-gray-500'">
                {{ selectedOfferId === q.loan_offer_id ? '✓ Избрана оферта' : 'Избери' }}
              </div>
            </button>

            <!-- Погасителен план на тази оферта -->
            <div class="border-t border-gray-100 px-5 py-4">
              <p class="text-sm font-semibold text-navy-700 mb-2">Погасителен план</p>
              <div class="max-h-72 overflow-y-auto rounded-xl border border-gray-100">
                <table class="w-full text-sm">
                  <thead class="sticky top-0 bg-gray-50">
                    <tr class="text-left text-gray-400">
                      <th class="px-3 py-2 font-medium">Дата</th>
                      <th class="px-3 py-2 font-medium text-right">Главница</th>
                      <th class="px-3 py-2 font-medium text-right">Лихва</th>
                      <th class="px-3 py-2 font-medium text-right">Общо</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in q.schedule" :key="i" class="border-t border-gray-50">
                      <td class="px-3 py-2 text-gray-600 whitespace-nowrap">{{ new Date(row.due_date).toLocaleDateString('bg-BG') }}</td>
                      <td class="px-3 py-2 text-right text-navy-700">{{ formatAmount(row.principal) }}</td>
                      <td class="px-3 py-2 text-right text-accent-500">{{ formatAmount(row.interest) }}</td>
                      <td class="px-3 py-2 text-right font-semibold text-navy-700">{{ formatAmount(row.total) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Confirmation modal -->
    <Teleport to="body">
      <div v-if="showConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black/40" @click="showConfirmModal = false"></div>
        <div class="relative bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl">
          <h3 class="text-lg font-bold text-navy-700 mb-2">Потвърди инвестиция</h3>
          <p class="text-sm text-gray-500 mb-6">
            Сигурни ли сте, че искате да инвестирате
            <strong class="text-navy-700">{{ formatAmount(investAmount) }} €</strong>
            в кредит <strong class="text-navy-700">#{{ loan.id }}</strong>
            <span v-if="selectedQuote">по оферта <strong class="text-navy-700">{{ selectedQuote.label }}</strong> ({{ selectedQuote.interest_rate }}%)</span>?
          </p>
          <div class="mb-5 rounded-xl bg-gray-50 p-3 text-xs text-gray-500 leading-relaxed">
            С натискането на „Потвърди“ сключвате <strong class="text-navy-700">Договор за целеви паричен заем</strong>
            при избраните условия. Кликването се записва като Вашето електронно съгласие с договора —
            подписи не се полагат.
            <a v-if="contractPreviewUrl" :href="contractPreviewUrl" target="_blank" rel="noopener"
               class="mt-1.5 block font-medium text-accent-500 underline hover:text-accent-600">
              Преглед на договора (проект, PDF)
            </a>
          </div>
          <div class="flex gap-3">
            <button @click="showConfirmModal = false" class="flex-1 py-2.5 border border-gray-200 text-sm font-medium text-gray-600 rounded-xl">Отказ</button>
            <button @click="confirmInvest" :disabled="investLoading" class="flex-1 py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl">
              {{ investLoading ? 'Обработка...' : 'Потвърди' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* Animated stripes over the UNFILLED part of the funding bar while the loan
   still takes money — the free capacity literally moves («по-готино»,
   2026-08-14). The solid green fill covers the funded part on top. */
.funding-track--open {
  background-image: repeating-linear-gradient(
    -55deg,
    rgba(34, 197, 94, 0.14) 0 6px,
    rgba(34, 197, 94, 0.05) 6px 12px
  );
  background-size: 200% 100%;
  animation: funding-stripes 18s linear infinite;
}

@keyframes funding-stripes {
  0% { background-position: 0 0; }
  100% { background-position: -200% 0; }
}

/* Someone just invested — the bar glows for a beat. */
.funding-track--flash {
  animation: funding-flash 1.4s ease-out;
}

@keyframes funding-flash {
  0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.6); }
  100% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
}

/* Live social-proof dot */
.live-dot {
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: #22c55e;
  animation: live-pulse 1.8s ease-in-out infinite;
}

@keyframes live-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45); }
  70% { box-shadow: 0 0 0 5px rgba(34, 197, 94, 0); }
}

@media (prefers-reduced-motion: reduce) {
  .funding-track--open,
  .funding-track--flash,
  .live-dot {
    animation: none;
  }
}
</style>
