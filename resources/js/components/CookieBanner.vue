<script setup>
import { ref, onMounted } from 'vue'

const STORAGE_KEY = 'cookie_consent'
// Bump this if/when policy changes substantively — visitors with stale consent
// will be re-prompted.
const POLICY_VERSION = 'v1.0'

const visible = ref(false)

onMounted(() => {
  // Show the banner only when no valid consent record exists for the current
  // policy version. Wrapped in try/catch because privacy modes / Safari ITP
  // can throw on localStorage access.
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) {
      visible.value = true
      return
    }
    const parsed = JSON.parse(raw)
    if (!parsed?.version || parsed.version !== POLICY_VERSION) {
      visible.value = true
    }
  } catch {
    visible.value = true
  }
})

function persist(choice) {
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        version: POLICY_VERSION,
        choice,
        accepted_at: new Date().toISOString(),
      }),
    )
  } catch {
    // localStorage unavailable — nothing we can do; the banner just won't be
    // suppressed on next visit. Acceptable trade-off vs. blocking the page.
  }
  visible.value = false
}
</script>

<template>
  <transition
    enter-active-class="transition-all duration-300"
    enter-from-class="opacity-0 translate-y-4"
    enter-to-class="opacity-100 translate-y-0"
    leave-active-class="transition-all duration-200"
    leave-from-class="opacity-100 translate-y-0"
    leave-to-class="opacity-0 translate-y-4"
  >
    <div
      v-if="visible"
      class="fixed bottom-4 left-4 right-4 sm:bottom-6 sm:left-6 sm:right-auto sm:max-w-md z-50"
      role="dialog"
      aria-live="polite"
      aria-label="Известие за бисквитки"
    >
      <div class="bg-white rounded-2xl border border-gray-200 shadow-lg p-5">
        <p class="text-sm font-semibold text-navy-700 mb-1.5">Бисквитки</p>
        <p class="text-xs text-gray-500 leading-relaxed mb-4">
          Използваме технически бисквитки, необходими за работа на платформата
          (поддръжка на сесия, защита от атаки). Аналитични или маркетингови
          бисквитки не поставяме без Вашето съгласие. Подробности —
          <router-link to="/legal/cookies" class="text-accent-500 hover:text-accent-600 underline">
            Политика за бисквитки
          </router-link>.
        </p>
        <div class="flex flex-col sm:flex-row gap-2">
          <button
            type="button"
            @click="persist('all')"
            class="flex-1 px-4 py-2 bg-accent-400 hover:bg-accent-500 text-white text-xs font-semibold rounded-lg transition-colors"
          >
            Приемам всички
          </button>
          <button
            type="button"
            @click="persist('essential')"
            class="flex-1 px-4 py-2 border border-gray-200 hover:border-navy-200 hover:bg-gray-50 text-navy-700 text-xs font-semibold rounded-lg transition-colors"
          >
            Само необходимите
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>
