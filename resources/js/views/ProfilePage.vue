<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const loading = ref(true)

// Profile form
const profile = ref({ name: '', phone: '' })
const profileErrors = ref({})
const profileLoading = ref(false)
const profileSuccess = ref(false)

// Password form
const passwordForm = ref({ current_password: '', password: '', password_confirmation: '' })
const passwordErrors = ref({})
const passwordLoading = ref(false)
const passwordSuccess = ref(false)

// KYC
const kycFile = ref(null)
const kycLoading = ref(false)
const kycError = ref(null)
const kycSuccess = ref(false)

// IBANs
const ibans = ref([])
const ibanForm = ref({ iban: '', label: '' })
const ibanErrors = ref({})
const ibanLoading = ref(false)

const kycStatus = computed(() => auth.user?.kyc_status ?? 'pending')
const kycStatusLabels = { pending: 'Очаква верификация', submitted: 'Изпратен', approved: 'Верифициран', rejected: 'Отхвърлен' }
const kycStatusClasses = {
  pending: 'bg-amber-50 text-amber-600',
  submitted: 'bg-blue-50 text-blue-600',
  approved: 'bg-green-50 text-green-600',
  rejected: 'bg-red-50 text-red-600',
}

async function loadData() {
  loading.value = true
  try {
    const [profileRes, ibansRes] = await Promise.all([
      api.get('/profile'),
      api.get('/profile/ibans'),
    ])
    profile.value = { name: profileRes.data.name, phone: profileRes.data.phone || '' }
    ibans.value = ibansRes.data.data
  } finally {
    loading.value = false
  }
}

async function updateProfile() {
  profileErrors.value = {}
  profileLoading.value = true
  profileSuccess.value = false
  try {
    await api.put('/profile', profile.value)
    await auth.fetchUser()
    profileSuccess.value = true
  } catch (e) {
    if (e.response?.status === 422) profileErrors.value = e.response.data.errors || {}
  } finally {
    profileLoading.value = false
  }
}

async function changePassword() {
  passwordErrors.value = {}
  passwordLoading.value = true
  passwordSuccess.value = false
  try {
    await api.put('/profile/password', passwordForm.value)
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' }
    passwordSuccess.value = true
  } catch (e) {
    if (e.response?.status === 422) passwordErrors.value = e.response.data.errors || {}
  } finally {
    passwordLoading.value = false
  }
}

function onFileChange(e) {
  kycFile.value = e.target.files[0] || null
}

