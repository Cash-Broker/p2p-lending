<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const LEGAL_FORMS = [
  { value: 'EOOD',         label: 'ЕООД' },
  { value: 'OOD',          label: 'ООД' },
  { value: 'AD',           label: 'АД' },
  { value: 'EAD',          label: 'ЕАД' },
  { value: 'ADSITZ',       label: 'АДСИЦ' },
  { value: 'ET',           label: 'ЕТ' },
  { value: 'KOOPERATSIYA', label: 'Кооперация' },
  { value: 'DRUGO',        label: 'Друго' },
]

const REPRESENTATIVE_ROLES = [
  { value: 'upravitel',      label: 'Управител' },
  { value: 'prokurist',      label: 'Прокурист' },
  { value: 'upalnomoshteno', label: 'Упълномощено лице' },
]

const SOURCES_OF_FUNDS = [
  { value: 'business_income', label: 'Доходи от стопанска дейност' },
  { value: 'dividends',       label: 'Дивиденти' },
  { value: 'asset_sale',      label: 'Продажба на актив' },
  { value: 'loan',            label: 'Заем' },
  { value: 'other',           label: 'Друго' },
]

const CONTROL_TYPES = [
  { value: 'direct',   label: 'Пряк (≥25% дялове/акции)' },
  { value: 'indirect', label: 'Косвен (чрез друга структура)' },
  { value: 'other',    label: 'Друг (договор / гласуване)' },
]

function emptyUbo() {
  return {
    full_name: '',
    national_id: '',
    date_of_birth: '',
    nationality: 'BG',
    ownership_percent: '',
    control_type: 'direct',
    pep_status: false,
    pep_details: '',
  }
}

const form = ref({
  account_type: 'individual',

  // Common (also used as representative name when legal_entity)
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  terms_accepted: false,

  // Legal entity
  legal_name: '',
  legal_form: 'EOOD',
  eik: '',
  vat_number: '',
  address_country: 'BG',
  address_city: '',
  address_postcode: '',
  address_street: '',
  company_email: '',
  company_phone: '',
  representative_role: 'upravitel',
  representative_egn: '',
  representative_dob: '',
  pep_status: false,
  pep_details: '',
  source_of_funds: 'business_income',
  source_of_funds_other: '',
  beneficial_owners: [emptyUbo()],
})

const errors = ref({})
const loading = ref(false)
const isLegal = computed(() => form.value.account_type === 'legal_entity')

function addUbo() {
  if (form.value.beneficial_owners.length >= 20) return
  form.value.beneficial_owners.push(emptyUbo())
}

function removeUbo(idx) {
  if (form.value.beneficial_owners.length <= 1) return
  form.value.beneficial_owners.splice(idx, 1)
}

function uboError(idx, field) {
  return errors.value[`beneficial_owners.${idx}.${field}`]?.[0]
}

