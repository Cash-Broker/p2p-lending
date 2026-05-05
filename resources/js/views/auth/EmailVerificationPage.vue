<script setup>
import { ref } from 'vue'
import api from '../../api/axios'

const loading = ref(false)
const sent = ref(false)

async function resend() {
  loading.value = true
  try {
    await api.post('/email/verification-notification')
    sent.value = true
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
          <img :src="'/logo/logo-mark.png'" alt="Vamaasset" class="h-20 w-auto" width="93" height="80" />
        </router-link>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8 text-center">
        <!-- Email icon -->
        <div class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-accent-50 text-accent-500 mb-5">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
          </svg>
        </div>

        <h1 class="text-2xl font-bold text-navy-700 mb-2">Провери имейла си</h1>
        <p class="text-sm text-gray-500 mb-6">
          Изпратихме ти линк за верификация. Провери входящата си поща и кликни на линка за да активираш акаунта си.
        </p>

        <div v-if="sent" class="rounded-xl bg-accent-50 border border-accent-200 p-3 text-sm text-accent-700 mb-4">
          Нов линк е изпратен!
        </div>

        <button
          @click="resend"
          :disabled="loading"
          class="w-full py-2.5 border border-gray-200 hover:border-navy-200 hover:bg-navy-50 disabled:opacity-50 text-navy-700 text-sm font-semibold rounded-xl transition-colors"
        >
          {{ loading ? 'Изпращане...' : 'Изпрати отново' }}
        </button>
      </div>

      <p class="text-center text-sm text-gray-500 mt-6">
        <router-link to="/login" class="text-accent-500 hover:text-accent-600 font-medium">Обратно към вход</router-link>
      </p>
    </div>
  </div>
</template>
