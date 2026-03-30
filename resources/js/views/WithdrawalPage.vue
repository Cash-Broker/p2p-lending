<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const loading = ref(true)
const withdrawals = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })

const form = ref({ amount: '', iban: '' })
const errors = ref({})
const submitLoading = ref(false)
const showConfirm = ref(false)
const success = ref(false)

const availableBalance = computed(() => auth.user?.wallet?.available ?? '0.00')
const isKycApproved = computed(() => auth.user?.kyc_status === 'approved')

const totalWithdrawn = computed(() => {
  return withdrawals.value
    .filter(w => w.status === 'approved' || w.status === 'processed')
    .reduce((sum, w) => sum + parseFloat(w.amount), 0)
    .toFixed(2)
})

const completedCount = computed(() => withdrawals.value.filter(w => w.status !== 'pending').length)

async function loadHistory(page = 1) {
  try {
    const { data } = await api.get('/withdrawal/history', { params: { page } })
    withdrawals.value = data.data
    meta.value = data.meta
  } catch { /* KYC not approved — no history */ }
}

async function load() {
  loading.value = true
  await loadHistory()
  loading.value = false
}

function openConfirm() {
  errors.value = {}
  if (!form.value.amount || parseFloat(form.value.amount) < 10) {
    errors.value = { amount: ['Минималната сума е 10.00 €.'] }
    return
  }
  if (parseFloat(form.value.amount) > parseFloat(availableBalance.value)) {
    errors.value = { amount: ['Недостатъчен свободен баланс.'] }
    return
  }
  if (!form.value.iban || form.value.iban.replace(/\s/g, '').length < 15) {
    errors.value = { iban: ['Въведете валиден IBAN.'] }
    return
  }
  showConfirm.value = true
}

async function submitWithdrawal() {
  submitLoading.value = true
  errors.value = {}
  try {
    await api.post('/withdrawal', {
      amount: form.value.amount,
      iban: form.value.iban.replace(/\s/g, '').toUpperCase(),
    })
    success.value = true
    form.value = { amount: '', iban: '' }
    showConfirm.value = false
    await auth.fetchUser()
    await loadHistory()
  } catch (e) {
    showConfirm.value = false
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else if (e.response?.status === 403) {
      errors.value = { amount: ['KYC верификация необходима за теглене.'] }
    } else {
      errors.value = { amount: ['Грешка. Опитайте отново.'] }
    }
  } finally {
    submitLoading.value = false
  }
}

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

const statusLabels = { pending: 'Изчакване', approved: 'Одобрен', rejected: 'Отхвърлен', processed: 'Обработен' }
const statusClasses = {
  pending: 'bg-amber-50 text-amber-600',
  approved: 'bg-green-50 text-green-600',
  rejected: 'bg-red-50 text-red-600',
  processed: 'bg-blue-50 text-blue-600',
}

