<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useDocumentMeta } from '../../composables/useDocumentMeta'

useDocumentMeta({
  title: 'Вход',
  description: 'Влезте в акаунта си в Vamaasset.',
  path: '/login',
})

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// Arrived here via the session-expiry hard redirect (axios 401/419
// interceptor) — tell her WHY she is at the login screen instead of leaving
// the impression the app broke (PWA fix, Reni 2026-08-16).
const sessionExpired = ref(route.query.expired === '1')

// SEC-22: landing from the account-deletion e-mail links.
const deletionNotice = ref(route.query.deletion ?? null)
const deletionNotices = {
  cancelled: 'Заявката за закриване е отменена и всички сесии бяха прекратени. Ако не сте я подавали вие, сменете паролата си.',
  confirmed: 'Закриването е потвърдено. Влезте, за да видите датата или да го отмените.',
  invalid: 'Линкът вече не е валиден.',
}

const form = ref({
  email: '',
  password: '',
})
const errors = ref({})
const loading = ref(false)

async function submit() {
  errors.value = {}
  loading.value = true
  try {
    await auth.login(form.value)
    // Only a plain in-app path may steer the post-login landing: a repeated
    // ?redirect= arrives as an ARRAY (router.push would throw inside this try
    // and fake a login failure), and '//host' shapes are hostile-link fodder.
    const target = route.query.redirect
    router.push(typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')
      ? target
      : '/dashboard')
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
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
    <div class="w-full max-w-md">
      <!-- Logo -->
      <div class="text-center mb-8">
        <router-link to="/" class="inline-flex items-center" aria-label="Vamaasset — начална страница">
          <img :src="'/logo/logo-mark.png'" alt="Vamaasset" class="h-20 w-auto" width="93" height="80" />
        </router-link>
      </div>

      <!-- Card -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8">
        <h1 class="text-2xl font-bold text-navy-700 mb-1">Вход</h1>
        <p class="text-sm text-gray-500 mb-6">Влез в своя акаунт</p>

        <div
          v-if="sessionExpired"
          class="mb-4 rounded-xl bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-700"
          role="status"
        >
          Сесията ви изтече след период на неактивност. Влезте отново — ще ви върнем там, откъдето спряхте.
        </div>

        <div
          v-if="deletionNotice && deletionNotices[deletionNotice]"
          class="mb-4 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800"
          role="status"
        >
          {{ deletionNotices[deletionNotice] }}
          <RouterLink v-if="deletionNotice === 'cancelled'" to="/forgot-password" class="ml-1 underline">Смяна на паролата</RouterLink>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
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

          <div>
            <div class="flex items-center justify-between mb-1">
              <label for="password" class="block text-sm font-medium text-navy-700">Парола</label>
              <router-link to="/forgot-password" class="text-xs text-accent-500 hover:text-accent-600">Забравена парола?</router-link>
            </div>
            <input
              id="password"
              v-model="form.password"
              type="password"
              required
              autocomplete="current-password"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.password ? 'border-red-400' : ''"
              placeholder="••••••••"
            />
            <p v-if="errors.password" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.password[0] }}</p>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Влизане...' : 'Влез' }}
          </button>
        </form>
      </div>

      <p class="text-center text-sm text-gray-500 mt-6">
        Нямаш акаунт?
        <router-link to="/register" class="text-accent-500 hover:text-accent-600 font-medium">Регистрирай се</router-link>
      </p>
    </div>
  </div>
</template>