async function submit() {
  errors.value = {}
  loading.value = true
  try {
    const payload = { ...form.value }
    // Strip legal-entity fields for individual registrations — keeps the
    // request body small and avoids sending unused PII.
    if (!isLegal.value) {
      const keep = ['account_type', 'name', 'email', 'password', 'password_confirmation', 'terms_accepted']
      Object.keys(payload).forEach(k => { if (!keep.includes(k)) delete payload[k] })
    }
    await auth.register(payload)
    router.push('/verify-email')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      errors.value = { email: ['Възникна грешка. Опитайте отново.'] }
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-start justify-center bg-gray-50 px-4 py-12">
    <div class="w-full max-w-2xl">
      <div class="text-center mb-8">
        <router-link to="/" class="inline-flex items-center gap-2">
          <div class="flex size-10 items-center justify-center rounded-xl bg-navy-700 text-white text-sm font-bold">V</div>
          <span class="text-xl font-bold text-navy-700">Vamaa<span class="text-accent-400">sset</span></span>
        </router-link>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-8">
        <h1 class="text-2xl font-bold text-navy-700 mb-1">Регистрация</h1>
        <p class="text-sm text-gray-500 mb-6">Създай акаунт и започни да инвестираш</p>

        <!-- Account type toggle -->
        <div class="grid grid-cols-2 gap-2 mb-6 p-1 bg-gray-50 rounded-xl">
          <button
            type="button"
            @click="form.account_type = 'individual'"
            :class="!isLegal ? 'bg-white shadow-sm text-navy-700' : 'text-gray-500 hover:text-navy-700'"
            class="px-4 py-2.5 rounded-lg text-sm font-semibold transition-colors"
          >
            Физическо лице
          </button>
          <button
            type="button"
            @click="form.account_type = 'legal_entity'"
            :class="isLegal ? 'bg-white shadow-sm text-navy-700' : 'text-gray-500 hover:text-navy-700'"
            class="px-4 py-2.5 rounded-lg text-sm font-semibold transition-colors"
          >
            Юридическо лице
          </button>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
          <!-- ── Account holder identity (always shown) ── -->
          <div>
            <h3 v-if="isLegal" class="text-sm font-bold text-navy-700 uppercase tracking-wider mb-3">
              Представляващ
            </h3>
            <div class="space-y-4">
              <div>
                <label for="name" class="block text-sm font-medium text-navy-700 mb-1">
                  {{ isLegal ? 'Имена на представляващия' : 'Име' }}
                </label>
                <input
                  id="name"
                  v-model="form.name"
                  type="text"
                  required
                  autocomplete="name"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                  :class="errors.name ? 'border-red-400' : ''"
                  :placeholder="isLegal ? 'Иван Иванов Иванов' : 'Иван Иванов'"
                />
                <p v-if="errors.name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.name[0] }}</p>
              </div>

              <div>
                <label for="email" class="block text-sm font-medium text-navy-700 mb-1">
                  {{ isLegal ? 'Имейл за вход' : 'Имейл' }}
                </label>
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  required
                  autocomplete="email"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                  :class="errors.email ? 'border-red-400' : ''"
                  placeholder="ime@example.com"
                />
                <p v-if="errors.email" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.email[0] }}</p>
              </div>

              <div class="grid sm:grid-cols-2 gap-4">
                <div>
                  <label for="password" class="block text-sm font-medium text-navy-700 mb-1">Парола</label>
                  <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    autocomplete="new-password"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                    :class="errors.password ? 'border-red-400' : ''"
                    placeholder="Минимум 8 символа"
                  />
                  <p v-if="errors.password" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.password[0] }}</p>
                </div>
                <div>
                  <label for="password_confirmation" class="block text-sm font-medium text-navy-700 mb-1">Потвърди</label>
                  <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                    placeholder="Повтори паролата"
                  />
                </div>
              </div>

              <!-- Representative-only fields -->
              <template v-if="isLegal">
                <div>
                  <label for="rep_role" class="block text-sm font-medium text-navy-700 mb-1">Качество</label>
                  <select
                    id="rep_role"
                    v-model="form.representative_role"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.representative_role ? 'border-red-400' : ''"
                  >
                    <option v-for="r in REPRESENTATIVE_ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
                  </select>
                  <p v-if="errors.representative_role" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.representative_role[0] }}</p>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">ЕГН (за бълг. граждани)</label>
                    <input
                      v-model="form.representative_egn"
                      type="text"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.representative_egn ? 'border-red-400' : ''"
                      placeholder="10 цифри"
                      maxlength="10"
                    />
                    <p v-if="errors.representative_egn" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.representative_egn[0] }}</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">Или дата на раждане (за чужденци)</label>
                    <input
                      v-model="form.representative_dob"
                      type="date"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.representative_dob ? 'border-red-400' : ''"
                    />
                    <p v-if="errors.representative_dob" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.representative_dob[0] }}</p>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <!-- ────────────────────────── LEGAL ENTITY ──────────────────────── -->
          <template v-if="isLegal">
            <hr class="border-gray-100" />

            <!-- Company identity -->
            <div>
              <h3 class="text-sm font-bold text-navy-700 uppercase tracking-wider mb-3">Фирмени данни</h3>
              <div class="space-y-4">
                <div class="grid sm:grid-cols-3 gap-4">
                  <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-navy-700 mb-1">Юридическо наименование</label>
                    <input
                      v-model="form.legal_name"
                      type="text"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.legal_name ? 'border-red-400' : ''"
                      placeholder="ВАМА АСЕТ"
                    />
                    <p v-if="errors.legal_name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.legal_name[0] }}</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">Правна форма</label>
                    <select
                      v-model="form.legal_form"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.legal_form ? 'border-red-400' : ''"
                    >
                      <option v-for="f in LEGAL_FORMS" :key="f.value" :value="f.value">{{ f.label }}</option>
                    </select>
                  </div>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">ЕИК</label>
                    <input
                      v-model="form.eik"
                      type="text"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.eik ? 'border-red-400' : ''"
                      placeholder="9 или 13 цифри"
                      maxlength="13"
                    />
                    <p v-if="errors.eik" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.eik[0] }}</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">ДДС № (по избор)</label>
                    <input
                      v-model="form.vat_number"
                      type="text"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.vat_number ? 'border-red-400' : ''"
                      placeholder="BG201035515"
                    />
                    <p v-if="errors.vat_number" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.vat_number[0] }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Address -->
            <div>
              <h3 class="text-sm font-bold text-navy-700 uppercase tracking-wider mb-3">Адрес на управление</h3>
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-navy-700 mb-1">Улица и номер</label>
                  <input
                    v-model="form.address_street"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.address_street ? 'border-red-400' : ''"
                    placeholder="ул. Васил Левски 1"
                  />
                  <p v-if="errors.address_street" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.address_street[0] }}</p>
                </div>
                <div class="grid sm:grid-cols-3 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">Град</label>
                    <input
                      v-model="form.address_city"
                      type="text"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.address_city ? 'border-red-400' : ''"
                      placeholder="София"
                    />
                    <p v-if="errors.address_city" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.address_city[0] }}</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">Пощ. код</label>
                    <input
                      v-model="form.address_postcode"
                      type="text"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.address_postcode ? 'border-red-400' : ''"
                      placeholder="1000"
                    />
                    <p v-if="errors.address_postcode" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.address_postcode[0] }}</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-navy-700 mb-1">Държава</label>
                    <input
                      v-model="form.address_country"
                      type="text"
                      maxlength="2"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="errors.address_country ? 'border-red-400' : ''"
                      placeholder="BG"
                    />
                    <p v-if="errors.address_country" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.address_country[0] }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Company contact -->
            <div>
              <h3 class="text-sm font-bold text-navy-700 uppercase tracking-wider mb-3">Контакт на фирмата</h3>
              <div class="grid sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-navy-700 mb-1">Имейл на фирмата</label>
                  <input
                    v-model="form.company_email"
                    type="email"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.company_email ? 'border-red-400' : ''"
                    placeholder="office@vamaasset.bg"
                  />
                  <p v-if="errors.company_email" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.company_email[0] }}</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-navy-700 mb-1">Телефон</label>
                  <input
                    v-model="form.company_phone"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.company_phone ? 'border-red-400' : ''"
                    placeholder="+359 88 123 4567"
                  />
                  <p v-if="errors.company_phone" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.company_phone[0] }}</p>
                </div>
              </div>
            </div>

            <!-- AML — PEP + Source of funds -->
            <div>
              <h3 class="text-sm font-bold text-navy-700 uppercase tracking-wider mb-3">Декларации по ЗМИП</h3>
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-navy-700 mb-1">Източник на средствата</label>
                  <select
                    v-model="form.source_of_funds"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.source_of_funds ? 'border-red-400' : ''"
                  >
                    <option v-for="s in SOURCES_OF_FUNDS" :key="s.value" :value="s.value">{{ s.label }}</option>
                  </select>
                </div>
                <div v-if="form.source_of_funds === 'other'">
                  <label class="block text-sm font-medium text-navy-700 mb-1">Опишете източника</label>
                  <input
                    v-model="form.source_of_funds_other"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.source_of_funds_other ? 'border-red-400' : ''"
                  />
                  <p v-if="errors.source_of_funds_other" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.source_of_funds_other[0] }}</p>
                </div>

                <label class="flex items-start gap-2.5">
                  <input
                    v-model="form.pep_status"
                    type="checkbox"
                    class="mt-0.5 size-4 rounded border-gray-300 text-accent-400 focus:ring-accent-400/50"
                  />
                  <span class="text-xs text-gray-600 leading-relaxed">
                    Представляващият или негов близък <strong>е</strong> политически значимо лице (PEP) по смисъла на ЗМИП.
                  </span>
                </label>
                <div v-if="form.pep_status">
                  <label class="block text-sm font-medium text-navy-700 mb-1">Подробности за PEP статус</label>
                  <textarea
                    v-model="form.pep_details"
                    rows="2"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                    :class="errors.pep_details ? 'border-red-400' : ''"
                    placeholder="Длъжност, държава, период..."
                  />
                  <p v-if="errors.pep_details" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.pep_details[0] }}</p>
                </div>
              </div>
            </div>

            <!-- Beneficial owners -->
            <div>
              <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-bold text-navy-700 uppercase tracking-wider">
                  Действителни собственици
                  <span class="text-xs font-normal text-gray-400 normal-case ml-1">(УДБ — поне 1 запис)</span>
                </h3>
                <button
                  v-if="form.beneficial_owners.length < 20"
                  type="button"
                  @click="addUbo"
                  class="text-xs font-medium text-accent-500 hover:text-accent-600"
                >+ Добави УДБ</button>
              </div>

              <p v-if="errors.beneficial_owners" class="text-xs text-red-500 mb-3">
                {{ errors.beneficial_owners[0] }}
              </p>

              <div
                v-for="(ubo, idx) in form.beneficial_owners"
                :key="idx"
                class="rounded-xl border border-gray-100 p-4 mb-3 bg-gray-50/50"
              >
                <div class="flex items-center justify-between mb-3">
                  <p class="text-xs font-semibold text-navy-700">УДБ #{{ idx + 1 }}</p>
                  <button
                    v-if="form.beneficial_owners.length > 1"
                    type="button"
                    @click="removeUbo(idx)"
                    class="text-xs text-red-500 hover:text-red-600"
                  >Премахни</button>
                </div>
                <div class="space-y-3">
                  <div>
                    <label class="block text-xs font-medium text-navy-700 mb-1">Трите имена</label>
                    <input
                      v-model="ubo.full_name"
                      type="text"
                      class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      :class="uboError(idx, 'full_name') ? 'border-red-400' : ''"
                      placeholder="Иван Иванов Иванов"
                    />
                    <p v-if="uboError(idx, 'full_name')" class="mt-1 text-xs text-red-500">{{ uboError(idx, 'full_name') }}</p>
                  </div>
                  <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                      <label class="block text-xs font-medium text-navy-700 mb-1">ЕГН (бълг. гражданин)</label>
                      <input
                        v-model="ubo.national_id"
                        type="text"
                        maxlength="10"
                        class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                        :class="uboError(idx, 'national_id') ? 'border-red-400' : ''"
                        placeholder="10 цифри"
                      />
                      <p v-if="uboError(idx, 'national_id')" class="mt-1 text-xs text-red-500">{{ uboError(idx, 'national_id') }}</p>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-navy-700 mb-1">Или дата на раждане</label>
                      <input
                        v-model="ubo.date_of_birth"
                        type="date"
                        class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                        :class="uboError(idx, 'date_of_birth') ? 'border-red-400' : ''"
                      />
                      <p v-if="uboError(idx, 'date_of_birth')" class="mt-1 text-xs text-red-500">{{ uboError(idx, 'date_of_birth') }}</p>
                    </div>
                  </div>
                  <div class="grid sm:grid-cols-3 gap-3">
                    <div>
                      <label class="block text-xs font-medium text-navy-700 mb-1">Гражданство</label>
                      <input
                        v-model="ubo.nationality"
                        type="text"
                        maxlength="2"
                        class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                        placeholder="BG"
                      />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-navy-700 mb-1">% собственост</label>
                      <input
                        v-model="ubo.ownership_percent"
                        type="number"
                        min="0.01"
                        max="100"
                        step="0.01"
                        class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                        :class="uboError(idx, 'ownership_percent') ? 'border-red-400' : ''"
                        placeholder="50"
                      />
                      <p v-if="uboError(idx, 'ownership_percent')" class="mt-1 text-xs text-red-500">{{ uboError(idx, 'ownership_percent') }}</p>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-navy-700 mb-1">Контрол</label>
                      <select
                        v-model="ubo.control_type"
                        class="w-full px-2 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      >
                        <option v-for="c in CONTROL_TYPES" :key="c.value" :value="c.value">{{ c.label }}</option>
                      </select>
                    </div>
                  </div>

                  <label class="flex items-start gap-2.5">
                    <input
                      v-model="ubo.pep_status"
                      type="checkbox"
                      class="mt-0.5 size-4 rounded border-gray-300 text-accent-400 focus:ring-accent-400/50"
                    />
                    <span class="text-xs text-gray-600 leading-relaxed">
                      Този УДБ <strong>е</strong> политически значимо лице (PEP).
                    </span>
                  </label>
                  <div v-if="ubo.pep_status">
                    <textarea
                      v-model="ubo.pep_details"
                      rows="2"
                      class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
                      placeholder="Длъжност, държава, период..."
                    />
                  </div>
                </div>
              </div>
            </div>
          </template>

          <!-- ── Legal consent (always last) ── -->
          <div class="pt-3 border-t border-gray-100">
            <label class="flex items-start gap-2.5">
              <input
                v-model="form.terms_accepted"
                type="checkbox"
                class="mt-0.5 size-4 rounded border-gray-300 text-accent-400 focus:ring-accent-400/50"
              />
              <span class="text-xs text-gray-500 leading-relaxed">
                Съгласявам се с
                <router-link to="/legal/terms" target="_blank" class="text-accent-500 hover:text-accent-600 underline">Общите условия</router-link>,
                <router-link to="/legal/privacy" target="_blank" class="text-accent-500 hover:text-accent-600 underline">Политиката за поверителност</router-link>
                и <router-link to="/legal/risk" target="_blank" class="text-accent-500 hover:text-accent-600 underline">Декларацията за риска</router-link>.
                Съгласен съм с обработката на личните ми данни за целите на регистрацията и услугите на платформата.
              </span>
            </label>
            <p v-if="errors.terms_accepted" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.terms_accepted[0] }}</p>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full py-3 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Регистриране...' : 'Регистрирай се' }}
          </button>
        </form>
      </div>

      <p class="text-center text-sm text-gray-500 mt-6">
        Вече имаш акаунт?
        <router-link to="/login" class="text-accent-500 hover:text-accent-600 font-medium">Влез</router-link>
      </p>
    </div>
  </div>
</template>
