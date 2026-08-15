<script setup>
// Portfolio investment-detail modal (Reni 2026-08-15: «като кликна върху някой
// кредит да излиза някакво инфо»). Bottom sheet on mobile, centered on desktop.
// Shows the investor's OWN position (snapshotted rate/plan + per-installment
// schedule) and links out to the loan page — which stays accessible for
// private (link-shared) loans too, because having an investment grants access.
import { computed, nextTick, ref, watch, onBeforeUnmount } from 'vue'
import {
  summarizeSchedule,
  nextUnpaidInstallment,
  dueLabelBg,
  daysUntilDue,
  parseDateOnlyLocal,
} from '../utils/investmentSummary'

const props = defineProps({
  investment: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }
const statusLabels = {
  active: 'Активен',
  funding: 'Финансира се',
  funded: 'Финансиран',
  late: 'Закъснение',
  default: 'Просрочен',
  bought_back: 'Изкупен',
  repaid: 'Изплатен',
}
const statusClasses = {
  active: 'bg-green-50 text-green-600',
  funding: 'bg-blue-50 text-blue-600',
  funded: 'bg-blue-50 text-blue-600',
  late: 'bg-amber-50 text-amber-600',
  default: 'bg-red-50 text-red-600',
  bought_back: 'bg-blue-50 text-blue-600',
  repaid: 'bg-gray-100 text-gray-500',
}
const scheduleStatusLabels = { pending: 'Предстои', paid: 'Платено', late: 'Закъснение', default: 'Просрочено' }
const scheduleStatusClasses = {
  pending: 'bg-gray-100 text-gray-500',
  paid: 'bg-green-50 text-green-600',
  late: 'bg-amber-50 text-amber-600',
  default: 'bg-red-50 text-red-600',
}
// Mobile schedule rows compress the status pill to a colored dot next to the
// date (five text columns don't fit 375px; principal/interest/total must stay).
const scheduleStatusDotClasses = {
  pending: 'bg-gray-300',
  paid: 'bg-green-500',
  late: 'bg-amber-500',
  default: 'bg-red-500',
}

const loan = computed(() => props.investment?.loan ?? null)

// Buyback settlement flips schedule rows to 'paid' as a SETTLEMENT marker, not
// a cash-truth marker: under principal_only coverage the interest part was
// written off, never paid. Row-derived «Получено»/progress would therefore
// overstate an entire terminal class of positions — for bought_back we show a
// neutral note instead of the schedule-derived figures.
const isBoughtBack = computed(() => loan.value?.status === 'bought_back')

const summary = computed(() => (isBoughtBack.value ? null : summarizeSchedule(props.investment?.schedule)))
const nextInstallment = computed(() => {
  // Terminal loans owe nothing more on the plan.
  if (['repaid', 'bought_back'].includes(loan.value?.status)) return null
  return nextUnpaidInstallment(props.investment?.schedule)
})
const nextDueLabel = computed(() => nextInstallment.value ? dueLabelBg(nextInstallment.value.due_date) : null)
// Past-due unpaid installment (late detection only flags the loan, not the
// per-investment rows) — the card must not celebrate an overdue date in green.
const nextIsOverdue = computed(() => {
  const days = nextInstallment.value ? daysUntilDue(nextInstallment.value.due_date) : null
  return days !== null && days < 0
})

// The investment's snapshotted rate is the truth; loan-level rate only backs
// legacy (pre-offer) positions — same fallback as the portfolio table.
const displayRate = computed(() => {
  const r = props.investment?.interest_rate ?? loan.value?.interest_rate
  return r ? `${r}%` : '—'
})

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}
function formatDate(val) {
  if (!val) return '—'
  // Date-only strings must be parsed as LOCAL midnight so the printed date
  // can never disagree with dueLabelBg (UTC parse is a day off west of UTC).
  const d = parseDateOnlyLocal(val) ?? new Date(val)
  return d.toLocaleDateString('bg-BG')
}

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}

const panel = ref(null)
let lastFocused = null

