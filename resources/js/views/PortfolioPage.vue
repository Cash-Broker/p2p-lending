<script setup>
import { ref, computed, onMounted } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import api from '../api/axios'

ChartJS.register(ArcElement, Tooltip, Legend)

const investments = ref([])
const summary = ref(null)
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }
const statusLabels = {
  active: 'Активен',
  funding: 'Финансира се',
  funded: 'Финансиран',
  late: 'Закъснение',
  default: 'Просрочен',
  // F2 — bought_back: terminal; originator honoured the buyback guarantee,
  // investor's share distributed back to their wallet.
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

async function load(page = 1) {
  loading.value = true
  try {
    const [invRes, sumRes] = await Promise.all([
      api.get('/portfolio', { params: { page } }),
      api.get('/portfolio/summary'),
    ])
    investments.value = invRes.data.data
    meta.value = invRes.data.meta
    summary.value = sumRes.data
  } finally {
    loading.value = false
  }
}

const statusChartData = computed(() => {
  if (!summary.value) return null
  const b = summary.value.breakdown_by_status
  return {
    labels: ['Активни', 'Закъснели', 'Просрочени', 'Изкупени', 'Изплатени'],
    datasets: [{
      data: [
        parseFloat(b.active),
        parseFloat(b.late),
        parseFloat(b.default),
        parseFloat(b.bought_back ?? 0),
        parseFloat(b.repaid),
      ],
      // bought_back: blue (info) — positive outcome distinct from active-green and repaid-gray
      backgroundColor: ['#22C55E', '#F59E0B', '#EF4444', '#3B82F6', '#9CA3AF'],
      borderWidth: 0,
    }],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: '65%',
  plugins: {
    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, font: { family: 'Inter', size: 12 } } },
    tooltip: { backgroundColor: '#1B2A4A', padding: 10, cornerRadius: 8, titleFont: { family: 'Inter' }, bodyFont: { family: 'Inter' }, callbacks: { label: (ctx) => `${ctx.label}: ${parseFloat(ctx.raw).toLocaleString('bg-BG', { minimumFractionDigits: 2 })} €` } },
  },
}

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

function goToPage(page) {
  if (page >= 1 && page <= meta.value.last_page) load(page)
}

onMounted(() => load())
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-navy-700 mb-1">Портфолио</h1>
    <p class="text-sm text-gray-500 mb-6">Преглед на твоите инвестиции</p>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!investments.length && !summary?.total_invested" class="rounded-2xl border border-gray-100 bg-white p-12 text-center">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="size-12 text-gray-300 mx-auto mb-4"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z" /></svg>
      <p class="text-gray-500 text-sm mb-4">Нямате инвестиции. Разгледайте възможностите.</p>
      <router-link to="/invest" class="inline-flex px-5 py-2 bg-accent-400 text-white text-sm font-semibold rounded-xl">Към Marketplace</router-link>
    </div>

    <template v-else>
      <!-- Late / Default banner — visible only when there is something to flag.
           Educational tooltips (title attr) explain Late vs Defaulted per spec. -->
      <div
        v-if="summary && (summary.late_loans_count > 0 || summary.default_loans_count > 0)"
        class="rounded-2xl border p-4 mb-6"
        :class="summary.default_loans_count > 0
          ? 'border-red-200 bg-red-50 text-red-700'
          : 'border-amber-200 bg-amber-50 text-amber-700'"
      >
        <div class="flex items-start gap-3">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5 mt-0.5 shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <div class="text-sm">
            <p class="font-semibold mb-1">Внимание: проблеми с някои инвестиции</p>
            <p>
              <span title="Late: кредити със забавени плащания (10+ дни след падежа). Оригинаторът работи по събирането.">
                <strong>Late</strong>: <span class="font-semibold">{{ summary.late_loans_count }}</span> кредит(а)
              </span>
              ·
              <span title="Defaulted: кредити в просрочено състояние. Ако оригинаторът предлага buyback, ще бъде изкупен след административно одобрение.">
                <strong>Defaulted</strong>: <span class="font-semibold">{{ summary.default_loans_count }}</span> кредит(а)
              </span>
            </p>
          </div>
        </div>
      </div>

      <!-- F2 — positive banner for bought-back loans. Terminal state;
           investor already received their share. Info/blue color —
           distinct from the warning/danger late+default banner. -->
      <div
        v-if="summary && summary.bought_back_loans_count > 0"
        class="rounded-2xl border border-blue-200 bg-blue-50 text-blue-700 p-4 mb-6"
      >
        <div class="flex items-start gap-3">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5 mt-0.5 shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
          </svg>
          <div class="text-sm">
            <p class="font-semibold mb-1">{{ summary.bought_back_loans_count }} {{ summary.bought_back_loans_count === 1 ? 'кредит е изкупен' : 'кредита са изкупени' }} от оригинаторите</p>
            <p title="Изкупен кредит: оригинаторът плати съгласно buyback гаранцията. Средствата са във вашия свободен баланс.">
              Вашата част е върната в портфейла и може да бъде инвестирана отново или изтеглена.
            </p>
          </div>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="grid grid-cols-3 gap-4 mb-6" v-if="summary">
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Инвестирано</p>
          <p class="text-2xl font-bold text-navy-700">{{ formatAmount(summary.total_invested) }} <span class="text-sm font-medium text-gray-400">€</span></p>
        </div>
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Спечелено</p>
          <p class="text-2xl font-bold text-accent-500">{{ formatAmount(summary.total_earned) }} <span class="text-sm font-medium text-gray-400">€</span></p>
        </div>
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Активни</p>
          <p class="text-2xl font-bold text-navy-700">{{ summary.active_investments_count }}</p>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid md:grid-cols-2 gap-6 mb-8" v-if="summary">
        <!-- Status breakdown -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-sm font-bold text-navy-700 mb-4">По статус</h2>
          <div class="h-56" v-if="statusChartData">
            <Doughnut :data="statusChartData" :options="chartOptions" />
          </div>
        </div>
        <!-- Originator breakdown -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-sm font-bold text-navy-700 mb-4">По оригинатор</h2>
          <div v-if="summary.breakdown_by_originator?.length" class="space-y-3">
            <div v-for="orig in summary.breakdown_by_originator" :key="orig.name" class="flex items-center justify-between">
              <span class="text-sm text-gray-600">{{ orig.name }}</span>
              <span class="text-sm font-semibold text-navy-700">{{ formatAmount(orig.amount) }} €</span>
            </div>
          </div>
          <p v-else class="text-sm text-gray-400 text-center py-8">Няма данни</p>
        </div>
      </div>

      <!-- Investments table -->
      <div class="rounded-2xl border border-gray-100 bg-white overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
          <h2 class="text-base font-bold text-navy-700">Моите инвестиции</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
                <th class="px-6 py-3 font-medium">Кредит</th>
                <th class="px-6 py-3 font-medium hidden sm:table-cell">Оригинатор</th>
                <th class="px-6 py-3 font-medium">Сума</th>
                <th class="px-6 py-3 font-medium hidden md:table-cell">Доходност</th>
                <th class="px-6 py-3 font-medium hidden md:table-cell">Срок</th>
                <th class="px-6 py-3 font-medium">Статус</th>
                <th class="px-6 py-3 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in investments" :key="inv.id" class="border-t border-gray-50 hover:bg-gray-50/50">
                <td class="px-6 py-3">
                  <p class="font-medium text-navy-700">{{ typeLabels[inv.loan?.type] || inv.loan?.type }}</p>
                  <p class="text-xs text-gray-400">#{{ inv.loan?.id }}</p>
                </td>
                <td class="px-6 py-3 text-gray-500 hidden sm:table-cell">{{ inv.loan?.originator?.name }}</td>
                <td class="px-6 py-3 font-semibold text-navy-700">{{ formatAmount(inv.amount) }} €</td>
                <td class="px-6 py-3 text-accent-500 font-medium hidden md:table-cell">{{ inv.loan?.interest_rate }}%</td>
                <td class="px-6 py-3 text-gray-500 hidden md:table-cell">{{ inv.loan?.term_months }} мес.</td>
                <td class="px-6 py-3">
                  <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="statusClasses[inv.loan?.status]">
                    {{ statusLabels[inv.loan?.status] || inv.loan?.status }}
                  </span>
                  <!-- Days overdue badge — only when the loan is currently late
                       AND days_overdue_max is populated by the API (PortfolioController
                       eager-loads via withMax). -->
                  <span
                    v-if="inv.loan?.status === 'late' && inv.loan?.days_overdue_max != null"
                    class="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700"
                    :title="`Най-старата непогасена вноска е забавена с ${inv.loan.days_overdue_max} дни`"
                  >
                    {{ inv.loan.days_overdue_max }}д закъснение
                  </span>
                </td>
                <td class="px-6 py-3">
                  <div class="flex items-center gap-3">
                    <router-link :to="`/invest/${inv.loan?.id}`" class="text-xs text-accent-500 font-medium">Детайли</router-link>
                    <a v-if="inv.has_contract" :href="`/api/investments/${inv.id}/contract`" target="_blank" rel="noopener"
                       class="text-xs font-medium text-navy-700 underline hover:text-navy-900">Договор</a>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 py-4 border-t border-gray-100">
          <button @click="goToPage(meta.current_page - 1)" :disabled="meta.current_page === 1" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Назад</button>
          <span class="text-xs text-gray-400">{{ meta.current_page }} / {{ meta.last_page }}</span>
          <button @click="goToPage(meta.current_page + 1)" :disabled="meta.current_page === meta.last_page" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Напред</button>
        </div>
      </div>
    </template>
  </div>
</template>
