<script setup>
// SEC-22 (owner 2026-09-03): a QUIET one-line strip while a confirmed account
// closure waits out its period — same understated style as PushOptInBanner.
// No modal, no `inert`: the account is fully usable until the day comes.
import { computed, ref } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/auth'
import { deletionLabel, showCountdown } from '../utils/deletionState'

const auth = useAuthStore()
const busy = ref(false)
const failed = ref(false)

const visible = computed(() => showCountdown(auth.user))
const label = computed(() => deletionLabel(auth.user?.deletion))

async function cancel() {
  busy.value = true
  failed.value = false
  try {
    const { data } = await api.post('/profile/delete/cancel')
    auth.user = data.user
  } catch {
    failed.value = true
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="visible" class="border-b border-red-100 bg-red-50/60 px-4 py-2 text-xs text-red-700 sm:px-6 lg:px-8">
    <span>{{ label }}</span>
    <button type="button" class="ml-2 underline hover:text-red-900 disabled:opacity-50" :disabled="busy" @click="cancel">
      {{ busy ? 'отмяна…' : 'отмени' }}
    </button>
    <span v-if="failed" class="ml-2 text-red-500">Неуспешна отмяна — опитайте от профила.</span>
  </div>
</template>
