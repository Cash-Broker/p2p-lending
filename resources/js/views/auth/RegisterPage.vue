<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = ref({
  account_type: 'individual',

  // Common (also acts as login + display name)
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  terms_accepted: false,

  // Legal-entity-only
  legal_name: '',
  eik: '',
  first_name: '',
  last_name: '',
  phone: '',
})

const errors = ref({})
const loading = ref(false)
const isLegal = computed(() => form.value.account_type === 'legal_entity')

async function submit() {
  errors.value = {}
  loading.value = true
  try {
    const payload = { ...form.value }

    if (isLegal.value) {
      // Backend RegisterRequest::prepareForValidation() concatenates
      // first_name + last_name into name, but we also send name explicitly
      // so individual rules can apply uniformly even if the request rules
      // diverge later.
      payload.name = `${form.value.first_name.trim()} ${form.value.last_name.trim()}`.trim()
    } else {
      // Strip legal-entity fields to keep the body small and avoid sending
      // empty PII fields the backend doesn't expect for individuals.
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
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <router-link to="/" class="inline-flex items-center gap-2">
          <div class="flex size-10 items-center justify-center rounded-xl bg-navy-700 text-white text-sm font-bold">V</div>
          <span class="text-xl font-bold text-navy-700">Vamaa<span class="text-accent-400">sset</span></span>
        </router-link>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-8">
        <h1 class="text-2xl font-bold text-navy-700 mb-1">Регистрация</h1>
        <p class="text-sm text-gray-500 mb-6">Създай акаунт и започни да инвестираш</p>

        <!-- Account-type toggle -->
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

        <form @submit.prevent="submit" class="space-y-4">
          <!-- ──────────────────────────── INDIVIDUAL ─────────────────────────── -->
          <template v-if="!isLegal">
            <div>
              <label for="name" class="block text-sm font-medium text-navy-700 mb-1">Име</label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                required
                autocomplete="name"
                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                :class="errors.name ? 'border-red-400' : ''"
                placeholder="Иван Иванов"
              />
              <p v-if="errors.name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.name[0] }}</p>
            </div>
          </template>

          <!-- ────────────────────────── LEGAL ENTITY ──────────────────────────── -->
          <template v-else>
            <div>
              <label for="legal_name" class="block text-sm font-medium text-navy-700 mb-1">Име на фирмата</label>
              <input
                id="legal_name"
                v-model="form.legal_name"
                type="text"
                required
                autocomplete="organization"
                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                :class="errors.legal_name ? 'border-red-400' : ''"
              />
              <p v-if="errors.legal_name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.legal_name[0] }}</p>
            </div>

            <div>
              <label for="eik" class="block text-sm font-medium text-navy-700 mb-1">ЕИК</label>
              <input
                id="eik"
                v-model="form.eik"
                type="text"
                inputmode="numeric"
                maxlength="13"
                required
                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                :class="errors.eik ? 'border-red-400' : ''"
              />
              <p v-if="errors.eik" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.eik[0] }}</p>
            </div>

            <div>
              <label for="first_name" class="block text-sm font-medium text-navy-700 mb-1">Име на контактно лице</label>
              <input
                id="first_name"
                v-model="form.first_name"
                type="text"
                required
                autocomplete="given-name"
                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                :class="errors.first_name ? 'border-red-400' : ''"
              />
              <p v-if="errors.first_name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.first_name[0] }}</p>
            </div>

            <div>
              <label for="last_name" class="block text-sm font-medium text-navy-700 mb-1">Фамилия на контактно лице</label>
              <input
                id="last_name"
                v-model="form.last_name"
                type="text"
                required
                autocomplete="family-name"
                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
                :class="errors.last_name ? 'border-red-400' : ''"
              />
              <p v-if="errors.last_name" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.last_name[0] }}</p>
            </div>
          </template>

          <!-- ── Email + Phone (phone shown only for legal entity) ── -->
          <div>
            <label for="email" class="block text-sm font-medium text-navy-700 mb-1">Имейл</label>
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

          <div v-if="isLegal">
            <label for="phone" class="block text-sm font-medium text-navy-700 mb-1">Телефон</label>
            <input
              id="phone"
              v-model="form.phone"
              type="tel"
              required
              autocomplete="tel"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.phone ? 'border-red-400' : ''"
              placeholder="+359 88 123 4567"
            />
            <p v-if="errors.phone" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.phone[0] }}</p>
          </div>

          <!-- ── Password + Confirm ── -->
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
            <label for="password_confirmation" class="block text-sm font-medium text-navy-700 mb-1">Потвърди парола</label>
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

          <!-- ── Legal consent ── -->
          <div class="pt-2">
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
