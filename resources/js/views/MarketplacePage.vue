<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import api from '../api/axios'

const loans = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const activeTab = ref('active') // active, favorites

const filters = reactive({
  type: [],
  risk_class: [],
  originator_id: [],
  amount_min: '',
  amount_max: '',
  interest_rate_min: '',
  interest_rate_max: '',
  term_min: '',
  term_max: '',
  sort: 'newest',
})

const originators = ref([])
const loanTypes = [
  { value: 'consumer', label: 'Потребителски' },
  { value: 'business', label: 'Бизнес' },
  { value: 'mortgage', label: 'Ипотечен' },
  { value: 'bridge', label: 'Мостов' },
]
const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }

const riskClasses = [
  { value: 'A', label: 'A — Нисък риск', color: 'bg-green-100 text-green-700' },
  { value: 'B', label: 'B — Умерен риск', color: 'bg-lime-100 text-lime-700' },
  { value: 'C', label: 'C — Среден риск', color: 'bg-amber-100 text-amber-700' },
  { value: 'D', label: 'D — Повишен риск', color: 'bg-orange-100 text-orange-700' },
  { value: 'E', label: 'E — Висок риск', color: 'bg-red-100 text-red-700' },
]

const riskBadgeClass = {
  A: 'bg-green-100 text-green-700',
  B: 'bg-lime-100 text-lime-700',
  C: 'bg-amber-100 text-amber-700',
  D: 'bg-orange-100 text-orange-700',
  E: 'bg-red-100 text-red-700',
}

const showFilters = ref(false)

