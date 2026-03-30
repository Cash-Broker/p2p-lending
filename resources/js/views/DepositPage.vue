<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const loading = ref(true)
const depositInfo = ref(null)
const deposits = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const copied = ref(false)

const isKycApproved = computed(() => auth.user?.kyc_status === 'approved')

const totalDeposited = computed(() => {
  return deposits.value
    .filter(d => d.status === 'approved')
    .reduce((sum, d) => sum + parseFloat(d.amount), 0)
    .toFixed(2)
})

const approvedCount = computed(() => deposits.value.filter(d => d.status === 'approved').length)

async function load(page = 1) {
  loading.value = true
  try {
    const [infoRes, historyRes] = await Promise.all([
      api.get('/deposit'),
      api.get('/deposit/history', { params: { page } }),
    ])
    depositInfo.value = infoRes.data
    deposits.value = historyRes.data.data
    meta.value = historyRes.data.meta
  } finally {
    loading.value = false
  }
}

function copyRef() {
  navigator.clipboard.writeText(depositInfo.value?.reference_code || '')
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

const statusLabels = { pending: 'Изчакване', approved: 'Одобрен', rejected: 'Отхвърлен' }
const statusClasses = {
  pending: 'bg-amber-50 text-amber-600',
  approved: 'bg-green-50 text-green-600',
  rejected: 'bg-red-50 text-red-600',
}

onMounted(() => load())
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-navy-700 mb-1">Депозиране</h1>
    <p class="text-sm text-gray-500 mb-6">Захрани акаунта си чрез банков превод</p>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- KYC warning -->
      <div v-if="!isKycApproved" class="rounded-2xl bg-amber-50 border border-amber-200 p-4 flex items-center gap-3 mb-6">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 text-amber-500 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
        <div class="flex-1">
          <p class="text-sm font-medium text-amber-800">KYC верификация необходима</p>
          <p class="text-xs text-amber-600 mt-0.5">Депозитите ще бъдат обработени след одобрение на вашата верификация.</p>
        </div>
        <router-link to="/profile" class="px-3 py-1.5 bg-amber-500 text-white text-xs font-medium rounded-lg shrink-0">Верификация</router-link>
      </div>

      <!-- Stat cards -->
      <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Одобрени депозити</p>
          <p class="text-2xl font-bold text-navy-700">{{ approvedCount }}</p>
        </div>
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Обща сума</p>
          <p class="text-2xl font-bold text-navy-700">{{ formatAmount(totalDeposited) }} <span class="text-sm font-medium text-gray-400">€</span></p>
        </div>
      </div>

      <!-- Bank details + Instructions -->
      <div class="grid md:grid-cols-2 gap-6 mb-8">
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Как да депозирате</h2>
          <ol class="space-y-3 text-sm text-gray-600">
            <li class="flex gap-3">
              <span class="flex size-6 items-center justify-center rounded-full bg-navy-700 text-white text-xs font-bold shrink-0">1</span>
              Направете банков превод към посочената сметка
            </li>
            <li class="flex gap-3">
              <span class="flex size-6 items-center justify-center rounded-full bg-navy-700 text-white text-xs font-bold shrink-0">2</span>
              Използвайте вашия reference код в основанието на превода
            </li>
            <li class="flex gap-3">
              <span class="flex size-6 items-center justify-center rounded-full bg-navy-700 text-white text-xs font-bold shrink-0">3</span>
              Средствата се отразяват след потвърждение от нашия екип (до 1 работен ден)
            </li>
          </ol>
        </div>

        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Банкови реквизити</h2>
          <div class="space-y-3 text-sm" v-if="depositInfo">
            <div class="flex justify-between py-2 border-b border-gray-50">
              <span class="text-gray-400">Банка</span>
              <span class="font-medium text-navy-700">{{ depositInfo.bank_details.bank_name }}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-50">
              <span class="text-gray-400">IBAN</span>
              <span class="font-medium text-navy-700 font-mono text-xs">{{ depositInfo.bank_details.iban }}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-50">
              <span class="text-gray-400">BIC</span>
              <span class="font-medium text-navy-700">{{ depositInfo.bank_details.bic }}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-50">
              <span class="text-gray-400">Получател</span>
              <span class="font-medium text-navy-700">{{ depositInfo.bank_details.beneficiary }}</span>
            </div>
            <div class="flex justify-between items-center py-2">
              <span class="text-gray-400">Reference код</span>
              <div class="flex items-center gap-2">
                <span class="font-bold text-accent-500 font-mono">{{ depositInfo.reference_code }}</span>
                <button @click="copyRef" class="px-2 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                  {{ copied ? 'Копирано!' : 'Копирай' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- History -->
      <div class="rounded-2xl border border-gray-100 bg-white overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
          <h2 class="text-base font-bold text-navy-700">История на депозитите</h2>
        </div>

        <div v-if="!deposits.length" class="px-6 py-12 text-center text-sm text-gray-400">
          Все още нямате депозити.
        </div>

        <table v-else class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
              <th class="px-6 py-3 font-medium">Дата</th>
              <th class="px-6 py-3 font-medium">Сума</th>
              <th class="px-6 py-3 font-medium hidden sm:table-cell">Reference</th>
              <th class="px-6 py-3 font-medium">Статус</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="dep in deposits" :key="dep.id" class="border-t border-gray-50">
              <td class="px-6 py-3 text-gray-600">{{ new Date(dep.created_at).toLocaleDateString('bg-BG') }}</td>
              <td class="px-6 py-3 font-semibold text-navy-700">{{ formatAmount(dep.amount) }} €</td>
              <td class="px-6 py-3 text-gray-400 font-mono text-xs hidden sm:table-cell">{{ dep.reference_code }}</td>
              <td class="px-6 py-3">
                <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="statusClasses[dep.status]">{{ statusLabels[dep.status] }}</span>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 py-4 border-t border-gray-100">
          <button @click="load(meta.current_page - 1)" :disabled="meta.current_page === 1" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Назад</button>
          <span class="text-xs text-gray-400">{{ meta.current_page }} / {{ meta.last_page }}</span>
          <button @click="load(meta.current_page + 1)" :disabled="meta.current_page === meta.last_page" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Напред</button>
        </div>
      </div>
    </template>
  </div>
</template>
