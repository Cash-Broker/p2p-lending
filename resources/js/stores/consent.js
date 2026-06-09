import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api/axios'

export const useConsentStore = defineStore('consent', () => {
  // [{ type, version, title, url }]
  const pending = ref([])
  // Bumped whenever we want to force the modal back open (e.g. after a 403 from
  // a gated action the user tried). The layout watches this to clear a local
  // "dismissed" flag.
  const promptNonce = ref(0)

  const needsConsent = computed(() => pending.value.length > 0)

  async function check() {
    try {
      const { data } = await api.get('/consents/pending')
      pending.value = data.pending || []
    } catch {
      // Non-blocking — re-consent is enforced server-side regardless.
    }
  }

  async function forcePrompt() {
    await check()
    promptNonce.value++
  }

  async function accept() {
    await api.post('/consents/accept', { types: pending.value.map((p) => p.type) })
    pending.value = []
  }

  return { pending, promptNonce, needsConsent, check, forcePrompt, accept }
})
