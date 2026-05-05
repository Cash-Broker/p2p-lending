<script setup>
import { ref } from 'vue'
import api from '../../api/axios'

const email = ref('')
const errors = ref({})
const loading = ref(false)
const success = ref(false)

async function submit() {
  errors.value = {}
  loading.value = true
  try {
    await api.post('/forgot-password', { email: email.value })
    success.value = true
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
        <h1 class="text-2xl font-bold text-navy-700 mb-1">Забравена парола</h1>
        <p class="text-sm text-gray-500 mb-6">Ще ти изпратим линк за нова парола</p>

        <div v-if="success" class="rounded-xl bg-accent-50 border border-accent-200 p-4 text-sm text-accent-700">
          Изпратихме линк за нова парола на вашия имейл.
        </div>

        <form v-else @submit.prevent="submit" class="space-y-4">
          <div>
            <label for="email" class="block text-sm font-medium text-navy-700 mb-1">Имейл</label>
            <input
              id="email"
              v-model="email"
              type="email"
              required
              autocomplete="email"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.email ? 'border-red-400' : ''"
              placeholder="ime@example.com"
            />
            <p v-if="errors.email" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ errors.email[0] }}</p>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full py-2.5 bg-navy-700 hover:bg-navy-600 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Изпращане...' : 'Изпрати линк' }}
          </button>
        </form>
      </div>

      <p class="text-center text-sm text-gray-500 mt-6">
        <router-link to="/login" class="text-accent-500 hover:text-accent-600 font-medium">Обратно към вход</router-link>
      </p>
    </div>
  </div>
</template>
