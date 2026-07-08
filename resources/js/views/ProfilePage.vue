<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api/axios'
import { useAuthStore } from '../stores/auth'
import { isHeicFile, validateKycFile } from '../utils/kycFile'

const router = useRouter()
const auth = useAuthStore()
const loading = ref(true)

const isLegalEntity = computed(() => auth.user?.account_type === 'legal_entity')

// Profile form (for legal entities this is the contact person)
const profile = ref({ name: '', phone: '' })
const profileErrors = ref({})
const profileLoading = ref(false)
const profileSuccess = ref(false)

// Company profile (legal entities only). legal_name + eik are read-only
// identity — shown but never sent back; only the operational fields are editable.
const company = ref({
  legal_name: '', eik: '',
  legal_form: '', vat_number: '',
  address_country: 'BG', address_city: '', address_postcode: '', address_street: '',
  company_email: '', company_phone: '',
})
const companyErrors = ref({})
const companyLoading = ref(false)
const companySuccess = ref(false)
const legalForms = { EOOD: 'ЕООД', OOD: 'ООД', AD: 'АД', EAD: 'ЕАД', ADSITZ: 'АДСИЦ', ET: 'ЕТ', KOOPERATSIYA: 'Кооперация', DRUGO: 'Друго' }

// Password form
const passwordForm = ref({ current_password: '', password: '', password_confirmation: '' })
const passwordErrors = ref({})
const passwordLoading = ref(false)
const passwordSuccess = ref(false)

// KYC — both sides of the ID card + a live selfie are required
const kycFrontFile = ref(null)
const kycBackFile = ref(null)
// Thumbnail previews of the picked photos, so the user SEES what they are
// about to submit (object URLs; PDFs get no preview — the input shows the name)
const kycFrontPreview = ref(null)
const kycBackPreview = ref(null)
const kycSelfiePreview = ref(null)
const kycSelfieFile = ref(null)
const kycBiometricConsent = ref(false)
const kycLoading = ref(false)
const kycError = ref(null)
const kycErrors = ref({})
const kycSuccess = ref(false)

// IBANs
const ibans = ref([])
const ibanForm = ref({ iban: '', label: '' })
const ibanErrors = ref({})
const ibanLoading = ref(false)

// Account deletion
const deletePassword = ref('')
const deleteErrors = ref({})
const deleteLoading = ref(false)
const showDeleteConfirm = ref(false)

const kycStatus = computed(() => auth.user?.kyc_status ?? 'pending')
const kycStatusLabels = { pending: 'Очаква верификация', submitted: 'Изпратен', in_review: 'В процес на преглед', approved: 'Верифициран', rejected: 'Отхвърлен' }
const kycStatusClasses = {
  pending: 'bg-amber-50 text-amber-600',
  submitted: 'bg-blue-50 text-blue-600',
  in_review: 'bg-indigo-50 text-indigo-600',
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

    const lep = profileRes.data.legal_entity_profile
    if (lep) {
      company.value = {
        legal_name: lep.legal_name ?? '',
        eik: lep.eik ?? '',
        legal_form: lep.legal_form ?? '',
        vat_number: lep.vat_number ?? '',
        address_country: lep.address_country ?? 'BG',
        address_city: lep.address_city ?? '',
        address_postcode: lep.address_postcode ?? '',
        address_street: lep.address_street ?? '',
        company_email: lep.company_email ?? '',
        company_phone: lep.company_phone ?? '',
      }
    }
  } finally {
    loading.value = false
  }
}