watch(
  () => props.investment,
  (open) => {
    if (open) {
      window.addEventListener('keydown', onKeydown)
      document.body.style.overflow = 'hidden'
      // Move focus into the dialog (aria-modal without focus leaves screen
      // readers stranded on the obscured table); remember the opener row.
      lastFocused = document.activeElement
      nextTick(() => panel.value?.focus())
    } else {
      window.removeEventListener('keydown', onKeydown)
      document.body.style.overflow = ''
      if (lastFocused?.focus) lastFocused.focus()
      lastFocused = null
    }
  },
)
// «Към кредита» navigates away while open — the watch never fires, so the
// unmount hook must undo both the listener and the body scroll lock.
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div v-if="investment && loan" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4">
      <div class="fixed inset-0 bg-black/40" @click="emit('close')"></div>

      <div
        ref="panel"
        tabindex="-1"
        class="investment-modal relative bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-lg max-h-[88vh] overflow-y-auto overscroll-contain focus:outline-none"
        role="dialog"
        aria-modal="true"
        :aria-label="`Инвестиция в кредит #${loan.id}`"
      >
        <!-- Header -->
        <div class="sticky top-0 bg-white px-5 pt-5 pb-4 border-b border-gray-100 flex items-start justify-between gap-3">
          <div>
            <h3 class="text-lg font-bold text-navy-700">{{ typeLabels[loan.type] || loan.type }} #{{ loan.id }}</h3>
            <p class="text-sm text-gray-500 mt-0.5">{{ loan.originator?.name }}</p>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="statusClasses[loan.status]">
              {{ statusLabels[loan.status] || loan.status }}
            </span>
            <button
              @click="emit('close')"
              class="flex size-8 items-center justify-center rounded-lg text-gray-400 hover:text-navy-700 hover:bg-gray-50"
              aria-label="Затвори"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
          </div>
        </div>

        <div class="p-5 space-y-5">
          <!-- Late warning — same figure as the table badge -->
          <div
            v-if="loan.status === 'late' && loan.days_overdue_max != null"
            class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700"
          >
            Най-старата непогасена вноска е забавена с <strong>{{ loan.days_overdue_max }} дни</strong>.
            Оригинаторът работи по събирането.
          </div>

          <!-- Key figures -->
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-gray-50 p-3">
              <p class="text-xs text-gray-400 mb-0.5">Инвестирано</p>
              <p class="text-base font-bold text-navy-700">{{ formatAmount(investment.amount) }} €</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3">
              <p class="text-xs text-gray-400 mb-0.5">Доходност</p>
              <p class="text-base font-bold text-accent-500">{{ displayRate }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3">
              <p class="text-xs text-gray-400 mb-0.5">План</p>
              <p class="text-sm font-semibold text-navy-700">{{ investment.payout_label || '—' }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3">
              <p class="text-xs text-gray-400 mb-0.5">Срок</p>
              <p class="text-sm font-semibold text-navy-700">{{ loan.term_months }} мес. <span class="text-xs font-normal text-gray-400">· от {{ formatDate(investment.invested_at) }}</span></p>
            </div>
          </div>

          <!-- Bought back: the neutral truth. Row-derived sums would claim
               written-off interest as received under principal_only coverage. -->
          <div v-if="isBoughtBack" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
            Кредитът е изкупен от оригинатора съгласно buyback гаранцията.
            Вашата част е върната в портфейла — вижте страницата на кредита за детайли по изплащането.
          </div>

          <template v-else>
            <!-- Next installment — amber when the due date has passed -->
            <div
              v-if="nextInstallment"
              class="rounded-xl border p-4"
              :class="nextIsOverdue ? 'border-amber-300 bg-amber-50' : 'border-accent-400/40 bg-accent-50'"
            >
              <div class="flex items-center justify-between gap-3">
                <div>
                  <p class="text-xs text-gray-500 mb-0.5">{{ nextIsOverdue ? 'Вноска в забава' : 'Следваща вноска' }}</p>
                  <p class="text-base font-bold text-navy-700">{{ formatAmount(nextInstallment.total) }} €</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-semibold text-navy-700">{{ formatDate(nextInstallment.due_date) }}</p>
                  <p v-if="nextDueLabel" class="text-xs font-medium" :class="nextIsOverdue ? 'text-amber-600' : 'text-accent-500'">{{ nextDueLabel }}</p>
                </div>
              </div>
            </div>

            <!-- Progress -->
            <div v-if="summary">
              <div class="flex items-center justify-between text-sm mb-1.5">
                <span class="text-gray-500">Получено <strong class="text-navy-700">{{ formatAmount(summary.received) }} €</strong> от {{ formatAmount(summary.totalExpected) }} €</span>
                <span class="text-xs text-gray-400 shrink-0">{{ summary.paidCount }}/{{ summary.count }} вноски</span>
              </div>
              <div class="h-2.5 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full rounded-full bg-accent-400 transition-all duration-500" :style="{ width: summary.progressPct + '%' }"></div>
              </div>
              <p class="text-xs text-gray-400 mt-1.5">Обща лихва по плана: <span class="font-semibold text-green-600">+{{ formatAmount(summary.totalInterest) }} €</span></p>
            </div>

            <!-- Per-installment schedule. Principal/interest per row stay
                 visible — the investor must always see the breakdown (boss req). -->
            <div v-if="investment.schedule?.length" class="rounded-xl border border-gray-100 overflow-hidden">
              <p class="px-4 py-2.5 text-sm font-semibold text-navy-700 border-b border-gray-100 bg-gray-50/60">Вашият погасителен план</p>
              <div class="max-h-56 overflow-y-auto overflow-x-auto overscroll-contain">
                <table class="w-full text-xs">
                  <thead class="sticky top-0 bg-gray-50">
                    <tr class="text-left text-gray-400">
                      <th class="px-2 sm:px-2.5 py-2 font-medium">Дата</th>
                      <th class="px-2 sm:px-2.5 py-2 font-medium text-right">Главница</th>
                      <th class="px-2 sm:px-2.5 py-2 font-medium text-right">Лихва</th>
                      <th class="px-2 sm:px-2.5 py-2 font-medium text-right">Общо</th>
                      <th class="px-2.5 py-2 font-medium text-right hidden sm:table-cell">Статус</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(r, i) in investment.schedule" :key="i" class="border-t border-gray-50">
                      <td class="px-2 sm:px-2.5 py-2 text-gray-600 whitespace-nowrap">
                        <span
                          class="sm:hidden inline-block size-2 rounded-full mr-1.5 align-middle"
                          :class="scheduleStatusDotClasses[r.status] || 'bg-gray-300'"
                          :title="scheduleStatusLabels[r.status] || r.status"
                        ></span><span class="sm:hidden sr-only">{{ scheduleStatusLabels[r.status] || r.status }} · </span>{{ formatDate(r.due_date) }}
                      </td>
                      <td class="px-2 sm:px-2.5 py-2 text-right text-navy-700 whitespace-nowrap">{{ formatAmount(r.principal) }}</td>
                      <td class="px-2 sm:px-2.5 py-2 text-right text-accent-500 whitespace-nowrap">{{ formatAmount(r.interest) }}</td>
                      <td class="px-2 sm:px-2.5 py-2 text-right font-semibold text-navy-700 whitespace-nowrap">{{ formatAmount(r.total) }}</td>
                      <td class="px-2.5 py-2 text-right hidden sm:table-cell">
                        <span class="px-1.5 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap" :class="scheduleStatusClasses[r.status]">
                          {{ scheduleStatusLabels[r.status] || r.status }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- No per-investment schedule: pre-activation vs legacy position -->
            <p v-else class="text-xs text-gray-400 leading-relaxed">
              <template v-if="investment.loan_offer_id">
                Персоналният погасителен план се генерира при активиране на кредита.
              </template>
              <template v-else>
                Погасителният план на този кредит е достъпен на страницата на кредита.
              </template>
            </p>
          </template>
        </div>

        <!-- Actions -->
        <div class="sticky bottom-0 bg-white px-5 py-4 border-t border-gray-100 flex gap-3">
          <router-link
            :to="`/invest/${loan.id}`"
            class="flex-1 py-2.5 bg-accent-400 hover:bg-accent-500 text-white text-sm font-semibold rounded-xl text-center transition-colors"
          >
            Към кредита
          </router-link>
          <a
            v-if="investment.has_contract"
            :href="`/api/investments/${investment.id}/contract`"
            target="_blank"
            rel="noopener"
            class="flex-1 py-2.5 border border-gray-200 text-sm font-semibold text-navy-700 rounded-xl text-center hover:bg-gray-50 transition-colors"
          >
            Договор (PDF)
          </a>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* Mobile bottom-sheet slide-in — the tap visibly "opens" something. */
.investment-modal {
  animation: modal-rise 0.22s ease-out;
}

@keyframes modal-rise {
  from { transform: translateY(24px); opacity: 0.6; }
  to { transform: translateY(0); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .investment-modal {
    animation: none;
  }
}
</style>
