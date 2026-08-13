<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatEuro, tickerValue } from '../utils/earningsTicker'

// The green «Спечелени» reference counter (client request 2026-08-13):
// interest accrued by the repayment schedule but not yet paid out. Two
// variants, picked by the admin via dashboard_earned_mode:
//   daily — static amount that steps once per day,
//   live  — ticks every second at the schedule's pace.
// Informational only — this money is NOT spendable yet, hence the caption.
const props = defineProps({
  accrual: { type: Object, required: true },
})

const isLive = computed(() => props.accrual.mode === 'live')

// Anchor on the client clock at mount: the backend amount is a snapshot at
// `as_of`, and ticking from "received now" avoids server/client clock-skew
// jumps. Worst case the ticker starts a request-latency behind — invisible.
const anchorMs = Date.now()
const nowMs = ref(anchorMs)
let timer = null

onMounted(() => {
  if (isLive.value) {
    timer = setInterval(() => { nowMs.value = Date.now() }, 1000)
  }
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

const displayValue = computed(() => {
  if (!isLive.value) {
    return formatEuro(parseFloat(props.accrual.amount_daily) || 0)
  }
  return formatEuro(tickerValue(props.accrual.amount_live, props.accrual.per_second_rate, nowMs.value - anchorMs))
})

const dailyRate = computed(() => {
  const rate = parseFloat(props.accrual.daily_rate) || 0
  if (rate <= 0) return null
  // Sub-stotinka daily pace still deserves a visible hint, not «0,00».
  return rate < 0.01 ? '< 0,01' : formatEuro(rate)
})
</script>

<template>
  <div class="rounded-2xl border border-gray-100 bg-white px-5 py-4 sm:min-w-[240px]">
    <div class="flex items-center justify-between gap-6 mb-1">
      <span class="text-xs font-medium text-gray-400 uppercase tracking-wider">Спечелени</span>
      <span v-if="dailyRate" class="text-xs font-semibold text-accent-500">+{{ dailyRate }} € / ден</span>
    </div>
    <p class="text-3xl font-bold text-accent-500 tabular-nums">
      {{ displayValue }} <span class="text-sm font-medium text-accent-500/70">€</span>
    </p>
    <p class="text-[11px] text-gray-400 mt-1">по погасителен план · информативно</p>
  </div>
</template>
