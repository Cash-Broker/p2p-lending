<script setup>
import { ref, onMounted, computed } from 'vue'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler } from 'chart.js'
import api from '../api/axios'
import EarnedTicker from '../components/EarnedTicker.vue'
import PromoPanel from '../components/PromoPanel.vue'
import { useAuthStore } from '../stores/auth'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler)

const auth = useAuthStore()
const dashboard = ref(null)
const loading = ref(true)
const error = ref(null)

async function loadDashboard() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/dashboard')
    dashboard.value = data
  } catch (e) {
    error.value = e.response?.status === 403 && e.response?.data?.requires_verification
      ? 'verification'
      : 'Неуспешно зареждане на данните. Опитайте отново.'
  } finally {
    loading.value = false
  }
}

onMounted(loadDashboard)

const wallet = computed(() => dashboard.value?.wallet ?? { available: '0.00', invested: '0.00', earned: '0.00', total: '0.00' })

const totalBalance = computed(() => wallet.value.total ?? '0.00')

const statCards = computed(() => [
  { label: 'Общо', value: totalBalance.value, icon: 'total', color: 'navy' },
  { label: 'Свободни', value: wallet.value.available, icon: 'available', color: 'accent' },
  { label: 'Инвестирани', value: wallet.value.invested, icon: 'invested', color: 'blue' },
  // «Изплатени» (2026-08-13, Reni): interest actually paid out — the live
  // «Спечелени» accrual moved to the ticker above the cards.
  { label: 'Изплатени', value: wallet.value.earned, icon: 'earned', color: 'emerald' },
])

