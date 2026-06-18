<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api/axios'

// Private-loan share link landing. Resolves the token on the backend (which
// grants this investor persistent access), then forwards to the loan detail
// page. The router guard already ensured the user is logged in before we get
// here, so the grant is attached to the right account.
const route = useRoute()
const router = useRouter()
const error = ref(null)

onMounted(async () => {
  try {
    const { data } = await api.get(`/loans/shared/${route.params.token}`)
    router.replace(`/invest/${data.loan_id}`)
  } catch (e) {
    // Prefer the server's message (e.g. "не е наличен", invalid link); fall
    // back to generic copy by status.
    error.value = e.response?.data?.message
      || (e.response?.status === 404
        ? 'Линкът е невалиден или вече не е активен.'
        : 'Възникна грешка при отваряне на линка.')
  }
})
</script>

<template>
  <div class="flex flex-col items-center justify-center py-20 text-center">
    <template v-if="!error">
      <div class="size-8 border-4 border-gray-200 border-t-navy-700 rounded-full animate-spin mb-4"></div>
      <p class="text-sm text-gray-500">Отваряне на кредита…</p>
    </template>
    <template v-else>
      <div class="rounded-2xl bg-red-50 border border-red-200 p-8 max-w-sm">
        <p class="text-red-700 font-medium">{{ error }}</p>
        <button @click="router.push('/invest')" class="mt-4 px-4 py-2 bg-navy-700 text-white text-sm rounded-xl">Към таблото</button>
      </div>
    </template>
  </div>
</template>