onMounted(() => load())
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-navy-700 mb-1">Теглене</h1>
    <p class="text-sm text-gray-500 mb-6">Изтегли средства към банковата си сметка</p>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Stat cards -->
      <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Обработени тегления</p>
          <p class="text-2xl font-bold text-navy-700">{{ completedCount }}</p>
        </div>
        <div class="rounded-2xl border border-gray-100 bg-white p-5">
          <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Обща сума</p>
          <p class="text-2xl font-bold text-navy-700">{{ formatAmount(totalWithdrawn) }} <span class="text-sm font-medium text-gray-400">€</span></p>
        </div>
      </div>

      <div class="grid lg:grid-cols-3 gap-6 mb-8">
        <!-- Withdrawal form -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Заяви теглене</h2>

          <div v-if="success" class="text-center py-4">
            <div class="flex size-14 items-center justify-center rounded-2xl bg-accent-50 text-accent-500 mx-auto mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <p class="text-sm font-semibold text-navy-700 mb-1">Заявката е подадена!</p>
            <p class="text-xs text-gray-500 mb-4">Ще бъде обработена в рамките на 1-2 работни дни.</p>
            <button @click="success = false" class="text-sm text-accent-500 font-medium">Ново теглене</button>
          </div>

          <template v-else>
            <div class="flex items-center justify-between text-sm mb-4 p-3 rounded-xl bg-gray-50">
              <span class="text-gray-500">Свободен баланс</span>
              <span class="font-semibold text-navy-700">{{ formatAmount(availableBalance) }} €</span>
            </div>

            <form @submit.prevent="openConfirm" class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-navy-700 mb-1">Сума (€)</label>
                <input
                  v-model="form.amount"
                  type="number"
                  min="10"
                  step="0.01"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                  :class="errors.amount ? 'border-red-400' : ''"
                  placeholder="мин. 10.00"
                />
                <p v-if="errors.amount" class="mt-1 text-xs text-red-500">{{ errors.amount[0] }}</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-navy-700 mb-1">IBAN</label>
                <input
                  v-model="form.iban"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                  :class="errors.iban ? 'border-red-400' : ''"
                  placeholder="BG80BNBG96611020345678"
                />
                <p v-if="errors.iban" class="mt-1 text-xs text-red-500">{{ errors.iban[0] }}</p>
              </div>

              <button
                type="submit"
                :disabled="submitLoading || !isKycApproved"
                class="w-full py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
              >
                {{ !isKycApproved ? 'KYC верификация необходима' : submitLoading ? 'Обработка...' : 'Заяви теглене' }}
              </button>
            </form>
          </template>
        </div>

        <!-- History -->
        <div class="lg:col-span-2 rounded-2xl border border-gray-100 bg-white overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-bold text-navy-700">История на тегленията</h2>
          </div>

          <div v-if="!withdrawals.length" class="px-6 py-12 text-center text-sm text-gray-400">
            Все още нямате тегления.
          </div>

          <table v-else class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
                <th class="px-6 py-3 font-medium">Дата</th>
                <th class="px-6 py-3 font-medium">Сума</th>
                <th class="px-6 py-3 font-medium hidden sm:table-cell">IBAN</th>
                <th class="px-6 py-3 font-medium">Статус</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in withdrawals" :key="w.id" class="border-t border-gray-50">
                <td class="px-6 py-3 text-gray-600">{{ new Date(w.created_at).toLocaleDateString('bg-BG') }}</td>
                <td class="px-6 py-3 font-semibold text-navy-700">{{ formatAmount(w.amount) }} €</td>
                <td class="px-6 py-3 text-gray-400 font-mono text-xs hidden sm:table-cell">{{ w.iban }}</td>
                <td class="px-6 py-3">
                  <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="statusClasses[w.status]">{{ statusLabels[w.status] }}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 py-4 border-t border-gray-100">
            <button @click="loadHistory(meta.current_page - 1)" :disabled="meta.current_page === 1" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Назад</button>
            <span class="text-xs text-gray-400">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <button @click="loadHistory(meta.current_page + 1)" :disabled="meta.current_page === meta.last_page" class="px-3 py-1 rounded-lg border border-gray-200 text-xs disabled:opacity-30">Напред</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Confirmation modal -->
    <Teleport to="body">
      <div v-if="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black/40" @click="showConfirm = false"></div>
        <div class="relative bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl">
          <h3 class="text-lg font-bold text-navy-700 mb-2">Потвърди теглене</h3>
          <p class="text-sm text-gray-500 mb-6">
            Сигурни ли сте, че искате да изтеглите
            <strong class="text-navy-700">{{ formatAmount(form.amount) }} €</strong>
            към <strong class="text-navy-700 font-mono text-xs">{{ form.iban }}</strong>?
          </p>
          <div class="flex gap-3">
            <button @click="showConfirm = false" class="flex-1 py-2.5 border border-gray-200 text-sm font-medium text-gray-600 rounded-xl">Отказ</button>
            <button @click="submitWithdrawal" :disabled="submitLoading" class="flex-1 py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl">
              {{ submitLoading ? 'Обработка...' : 'Потвърди' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
