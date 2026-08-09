<script setup>
import { ref, reactive, onMounted } from 'vue'
import api from '../api/axios'

const transactions = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)

const filters = reactive({
  type: [],
  date_from: '',
  date_to: '',
})

const txTypes = [
  { value: 'deposit', label: 'Депозит' },
  { value: 'bonus', label: 'Бонус' },
  { value: 'withdrawal', label: 'Теглене' },
  { value: 'investment', label: 'Инвестиция' },
  { value: 'repayment_principal', label: 'Главница' },
  { value: 'repayment_interest', label: 'Лихва' },
  { value: 'fee', label: 'Такса' },
]

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

const txTypeClasses = {
  deposit: 'bg-green-50 text-green-600',
  bonus: 'bg-amber-50 text-amber-600',
  withdrawal: 'bg-red-50 text-red-600',
  investment: 'bg-blue-50 text-blue-600',
  repayment_principal: 'bg-green-50 text-green-600',
  repayment_interest: 'bg-emerald-50 text-emerald-600',
  buyback_principal: 'bg-green-50 text-green-600',
  buyback_interest: 'bg-emerald-50 text-emerald-600',
  early_repayment_principal: 'bg-green-50 text-green-600',
  early_repayment_interest: 'bg-emerald-50 text-emerald-600',
  interest_accrued: 'bg-sky-50 text-sky-600',
  interest_released: 'bg-emerald-50 text-emerald-600',
  interest_accrual_reversed: 'bg-gray-100 text-gray-500',
  fee: 'bg-gray-100 text-gray-500',
}

// Cash-in rows show +green, cash-out rows -red. Types in NEITHER list
// (accrual bookkeeping, or a type this bundle predates) render neutral —
// an unknown credit must never look like money taken.
const incomingTypes = ['deposit', 'bonus', 'repayment_principal', 'repayment_interest', 'buyback_principal', 'buyback_interest', 'early_repayment_principal', 'early_repayment_interest', 'interest_released']
const outgoingTypes = ['withdrawal', 'investment', 'fee']

async function load(page = 1) {
  loading.value = true
  try {
    const params = { page }
    if (filters.type.length) params.type = filters.type
    if (filters.date_from) params.date_from = filters.date_from
    if (filters.date_to) params.date_to = filters.date_to

    const { data } = await api.get('/transactions', { params })
    transactions.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  load(1)
}

function clearFilters() {
  filters.type = []
  filters.date_from = ''
  filters.date_to = ''
  load(1)
}

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

function formatDate(date) {
  return new Date(date).toLocaleDateString('bg-BG', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function goToPage(page) {
  if (page >= 1 && page <= meta.value.last_page) load(page)
}

onMounted(() => load())
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-navy-700 mb-1">Транзакции</h1>
    <p class="text-sm text-gray-500 mb-6">История на всички финансови операции</p>

    <!-- Filters -->
    <div class="rounded-2xl border border-gray-100 bg-white p-5 mb-6">
      <div class="grid sm:grid-cols-3 gap-4">
        <!-- Type filter -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">Тип</p>
          <div class="space-y-1.5">
            <label v-for="t in txTypes" :key="t.value" class="flex items-center gap-2">
              <input type="checkbox" :value="t.value" v-model="filters.type" class="size-4 rounded border-gray-300 text-accent-400" />
              <span class="text-sm text-gray-700">{{ t.label }}</span>
            </label>
          </div>
        </div>
        <!-- Date filters -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">От дата</p>
          <input v-model="filters.date_from" type="date" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
        </div>
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">До дата</p>
          <input v-model="filters.date_to" type="date" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
        </div>
      </div>
      <div class="flex gap-3 mt-4 pt-4 border-t border-gray-100">
        <button @click="applyFilters" class="px-4 py-1.5 bg-navy-700 text-white text-sm font-medium rounded-lg">Приложи</button>
        <button @click="clearFilters" class="px-4 py-1.5 border border-gray-200 text-sm font-medium text-gray-600 rounded-lg">Изчисти</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!transactions.length" class="rounded-2xl border border-gray-100 bg-white p-12 text-center">
      <p class="text-gray-400 text-sm">Няма транзакции за показване.</p>
    </div>

    <!-- Table -->
    <div v-else class="rounded-2xl border border-gray-100 bg-white overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
            <th class="px-6 py-3 font-medium">Дата</th>
            <th class="px-6 py-3 font-medium">Тип</th>
            <th class="px-6 py-3 font-medium">Сума</th>
            <th class="px-6 py-3 font-medium hidden sm:table-cell">Описание</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tx in transactions" :key="tx.id" class="border-t border-gray-50">
            <td class="px-6 py-3 text-gray-600">{{ formatDate(tx.created_at) }}</td>
            <td class="px-6 py-3">
              <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="txTypeClasses[tx.type]">
                {{ txTypeLabels[tx.type] || tx.type }}
              </span>
            </td>
            <td class="px-6 py-3 font-semibold" :class="incomingTypes.includes(tx.type) ? 'text-green-600' : (outgoingTypes.includes(tx.type) ? 'text-red-600' : 'text-gray-600')">
              {{ incomingTypes.includes(tx.type) ? '+' : (outgoingTypes.includes(tx.type) ? '-' : '') }}{{ formatAmount(tx.amount) }} €
            </td>
            <td class="px-6 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ tx.description || '—' }}</td>
          </tr>
        </tbody>
      </table>

      <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 py-4 border-t border-gray-100">
        <button @click="goToPage(meta.current_page - 1)" :disabled="meta.current_page === 1" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Назад</button>
        <span class="text-xs text-gray-400">{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button @click="goToPage(meta.current_page + 1)" :disabled="meta.current_page === meta.last_page" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Напред</button>
      </div>
    </div>
  </div>
</template>