async function submitKyc() {
  if (!kycFile.value) return
  kycLoading.value = true
  kycError.value = null
  kycSuccess.value = false
  try {
    const formData = new FormData()
    formData.append('document', kycFile.value)
    await api.post('/profile/kyc', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
    kycSuccess.value = true
    await auth.fetchUser()
  } catch (e) {
    kycError.value = e.response?.data?.message || 'Грешка при изпращане.'
  } finally {
    kycLoading.value = false
  }
}

async function addIban() {
  ibanErrors.value = {}
  ibanLoading.value = true
  try {
    const { data } = await api.post('/profile/ibans', {
      iban: ibanForm.value.iban.replace(/\s/g, '').toUpperCase(),
      label: ibanForm.value.label || null,
    })
    ibans.value.unshift(data.iban)
    ibanForm.value = { iban: '', label: '' }
  } catch (e) {
    if (e.response?.status === 422) ibanErrors.value = e.response.data.errors || {}
  } finally {
    ibanLoading.value = false
  }
}

async function removeIban(id) {
  await api.delete(`/profile/ibans/${id}`)
  ibans.value = ibans.value.filter(i => i.id !== id)
}

onMounted(() => loadData())
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-navy-700 mb-1">Профил</h1>
    <p class="text-sm text-gray-500 mb-6">Управлявай данните и настройките на акаунта си</p>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <div class="grid lg:grid-cols-2 gap-6">
        <!-- Personal info -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Лични данни</h2>

          <div v-if="profileSuccess" class="rounded-xl bg-green-50 border border-green-200 p-3 text-sm text-green-700 mb-4">Промените са запазени.</div>

          <form @submit.prevent="updateProfile" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Име</label>
              <input v-model="profile.name" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="profileErrors.name ? 'border-red-400' : ''" />
              <p v-if="profileErrors.name" class="mt-1 text-xs text-red-500">{{ profileErrors.name[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Имейл</label>
              <input :value="auth.user?.email" type="email" disabled class="w-full px-4 py-2.5 rounded-xl border border-gray-100 bg-gray-50 text-sm text-gray-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Телефон</label>
              <input v-model="profile.phone" type="tel" placeholder="+359..." class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" />
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Член от</label>
              <input :value="auth.user?.created_at ? new Date(auth.user.created_at).toLocaleDateString('bg-BG') : ''" disabled class="w-full px-4 py-2.5 rounded-xl border border-gray-100 bg-gray-50 text-sm text-gray-500" />
            </div>
            <button type="submit" :disabled="profileLoading" class="w-full py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
              {{ profileLoading ? 'Запазване...' : 'Запази промените' }}
            </button>
          </form>
        </div>

        <!-- KYC -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-bold text-navy-700">Верификация (KYC)</h2>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="kycStatusClasses[kycStatus]">{{ kycStatusLabels[kycStatus] }}</span>
          </div>

          <!-- Approved -->
          <div v-if="kycStatus === 'approved'" class="text-center py-6">
            <div class="flex size-14 items-center justify-center rounded-2xl bg-green-50 text-green-500 mx-auto mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <p class="text-sm font-semibold text-green-700">Акаунтът ви е верифициран</p>
            <p class="text-xs text-gray-500 mt-1">Имате пълен достъп до всички функции на платформата.</p>
          </div>

          <!-- Submitted -->
          <div v-else-if="kycStatus === 'submitted' || kycSuccess" class="text-center py-6">
            <div class="flex size-14 items-center justify-center rounded-2xl bg-blue-50 text-blue-500 mx-auto mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </div>
            <p class="text-sm font-semibold text-blue-700">Документът е изпратен</p>
            <p class="text-xs text-gray-500 mt-1">Очаквайте одобрение в рамките на 1-2 работни дни.</p>
          </div>

          <!-- Pending / Rejected — show upload form -->
          <div v-else>
            <p class="text-sm text-gray-500 mb-4">
              {{ kycStatus === 'rejected' ? 'Документът ви е отхвърлен. Моля, изпратете нов.' : 'Качете снимка на лична карта за верификация.' }}
            </p>
            <div v-if="kycError" class="rounded-xl bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-4">{{ kycError }}</div>
            <input type="file" accept="image/*" @change="onFileChange" class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-navy-700/10 file:text-navy-700 file:font-medium file:text-sm mb-4" />
            <button @click="submitKyc" :disabled="!kycFile || kycLoading" class="w-full py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
              {{ kycLoading ? 'Изпращане...' : 'Изпрати за верификация' }}
            </button>
          </div>
        </div>

        <!-- Change password -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Промяна на парола</h2>

          <div v-if="passwordSuccess" class="rounded-xl bg-green-50 border border-green-200 p-3 text-sm text-green-700 mb-4">Паролата е променена.</div>

          <form @submit.prevent="changePassword" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Текуща парола</label>
              <input v-model="passwordForm.current_password" type="password" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="passwordErrors.current_password ? 'border-red-400' : ''" />
              <p v-if="passwordErrors.current_password" class="mt-1 text-xs text-red-500">{{ passwordErrors.current_password[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Нова парола</label>
              <input v-model="passwordForm.password" type="password" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="passwordErrors.password ? 'border-red-400' : ''" />
              <p v-if="passwordErrors.password" class="mt-1 text-xs text-red-500">{{ passwordErrors.password[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Потвърди нова парола</label>
              <input v-model="passwordForm.password_confirmation" type="password" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" />
            </div>
            <button type="submit" :disabled="passwordLoading" class="w-full py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
              {{ passwordLoading ? 'Промяна...' : 'Промени парола' }}
            </button>
          </form>
        </div>

        <!-- Saved IBANs -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">Банкови сметки</h2>

          <!-- List -->
          <div v-if="ibans.length" class="space-y-2 mb-4">
            <div v-for="iban in ibans" :key="iban.id" class="flex items-center justify-between p-3 rounded-xl bg-gray-50">
              <div>
                <p class="font-mono text-sm text-navy-700">{{ iban.iban }}</p>
                <p v-if="iban.label" class="text-xs text-gray-400 mt-0.5">{{ iban.label }}</p>
              </div>
              <button @click="removeIban(iban.id)" class="text-gray-400 hover:text-red-500 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
              </button>
            </div>
          </div>
          <p v-else class="text-sm text-gray-400 mb-4">Нямате запазени IBAN-и.</p>

          <!-- Add form -->
          <form @submit.prevent="addIban" class="space-y-3">
            <div>
              <input v-model="ibanForm.iban" type="text" placeholder="IBAN" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="ibanErrors.iban ? 'border-red-400' : ''" />
              <p v-if="ibanErrors.iban" class="mt-1 text-xs text-red-500">{{ ibanErrors.iban[0] }}</p>
            </div>
            <input v-model="ibanForm.label" type="text" placeholder="Етикет (незадължително)" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" />
            <button type="submit" :disabled="ibanLoading" class="w-full py-2.5 border border-navy-700 text-navy-700 hover:bg-navy-50 disabled:opacity-50 text-sm font-semibold rounded-xl transition-colors">
              {{ ibanLoading ? 'Добавяне...' : 'Добави IBAN' }}
            </button>
          </form>
        </div>
      </div>
    </template>
  </div>
</template>
