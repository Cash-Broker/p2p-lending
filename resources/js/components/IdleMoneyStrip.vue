<script setup>
import { computed, ref } from 'vue'

// Idle-money nudge (2026-08-15, extracted from the welcome-back banner where
// it read as fake news): a PERMANENT, quiet strip — «свободните пари стоят
// без работа» is true at any moment, so it needs no «докато те нямаше»
// framing. Honest loss-framing: the missed €/day at the best current
// «само лихва» rate. Dismiss hides it for the tab session.
const props = defineProps({
  available: { type: String, required: true },
  rateRange: { type: Object, default: null }, // { min, max } годишно
})

const DISMISS_KEY = 'vama_idle_strip_dismissed'

const dismissed = ref(sessionStorage.getItem(DISMISS_KEY) === '1')

function dismiss() {
  dismissed.value = true
  try { sessionStorage.setItem(DISMISS_KEY, '1') } catch { /* ignore */ }
}

const amount = computed(() => parseFloat(props.available) || 0)

const missedPerDay = computed(() => {
  const rate = parseFloat(props.rateRange?.max) || 0
  if (rate <= 0) return 0
  return (amount.value * rate) / 100 / 365
})

const show = computed(() => !dismissed.value && amount.value >= 50)

function fmt(v, decimals = 2) {
  return v.toLocaleString('bg-BG', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}
</script>

<template>
  <div
    v-if="show"
    class="mb-8 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-amber-200 bg-amber-50/60 px-5 py-3"
  >
    <p class="text-sm text-navy-700 min-w-0">
      💤 <strong class="font-bold">{{ fmt(amount) }} €</strong> свободни стоят без работа<template v-if="missedPerDay >= 0.01">
        — пропускаш до <strong class="font-bold text-amber-600">{{ fmt(missedPerDay) }} € на ден</strong></template>.
    </p>
    <router-link
      to="/invest"
      class="ml-auto shrink-0 rounded-xl bg-navy-700 hover:bg-navy-800 px-4 py-2 text-sm font-bold text-white transition-colors"
    >
      Инвестирай
    </router-link>
    <button
      type="button"
      @click="dismiss"
      aria-label="Скрий"
      class="shrink-0 text-gray-400 hover:text-navy-700 transition-colors"
    >
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
    </button>
  </div>
</template>