async function loadLoans(page = 1) {
  loading.value = true
  try {
    const params = { page }
    if (filters.type.length) params.type = filters.type
    if (filters.risk_class.length) params.risk_class = filters.risk_class
    if (filters.originator_id.length) params.originator_id = filters.originator_id
    if (filters.amount_min) params.amount_min = filters.amount_min
    if (filters.amount_max) params.amount_max = filters.amount_max
    if (filters.interest_rate_min) params.interest_rate_min = filters.interest_rate_min
    if (filters.interest_rate_max) params.interest_rate_max = filters.interest_rate_max
    if (filters.term_min) params.term_min = filters.term_min
    if (filters.term_max) params.term_max = filters.term_max
    if (filters.sort !== 'newest') params.sort = filters.sort

    const endpoint = activeTab.value === 'favorites' ? '/loans/favorites' : '/loans'
    const { data } = await api.get(endpoint, { params })
    loans.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

async function toggleFavorite(loan) {
  try {
    const { data } = await api.post(`/loans/${loan.id}/favorite`)
    loan._favorited = data.favorited
    if (activeTab.value === 'favorites' && !data.favorited) {
      loans.value = loans.value.filter(l => l.id !== loan.id)
    }
  } catch { /* silent */ }
}

function goToPage(page) {
  if (page >= 1 && page <= meta.value.last_page) {
    loadLoans(page)
  }
}

function resetFilters() {
  filters.type = []
  filters.risk_class = []
  filters.originator_id = []
  filters.amount_min = ''
  filters.amount_max = ''
  filters.interest_rate_min = ''
  filters.interest_rate_max = ''
  filters.term_min = ''
  filters.term_max = ''
  filters.sort = 'newest'
  loadLoans()
}

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

watch(activeTab, () => loadLoans())

onMounted(async () => {
  // Load originators for filter
  try {
    const { data } = await api.get('/loans', { params: { per_page: 1 } })
    // Extract unique originators from future endpoint; for now use loans
  } catch { /* */ }
  loadLoans()
})
</script>

<template>
  <div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
      <div>
        <h1 class="text-2xl font-bold text-navy-700">Инвестиране</h1>
        <p class="text-sm text-gray-500 mt-1">Разгледай налични кредити и инвестирай</p>
      </div>
      <button
        @click="showFilters = !showFilters"
        class="inline-flex items-center gap-2 px-4 py-2 border border-gray-200 rounded-xl text-sm font-medium text-navy-700 hover:bg-gray-50 transition-colors"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" /></svg>
        Филтри
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 mb-6 bg-gray-100 rounded-xl p-1 w-fit">
      <button
        v-for="tab in [{ key: 'active', label: 'Активни' }, { key: 'favorites', label: 'Любими' }]"
        :key="tab.key"
        @click="activeTab = tab.key"
        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
        :class="activeTab === tab.key ? 'bg-white text-navy-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Filters panel -->
    <div v-if="showFilters" class="rounded-2xl border border-gray-100 bg-white p-5 mb-6">
      <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <!-- Type -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">Вид кредит</p>
          <label v-for="t in loanTypes" :key="t.value" class="flex items-center gap-2 mb-1.5">
            <input type="checkbox" :value="t.value" v-model="filters.type" class="size-4 rounded border-gray-300 text-accent-400" />
            <span class="text-sm text-gray-700">{{ t.label }}</span>
          </label>
        </div>
        <!-- Risk class -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">Рисков клас</p>
          <label v-for="r in riskClasses" :key="r.value" class="flex items-center gap-2 mb-1.5">
            <input type="checkbox" :value="r.value" v-model="filters.risk_class" class="size-4 rounded border-gray-300 text-accent-400" />
            <span class="text-sm text-gray-700">{{ r.label }}</span>
          </label>
        </div>
        <!-- Amount -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">Сума (€)</p>
          <div class="flex gap-2">
            <input v-model="filters.amount_min" type="number" placeholder="от" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
            <input v-model="filters.amount_max" type="number" placeholder="до" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
          </div>
        </div>
        <!-- Interest -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">Доходност (%)</p>
          <div class="flex gap-2">
            <input v-model="filters.interest_rate_min" type="number" step="0.1" placeholder="от" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
            <input v-model="filters.interest_rate_max" type="number" step="0.1" placeholder="до" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
          </div>
        </div>
        <!-- Term -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wider">Срок (месеци)</p>
          <div class="flex gap-2">
            <input v-model="filters.term_min" type="number" placeholder="от" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
            <input v-model="filters.term_max" type="number" placeholder="до" class="w-full px-3 py-1.5 rounded-lg border border-gray-200 text-sm" />
          </div>
        </div>
      </div>
      <div class="flex items-center gap-3 mt-4 pt-4 border-t border-gray-100">
        <!-- Sort -->
        <select v-model="filters.sort" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-700">
          <option value="newest">Най-нови</option>
          <option value="highest_rate">Най-висока доходност</option>
          <option value="shortest_term">Най-кратък срок</option>
          <option value="most_funded">Най-финансирани</option>
        </select>
        <button @click="loadLoans()" class="px-4 py-1.5 bg-navy-700 text-white text-sm font-medium rounded-lg">Приложи</button>
        <button @click="resetFilters" class="px-4 py-1.5 border border-gray-200 text-sm font-medium text-gray-600 rounded-lg">Изчисти</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!loans.length" class="rounded-2xl border border-gray-100 bg-white p-12 text-center">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="size-12 text-gray-300 mx-auto mb-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm3.75 11.625a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
      <p class="text-gray-500 text-sm">{{ activeTab === 'favorites' ? 'Нямаш любими кредити.' : 'Няма налични кредити с тези филтри.' }}</p>
    </div>

    <!-- Desktop table -->
    <div v-else class="hidden lg:block rounded-2xl border border-gray-100 bg-white overflow-hidden mb-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
            <th class="px-5 py-3 font-medium">ID</th>
            <th class="px-5 py-3 font-medium">Вид</th>
            <th class="px-5 py-3 font-medium">Риск</th>
            <th class="px-5 py-3 font-medium">Оригинатор</th>
            <th class="px-5 py-3 font-medium">Доходност</th>
            <th class="px-5 py-3 font-medium">Срок</th>
            <th class="px-5 py-3 font-medium">Сума</th>
            <th class="px-5 py-3 font-medium">Прогрес</th>
            <th class="px-5 py-3 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="loan in loans" :key="loan.id" class="border-t border-gray-50 hover:bg-gray-50/50">
            <td class="px-5 py-3 text-gray-400">#{{ loan.id }}</td>
            <td class="px-5 py-3 font-medium text-navy-700">{{ typeLabels[loan.type] || loan.type }}</td>
            <td class="px-5 py-3">
              <span v-if="loan.anonymized_profile?.risk_class" class="px-2 py-0.5 rounded-full text-xs font-bold" :class="riskBadgeClass[loan.anonymized_profile.risk_class]">{{ loan.anonymized_profile.risk_class }}</span>
            </td>
            <td class="px-5 py-3 text-gray-500">{{ loan.originator?.name }}</td>
            <td class="px-5 py-3 font-semibold text-accent-500">{{ loan.interest_rate }}%</td>
            <td class="px-5 py-3 text-gray-500">{{ loan.term_months }} мес.</td>
            <td class="px-5 py-3 text-navy-700">{{ formatAmount(loan.amount) }} €</td>
            <td class="px-5 py-3">
              <div class="flex items-center gap-2">
                <div class="w-20 h-1.5 rounded-full bg-gray-100">
                  <div class="h-full rounded-full bg-accent-400" :style="{ width: loan.funded_percentage + '%' }"></div>
                </div>
                <span class="text-xs text-gray-400">{{ loan.funded_percentage }}%</span>
              </div>
            </td>
            <td class="px-5 py-3">
              <div class="flex items-center gap-2">
                <button @click="toggleFavorite(loan)" class="text-gray-300 hover:text-amber-400 transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" :fill="loan._favorited ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5" :class="loan._favorited ? 'text-amber-400' : ''"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" /></svg>
                </button>
                <router-link :to="`/invest/${loan.id}`" class="px-3 py-1 bg-navy-700 text-white text-xs font-medium rounded-lg hover:bg-navy-600 transition-colors">Отвори</router-link>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div v-if="!loading && loans.length" class="lg:hidden space-y-3 mb-6">
      <div v-for="loan in loans" :key="loan.id" class="rounded-2xl border border-gray-100 bg-white p-4">
        <div class="flex items-start justify-between mb-3">
          <div>
            <div class="flex items-center gap-2">
              <p class="font-semibold text-navy-700">{{ typeLabels[loan.type] || loan.type }}</p>
              <span v-if="loan.anonymized_profile?.risk_class" class="px-1.5 py-0.5 rounded text-[10px] font-bold" :class="riskBadgeClass[loan.anonymized_profile.risk_class]">{{ loan.anonymized_profile.risk_class }}</span>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">{{ loan.originator?.name }} · #{{ loan.id }}</p>
          </div>
          <span class="text-lg font-bold text-accent-500">{{ loan.interest_rate }}%</span>
        </div>
        <div class="grid grid-cols-3 gap-3 text-center py-3 border-y border-gray-100 mb-3">
          <div>
            <p class="text-xs text-gray-400">Сума</p>
            <p class="text-sm font-semibold text-navy-700">{{ formatAmount(loan.amount) }} €</p>
          </div>
          <div>
            <p class="text-xs text-gray-400">Срок</p>
            <p class="text-sm font-semibold text-navy-700">{{ loan.term_months }} мес.</p>
          </div>
          <div>
            <p class="text-xs text-gray-400">Прогрес</p>
            <p class="text-sm font-semibold text-navy-700">{{ loan.funded_percentage }}%</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <router-link :to="`/invest/${loan.id}`" class="flex-1 text-center px-3 py-2 bg-navy-700 text-white text-sm font-medium rounded-xl">Отвори</router-link>
          <button @click="toggleFavorite(loan)" class="px-3 py-2 border border-gray-200 rounded-xl" :class="loan._favorited ? 'text-amber-400' : 'text-gray-300'">
            <svg xmlns="http://www.w3.org/2000/svg" :fill="loan._favorited ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" /></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && meta.last_page > 1" class="flex items-center justify-center gap-2">
      <button @click="goToPage(meta.current_page - 1)" :disabled="meta.current_page === 1" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm disabled:opacity-30">Назад</button>
      <span class="text-sm text-gray-500">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button @click="goToPage(meta.current_page + 1)" :disabled="meta.current_page === meta.last_page" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm disabled:opacity-30">Напред</button>
    </div>
  </div>
</template>