const chartData = computed(() => {
  const months = dashboard.value?.monthly_earnings ?? []
  return {
    labels: months.map(m => {
      const [y, mo] = m.month.split('-')
      const names = ['Яну', 'Фев', 'Мар', 'Апр', 'Май', 'Юни', 'Юли', 'Авг', 'Сеп', 'Окт', 'Ное', 'Дек']
      return names[parseInt(mo) - 1]
    }),
    datasets: [
      {
        label: 'Лихви',
        data: months.map(m => parseFloat(m.interest)),
        borderColor: '#22C55E',
        backgroundColor: 'rgba(34, 197, 94, 0.08)',
        fill: true,
        tension: 0.4,
        pointRadius: 4,
        pointBackgroundColor: '#22C55E',
      },
      {
        label: 'Главници',
        data: months.map(m => parseFloat(m.principal)),
        borderColor: '#1B2A4A',
        backgroundColor: 'rgba(27, 42, 74, 0.08)',
        fill: true,
        tension: 0.4,
        pointRadius: 4,
        pointBackgroundColor: '#1B2A4A',
      },
    ],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { intersect: false, mode: 'index' },
  plugins: {
    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: { family: 'Inter', size: 12 } } },
    tooltip: { backgroundColor: '#1B2A4A', titleFont: { family: 'Inter' }, bodyFont: { family: 'Inter' }, padding: 12, cornerRadius: 8, callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} €` } },
  },
  scales: {
    x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 12 }, color: '#9ca3af' } },
    y: { grid: { color: '#f3f4f6' }, ticks: { font: { family: 'Inter', size: 12 }, color: '#9ca3af', callback: (v) => v + ' €' }, beginAtZero: true },
  },
}

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

const txTypeLabels = {
  deposit: 'Депозит',
  bonus: 'Бонус',
  withdrawal: 'Теглене',
  investment: 'Инвестиция',
  repayment_principal: 'Главница',
  repayment_interest: 'Лихва',
  buyback_principal: 'Обратно изкупуване — главница',
  buyback_interest: 'Обратно изкупуване — лихва',
  early_repayment_principal: 'Предсрочно погасяване — главница',
  early_repayment_interest: 'Предсрочно погасяване — лихва',
  interest_accrued: 'Начислена лихва',
  interest_released: 'Изплатена лихва',
  interest_accrual_reversed: 'Сторнирана начислена лихва',
  fee: 'Такса',
}

// Offers' range is the real pricing; the loan-level rate is nullable
// since 2026-08-10 (legacy loans only).
function rateDisplay(loan) {
  const r = loan.offer_rate_range
  if (r && r.length === 2) {
    return r[0] === r[1] ? `${parseFloat(r[0])}%` : `${parseFloat(r[0])}–${parseFloat(r[1])}%`
  }
  return loan.interest_rate ? `${loan.interest_rate}%` : '—'
}

// Same convention as TransactionsPage: cash-in +, cash-out -, everything
// else (accrual bookkeeping / unknown newer types) neutral without a sign.
const incomingTypes = ['deposit', 'bonus', 'repayment_principal', 'repayment_interest', 'buyback_principal', 'buyback_interest', 'early_repayment_principal', 'early_repayment_interest', 'interest_released']
const outgoingTypes = ['withdrawal', 'investment', 'fee']
</script>

<template>
  <div>
    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center py-32">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <!-- Error state -->
    <div v-else-if="error === 'verification'" class="flex flex-col items-center justify-center py-32 text-center">
      <div class="flex size-16 items-center justify-center rounded-2xl bg-amber-50 text-amber-500 mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
      </div>
      <h2 class="text-lg font-bold text-navy-700 mb-2">Потвърди имейла си</h2>
      <p class="text-sm text-gray-500 max-w-md">Трябва да потвърдиш имейл адреса си преди да можеш да използваш платформата.</p>
      <router-link to="/verify-email" class="mt-4 px-5 py-2 bg-navy-700 text-white text-sm font-semibold rounded-xl">Към верификация</router-link>
    </div>

    <div v-else-if="error" class="flex flex-col items-center justify-center py-32 text-center">
      <div class="flex size-16 items-center justify-center rounded-2xl bg-red-50 text-red-500 mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
      </div>
      <h2 class="text-lg font-bold text-navy-700 mb-2">Грешка</h2>
      <p class="text-sm text-gray-500 mb-4">{{ error }}</p>
      <button @click="loadDashboard" class="px-5 py-2 bg-navy-700 text-white text-sm font-semibold rounded-xl">Опитай отново</button>
    </div>

    <template v-else>
      <!-- Greeting + the green «Текуща печалба» ticker (top-right) -->
      <div class="mb-8 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold text-navy-700">Добре дошъл, {{ auth.user?.name?.split(' ')[0] }}</h1>
          <p class="text-sm text-gray-500 mt-1">Ето обобщение на твоя акаунт</p>
        </div>
        <EarnedTicker
          v-if="dashboard?.earned_accrual"
          :accrual="dashboard.earned_accrual"
          :lifetime="dashboard.lifetime_totals"
        />
      </div>

      <!-- Flash promo panel — renders only while a promo is running -->
      <PromoPanel />

      <!-- Stat cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div
          v-for="card in statCards"
          :key="card.label"
          class="rounded-2xl border border-gray-100 bg-white p-5"
        >
          <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-medium text-gray-400 uppercase tracking-wider">{{ card.label }}</span>
            <!-- Icons -->
            <div
              class="flex size-9 items-center justify-center rounded-lg"
              :class="{
                'bg-navy-700/10 text-navy-700': card.color === 'navy',
                'bg-accent-400/10 text-accent-500': card.color === 'accent',
                'bg-blue-100 text-blue-600': card.color === 'blue',
                'bg-emerald-100 text-emerald-600': card.color === 'emerald',
              }"
            >
              <svg v-if="card.icon === 'total'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
              <svg v-else-if="card.icon === 'available'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" /></svg>
              <svg v-else-if="card.icon === 'invested'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>
              <svg v-else-if="card.icon === 'earned'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.745 3.745 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" /></svg>
            </div>
          </div>
          <p class="text-2xl font-bold text-navy-700">{{ formatAmount(card.value) }} <span class="text-sm font-medium text-gray-400">€</span></p>
        </div>
      </div>

      <!-- Quick actions + Chart -->
      <div class="grid lg:grid-cols-3 gap-6 mb-8">
        <!-- Chart -->
        <div class="lg:col-span-2 rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Приходи от инвестиции</h2>
          <div class="h-64">
            <Line :data="chartData" :options="chartOptions" />
          </div>
        </div>

        <!-- Quick actions + stats -->
        <div class="space-y-4">
          <div class="rounded-2xl border border-gray-100 bg-white p-6">
            <h2 class="text-base font-bold text-navy-700 mb-4">Бързи действия</h2>
            <div class="space-y-3">
              <router-link to="/deposit" class="flex items-center gap-3 w-full px-4 py-3 rounded-xl bg-accent-400 hover:bg-accent-500 text-white text-sm font-semibold transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                Депозирай
              </router-link>
              <router-link to="/invest" class="flex items-center gap-3 w-full px-4 py-3 rounded-xl border border-navy-700 text-navy-700 hover:bg-navy-50 text-sm font-semibold transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                Инвестирай
              </router-link>
            </div>
          </div>

          <div class="rounded-2xl border border-gray-100 bg-white p-6">
            <div class="flex items-center justify-between mb-1">
              <span class="text-sm text-gray-500">Активни инвестиции</span>
              <span class="text-2xl font-bold text-navy-700">{{ dashboard?.active_investments_count ?? 0 }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Latest loans + Recent transactions -->
      <div class="grid lg:grid-cols-3 gap-6">
        <!-- Loans table -->
        <div class="lg:col-span-2 rounded-2xl border border-gray-100 bg-white overflow-hidden">
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-bold text-navy-700">Нови възможности</h2>
            <router-link to="/invest" class="text-xs font-medium text-accent-500 hover:text-accent-600">Виж всички</router-link>
          </div>

          <div v-if="!dashboard?.latest_loans?.length" class="px-6 py-12 text-center text-sm text-gray-400">
            Няма налични кредити в момента
          </div>

          <table v-else class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-gray-400 uppercase tracking-wider">
                <th class="px-6 py-3 font-medium">Вид</th>
                <th class="px-6 py-3 font-medium hidden sm:table-cell">Оригинатор</th>
                <th class="px-6 py-3 font-medium">Доходност</th>
                <th class="px-6 py-3 font-medium hidden md:table-cell">Срок</th>
                <th class="px-6 py-3 font-medium">Сума</th>
                <th class="px-6 py-3 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="loan in dashboard.latest_loans" :key="loan.id" class="border-t border-gray-50 hover:bg-gray-50/50">
                <td class="px-6 py-3 font-medium text-navy-700">{{ typeLabels[loan.type] || loan.type }}</td>
                <td class="px-6 py-3 text-gray-500 hidden sm:table-cell">{{ loan.originator?.name }}</td>
                <td class="px-6 py-3 font-semibold text-accent-500">{{ rateDisplay(loan) }}</td>
                <td class="px-6 py-3 text-gray-500 hidden md:table-cell">{{ loan.term_months }} мес.</td>
                <td class="px-6 py-3 text-navy-700">{{ formatAmount(loan.amount) }} €</td>
                <td class="px-6 py-3">
                  <router-link :to="`/invest/${loan.id}`" class="text-xs font-medium text-accent-500 hover:text-accent-600">Виж</router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Recent transactions -->
        <div class="rounded-2xl border border-gray-100 bg-white overflow-hidden">
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-bold text-navy-700">Последни транзакции</h2>
            <router-link to="/transactions" class="text-xs font-medium text-accent-500 hover:text-accent-600">Всички</router-link>
          </div>

          <div v-if="!dashboard?.recent_transactions?.length" class="px-6 py-12 text-center text-sm text-gray-400">
            Няма транзакции
          </div>

          <div v-else class="divide-y divide-gray-50">
            <div v-for="tx in dashboard.recent_transactions" :key="tx.id" class="px-6 py-3 flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-navy-700">{{ txTypeLabels[tx.type] || tx.type }}</p>
                <p class="text-xs text-gray-400">{{ new Date(tx.created_at).toLocaleDateString('bg-BG') }}</p>
              </div>
              <span
                class="text-sm font-semibold"
                :class="incomingTypes.includes(tx.type) ? 'text-accent-500' : 'text-navy-700'"
              >
                {{ incomingTypes.includes(tx.type) ? '+' : (outgoingTypes.includes(tx.type) ? '-' : '') }}{{ formatAmount(tx.amount) }} €
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