async function updateCompany() {
  companyErrors.value = {}
  companyLoading.value = true
  companySuccess.value = false
  try {
    // legal_name + eik are read-only identity — never sent back.
    const { legal_name, eik, ...editable } = company.value
    await api.put('/profile/company', editable)
    await auth.fetchUser()
    companySuccess.value = true
  } catch (e) {
    if (e.response?.status === 422) companyErrors.value = e.response.data.errors || {}
  } finally {
    companyLoading.value = false
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

function isPdf(file) {
  return file.type === 'application/pdf' || file.name?.toLowerCase().endsWith('.pdf')
}

// side → validation field name; the selfie is a plain photo upload (product
// decision 2026-07-08: no live capture — keep it easy for users), PDF not
// allowed there.
const KYC_FIELDS = { front: 'document_front', back: 'document_back', selfie: 'selfie' }

function setKycPreview(side, file) {
  const target = side === 'front' ? kycFrontPreview : side === 'back' ? kycBackPreview : kycSelfiePreview
  if (target.value) URL.revokeObjectURL(target.value)
  // No preview for PDFs (nothing to render) or HEIC (browsers render HEIC
  // blobs black or not at all — the "accepted" note in the template covers
  // it; the server converts HEIC to JPEG anyway).
  target.value = file && !isPdf(file) && !isHeicFile(file) ? URL.createObjectURL(file) : null
}

function onFileChange(e, side) {
  const field = KYC_FIELDS[side]
  const file = e.target.files[0] || null
  // Client-side pre-check (HEIC, format, 10MB) with Bulgarian messages —
  // catches the common mobile failures before burning a rate-limited request.
  const error = file ? validateKycFile(file, { allowPdf: side !== 'selfie' }) : null

  const nextErrors = { ...kycErrors.value }
  if (error) nextErrors[field] = [error]
  else delete nextErrors[field]
  kycErrors.value = nextErrors

  const accepted = error ? null : file
  if (side === 'front') kycFrontFile.value = accepted
  else if (side === 'back') kycBackFile.value = accepted
  else kycSelfieFile.value = accepted
  setKycPreview(side, accepted)
  // Reset the input on rejection so re-picking the same (now converted/smaller)
  // file still fires a change event.
  if (error) e.target.value = ''
}

onBeforeUnmount(() => {
  for (const preview of [kycFrontPreview, kycBackPreview, kycSelfiePreview]) {
    if (preview.value) URL.revokeObjectURL(preview.value)
  }
})

// 0-100 while the multipart body is uploading; 100 + kycLoading means the
// server is converting/storing (HEIC conversion takes a few seconds).
const kycUploadProgress = ref(0)

async function submitKyc() {
  if (!kycFrontFile.value || !kycBackFile.value || !kycSelfieFile.value || !kycBiometricConsent.value) return
  kycLoading.value = true
  kycUploadProgress.value = 0
  kycError.value = null
  kycErrors.value = {}
  kycSuccess.value = false
  try {
    const formData = new FormData()
    formData.append('document_front', kycFrontFile.value)
    formData.append('document_back', kycBackFile.value)
    formData.append('selfie', kycSelfieFile.value)
    formData.append('biometric_consent', kycBiometricConsent.value ? '1' : '0')
    await api.post('/profile/kyc', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (event) => {
        if (event.total) kycUploadProgress.value = Math.round((event.loaded / event.total) * 100)
      },
    })
    kycSuccess.value = true
    await auth.fetchUser()
  } catch (e) {
    if (e.response?.status === 422 && e.response.data.errors) {
      kycErrors.value = e.response.data.errors
    } else if (e.response?.status === 413) {
      // Server post-size limit hit by the AGGREGATE body — each file already
      // passed the per-file 10 MB check, so "use files up to 10 MB" would be
      // circular advice. Smaller photos are the only real way out.
      kycError.value = 'Файловете са твърде големи за изпращане наведнъж. Опитайте с по-малки снимки (например до 3–4 MB на файл).'
    } else if (e.response?.status === 429) {
      kycError.value = 'Твърде много опити за кратко време. Изчакайте една минута и опитайте отново.'
    } else {
      kycError.value = e.response?.data?.message || 'Грешка при изпращане.'
    }
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

async function deleteAccount() {
  deleteErrors.value = {}
  deleteLoading.value = true
  try {
    await api.post('/profile/delete', { password: deletePassword.value })
    auth.user = null
    router.push('/login')
  } catch (e) {
    showDeleteConfirm.value = false
    if (e.response?.status === 422) deleteErrors.value = e.response.data.errors || {}
    else deleteErrors.value = { account: ['Грешка. Опитайте отново.'] }
  } finally {
    deleteLoading.value = false
  }
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
        <!-- Company details (legal entity only) -->
        <div v-if="isLegalEntity" class="lg:col-span-2 rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-1">Фирмени данни</h2>
          <p class="text-sm text-gray-500 mb-4">Данни на юридическото лице. Името на фирмата и ЕИК са заключени — за корекция се свържете с поддръжка.</p>

          <div v-if="companySuccess" class="rounded-xl bg-green-50 border border-green-200 p-3 text-sm text-green-700 mb-4">Фирмените данни са запазени.</div>

          <form @submit.prevent="updateCompany" class="grid sm:grid-cols-2 gap-4">
            <!-- Read-only identity -->
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Име на фирмата</label>
              <input :value="company.legal_name" disabled class="w-full px-4 py-2.5 rounded-xl border border-gray-100 bg-gray-50 text-sm text-gray-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">ЕИК</label>
              <input :value="company.eik" disabled class="w-full px-4 py-2.5 rounded-xl border border-gray-100 bg-gray-50 text-sm text-gray-500 font-mono" />
            </div>

            <!-- Editable company fields -->
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Правна форма</label>
              <select v-model="company.legal_form" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.legal_form ? 'border-red-400' : ''">
                <option value="">—</option>
                <option v-for="(label, key) in legalForms" :key="key" :value="key">{{ label }}</option>
              </select>
              <p v-if="companyErrors.legal_form" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.legal_form[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">ДДС номер</label>
              <input v-model="company.vat_number" type="text" placeholder="BG123456789" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.vat_number ? 'border-red-400' : ''" />
              <p v-if="companyErrors.vat_number" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.vat_number[0] }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Държава</label>
              <input v-model="company.address_country" type="text" maxlength="2" placeholder="BG" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.address_country ? 'border-red-400' : ''" />
              <p v-if="companyErrors.address_country" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.address_country[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Град</label>
              <input v-model="company.address_city" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.address_city ? 'border-red-400' : ''" />
              <p v-if="companyErrors.address_city" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.address_city[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Пощенски код</label>
              <input v-model="company.address_postcode" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.address_postcode ? 'border-red-400' : ''" />
              <p v-if="companyErrors.address_postcode" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.address_postcode[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Адрес (улица, №)</label>
              <input v-model="company.address_street" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.address_street ? 'border-red-400' : ''" />
              <p v-if="companyErrors.address_street" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.address_street[0] }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Имейл на фирмата</label>
              <input v-model="company.company_email" type="email" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.company_email ? 'border-red-400' : ''" />
              <p v-if="companyErrors.company_email" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.company_email[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Телефон на фирмата</label>
              <input v-model="company.company_phone" type="tel" placeholder="+359..." class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="companyErrors.company_phone ? 'border-red-400' : ''" />
              <p v-if="companyErrors.company_phone" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ companyErrors.company_phone[0] }}</p>
            </div>

            <div class="sm:col-span-2">
              <button type="submit" :disabled="companyLoading" class="w-full sm:w-auto px-6 py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
                {{ companyLoading ? 'Запазване...' : 'Запази фирмените данни' }}
              </button>
            </div>
          </form>
        </div>

        <!-- Personal info (individual) / Contact person (legal entity) -->
        <div class="rounded-2xl border border-gray-100 bg-white p-6">
          <h2 class="text-base font-bold text-navy-700 mb-4">{{ isLegalEntity ? 'Контактно лице' : 'Лични данни' }}</h2>

          <div v-if="profileSuccess" class="rounded-xl bg-green-50 border border-green-200 p-3 text-sm text-green-700 mb-4">Промените са запазени.</div>

          <form @submit.prevent="updateProfile" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">{{ isLegalEntity ? 'Име на контактно лице' : 'Име' }}</label>
              <input v-model="profile.name" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="profileErrors.name ? 'border-red-400' : ''" />
              <p v-if="profileErrors.name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ profileErrors.name[0] }}</p>
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
          <div v-else-if="kycStatus === 'submitted' || kycStatus === 'in_review' || kycSuccess" class="text-center py-6">
            <div class="flex size-14 items-center justify-center rounded-2xl bg-blue-50 text-blue-500 mx-auto mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </div>
            <p class="text-sm font-semibold text-blue-700">Документът е изпратен</p>
            <p class="text-xs text-gray-500 mt-1">Очаквайте одобрение в рамките на 1-2 работни дни.</p>
          </div>

          <!-- Pending / Rejected — show upload form -->
          <div v-else>
            <p class="text-sm text-gray-500 mb-3">
              {{ kycStatus === 'rejected' ? 'Верификацията ви е отхвърлена. Моля, изпратете нови снимки и селфи.' : 'За да потвърдим самоличността ви, качете снимки на личната си карта и ваша снимка (селфи).' }}
            </p>

            <!-- All three are mandatory -->
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700 mb-4 flex gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0 mt-0.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
              <span>Необходими са <strong>три неща</strong> — лицевата страна (<strong>отпред</strong>) и гърбът (<strong>отзад</strong>) на личната карта, и <strong>ваша снимка (селфи)</strong>, на която ясно се вижда лицето ви.</span>
            </div>

            <div v-if="kycError" class="rounded-xl bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-4">{{ kycError }}</div>

            <!-- Front side -->
            <div class="mb-4">
              <label class="block text-sm font-medium text-navy-700 mb-1">1. Лицева страна на личната карта (отпред)</label>
              <!-- Explicit types instead of image/*: iOS 17+ Safari uploads raw
                   HEIC when image/* matches it, but transcodes to JPEG when the
                   accept list names only JPEG/PNG/WEBP. -->
              <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" @change="e => onFileChange(e, 'front')" class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-navy-700/10 file:text-navy-700 file:font-medium file:text-sm" />
              <p class="mt-1 text-xs text-gray-400">JPG, PNG, WEBP или PDF, до 10 MB.</p>
              <img v-if="kycFrontPreview" :src="kycFrontPreview" @error="kycFrontPreview = null" alt="Преглед — лицева страна" class="mt-2 max-h-40 rounded-xl border border-gray-200 object-contain" />
              <p v-else-if="kycFrontFile" class="mt-1 text-xs font-medium text-green-600">✓ Снимката е приета и ще бъде изпратена.</p>
              <p v-if="kycErrors.document_front" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ kycErrors.document_front[0] }}</p>
            </div>

            <!-- Back side -->
            <div class="mb-4">
              <label class="block text-sm font-medium text-navy-700 mb-1">2. Гръб на личната карта (отзад)</label>
              <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" @change="e => onFileChange(e, 'back')" class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-navy-700/10 file:text-navy-700 file:font-medium file:text-sm" />
              <p class="mt-1 text-xs text-gray-400">JPG, PNG, WEBP или PDF, до 10 MB.</p>
              <img v-if="kycBackPreview" :src="kycBackPreview" @error="kycBackPreview = null" alt="Преглед — гръб" class="mt-2 max-h-40 rounded-xl border border-gray-200 object-contain" />
              <p v-else-if="kycBackFile" class="mt-1 text-xs font-medium text-green-600">✓ Снимката е приета и ще бъде изпратена.</p>
              <p v-if="kycErrors.document_back" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ kycErrors.document_back[0] }}</p>
            </div>

            <!-- Selfie photo (plain upload — product decision: no live capture) -->
            <div class="mb-4">
              <label class="block text-sm font-medium text-navy-700 mb-1">3. Ваша снимка (селфи)</label>
              <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" @change="e => onFileChange(e, 'selfie')" class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-navy-700/10 file:text-navy-700 file:font-medium file:text-sm" />
              <p class="mt-1 text-xs text-gray-400">Ясна снимка на лицето ви — JPG, PNG или WEBP, до 10 MB.</p>
              <img v-if="kycSelfiePreview" :src="kycSelfiePreview" @error="kycSelfiePreview = null" alt="Преглед — селфи" class="mt-2 max-h-40 rounded-xl border border-gray-200 object-contain" />
              <p v-else-if="kycSelfieFile" class="mt-1 text-xs font-medium text-green-600">✓ Снимката е приета и ще бъде изпратена.</p>
              <p v-if="kycErrors.selfie" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ kycErrors.selfie[0] }}</p>
            </div>

            <!-- Explicit biometric consent (GDPR Art. 9(2)(a)) — separate, not pre-checked -->
            <label class="flex items-start gap-2.5 mb-4">
              <input v-model="kycBiometricConsent" type="checkbox" class="mt-0.5 size-4 rounded border-gray-300 text-accent-400 focus:ring-accent-400/50" />
              <span class="text-xs text-gray-500 leading-relaxed">
                Давам изрично съгласие селфито ми да бъде обработено като биометрични
                данни за лицево съпоставяне спрямо документа за самоличност с цел
                верификация (чл. 9, ал. 2, буква „а" GDPR). Подробности в
                <router-link to="/legal/privacy" target="_blank" class="text-accent-500 hover:text-accent-600 underline">Политиката за поверителност</router-link>.
              </span>
            </label>
            <p v-if="kycErrors.biometric_consent" role="alert" aria-live="polite" class="mt-1 mb-3 text-xs text-red-500">{{ kycErrors.biometric_consent[0] }}</p>

            <button @click="submitKyc" :disabled="!kycFrontFile || !kycBackFile || !kycSelfieFile || !kycBiometricConsent || kycLoading" class="w-full py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
              {{ !kycLoading ? 'Изпрати за верификация' : kycUploadProgress < 100 ? `Качване… ${kycUploadProgress}%` : 'Обработка на снимките…' }}
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
              <p v-if="passwordErrors.current_password" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ passwordErrors.current_password[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-navy-700 mb-1">Нова парола</label>
              <input v-model="passwordForm.password" type="password" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" :class="passwordErrors.password ? 'border-red-400' : ''" />
              <p v-if="passwordErrors.password" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ passwordErrors.password[0] }}</p>
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
              <p v-if="ibanErrors.iban" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ ibanErrors.iban[0] }}</p>
            </div>
            <input v-model="ibanForm.label" type="text" placeholder="Етикет (незадължително)" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400" />
            <button type="submit" :disabled="ibanLoading" class="w-full py-2.5 border border-navy-700 text-navy-700 hover:bg-navy-50 disabled:opacity-50 text-sm font-semibold rounded-xl transition-colors">
              {{ ibanLoading ? 'Добавяне...' : 'Добави IBAN' }}
            </button>
          </form>
        </div>
      </div>

      <!-- Danger zone -->
      <div class="mt-8 rounded-2xl border border-red-200 bg-red-50/50 p-6">
        <h2 class="text-base font-bold text-red-700 mb-2">Изтриване на акаунт</h2>
        <p class="text-sm text-red-600/80 mb-4">
          Тази операция е необратима. Личните ви данни ще бъдат анонимизирани. Финансовите записи се запазват за регулаторни цели.
          За да изтриете акаунта си, трябва да нямате активни инвестиции, наличен баланс или чакащи заявки.
        </p>

        <div v-if="deleteErrors.account" class="rounded-xl bg-red-100 border border-red-300 p-3 text-sm text-red-700 mb-4">{{ deleteErrors.account[0] }}</div>
        <div v-if="deleteErrors.password" class="rounded-xl bg-red-100 border border-red-300 p-3 text-sm text-red-700 mb-4">{{ deleteErrors.password[0] }}</div>

        <button @click="showDeleteConfirm = true" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
          Изтрий акаунта ми
        </button>
      </div>
    </template>

    <!-- Delete confirmation modal -->
    <Teleport to="body">
      <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black/40" @click="showDeleteConfirm = false"></div>
        <div class="relative bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl">
          <h3 class="text-lg font-bold text-red-700 mb-2">Изтриване на акаунт</h3>
          <p class="text-sm text-gray-500 mb-4">Въведете паролата си за потвърждение. Тази операция е необратима.</p>
          <input v-model="deletePassword" type="password" placeholder="Текуща парола" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm mb-4 focus:outline-none focus:ring-2 focus:ring-red-400/50 focus:border-red-400" />
          <div class="flex gap-3">
            <button @click="showDeleteConfirm = false" class="flex-1 py-2.5 border border-gray-200 text-sm font-medium text-gray-600 rounded-xl">Отказ</button>
            <button @click="deleteAccount" :disabled="deleteLoading || !deletePassword" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl">
              {{ deleteLoading ? 'Изтриване...' : 'Изтрий' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
