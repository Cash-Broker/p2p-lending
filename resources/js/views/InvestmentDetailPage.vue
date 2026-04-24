<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../api/axios'

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

// Invest form
const investAmount = ref('')
const investLoading = ref(false)
const investError = ref(null)
const investSuccess = ref(false)
const showConfirmModal = ref(false)

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }
const scheduleStatusLabels = { pending: 'Предстои', paid: 'Платено', late: 'Закъснение', default: 'Просрочено' }

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
    events.value = []
  } finally {
    eventsLoading.value = false
  }
})

const availableBalance = computed(() => auth.user?.wallet?.available ?? '0.00')

const remaining = computed(() => {
  if (!loan.value) return '0.00'
  return Math.max(0, parseFloat(loan.value.amount) - parseFloat(loan.value.funded_amount)).toFixed(2)
})

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

function openConfirm() {
  investError.value = null
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

async function confirmInvest() {
  investLoading.value = true
  investError.value = null
  try {
    await api.post(`/loans/${loan.value.id}/invest`, { amount: investAmount.value })
    investSuccess.value = true
    showConfirmModal.value = false
    // Refresh loan data and user wallet
    const { data } = await api.get(`/loans/${loan.value.id}`)
    loan.value = data
    await auth.fetchUser()
  } catch (e) {
    showConfirmModal.value = false
    if (e.response?.status === 422) {
      const errors = e.response.data.errors || {}
      investError.value = Object.values(errors).flat()[0] || 'Грешка при инвестиране.'
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

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 py-4 border-y border-gray-100">
              <div>
                <p class="text-xs text-gray-400 mb-1">Сума</p>
                <p class="text-lg font-bold text-navy-700">{{ formatAmount(loan.amount) }} €</p>
              </div>
              <div title="Вашата годишна доходност от инвестицията в този кредит.">
                <p class="text-xs text-gray-400 mb-1">Доходност</p>
                <p class="text-lg font-bold text-accent-500">{{ loan.interest_rate }}%</p>
              </div>
              <div title="ГПР — Годишен Процент на Разходите. Общата цена на кредита за кредитополучателя (регулаторна ставка).">
                <p class="text-xs text-gray-400 mb-1">ГПР</p>
                <p class="text-lg font-bold text-navy-700">
                  <span v-if="loan.apr">{{ loan.apr }}%</span>
                  <span v-else class="text-gray-300">—</span>
                </p>
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
              <strong class="text-navy-700 ml-1">ГПР</strong> — какво плаща кредитополучателят (включва лихва и такси).
            </p>

            <!-- Funding progress -->
            <div class="mt-4">
              <div class="flex items-center justify-between text-sm mb-2">
                <span class="text-gray-500">Финансирано: {{ formatAmount(loan.funded_amount) }} от {{ formatAmount(loan.amount) }} €</span>
                <span class="font-semibold text-navy-700">{{ loan.funded_percentage }}%</span>
              </div>
              <div class="w-full h-3 rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-accent-400 transition-all duration-500" :style="{ width: loan.funded_percentage + '%' }"></div>
              </div>
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
                      <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                        :class="{
                          'bg-gray-100 text-gray-500': row.status === 'pending',
                          'bg-green-50 text-green-600': row.status === 'paid',
                          'bg-amber-50 text-amber-600': row.status === 'late',
                          'bg-red-50 text-red-600': row.status === 'default',
                        }">
                        {{ scheduleStatusLabels[row.status] }}
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
                  :class="eventTypeClass[evt.event_type] || 'bg-gray-50 text-gray-700 ring-gray-200'"
                >{{ eventTypeLabels[evt.event_type] || evt.event_type }}</span>
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
              <button @click="investSuccess = false; investAmount = ''" class="text-sm text-accent-500 font-medium">Инвестирай отново</button>
            </div>

            <!-- Invest form -->
            <template v-else-if="loan.funded_percentage < 100">
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
              <p class="text-sm font-semibold text-gray-500">Напълно финансиран</p>
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
            в кредит <strong class="text-navy-700">#{{ loan.id }}</strong>?
          </p>
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
