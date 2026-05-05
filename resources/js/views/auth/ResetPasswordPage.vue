<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../api/axios'

const route = useRoute()
const router = useRouter()

const form = ref({
  email: route.query.email || '',
  password: '',
  password_confirmation: '',
  token: route.params.token || '',
})
const errors = ref({})
const loading = ref(false)

async function submit() {
  errors.value = {}
  loading.value = true
  try {
    await api.post('/reset-password', form.value)
    router.push('/login')
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
      <div class="text-center mb-8">
        <router-link to="/" class="inline-flex items-center" aria-label="Vamaasset — начална страница">
          <img :src="'/logo/logo.png'" alt="Vamaasset" class="h-16 w-auto" width="64" height="64" />
        </router-link>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8">
        <h1 class="text-2xl font-bold text-navy-700 mb-1">Нова парола</h1>
        <p class="text-sm text-gray-500 mb-6">Въведи новата си парола</p>

        <form @submit.prevent="submit" class="space-y-4">
          <div>
            <label for="email" class="block text-sm font-medium text-navy-700 mb-1">Имейл</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              required
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.email ? 'border-red-400' : ''"
            />
            <p v-if="errors.email" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.email[0] }}</p>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-navy-700 mb-1">Нова парола</label>
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

          <button
            type="submit"
            :disabled="loading"
            class="w-full py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Запазване...' : 'Запази парола' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
