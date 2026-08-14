<script setup>
import { computed } from 'vue'

// «Следващо плащане · след X дни · +Y €» — anticipation hook (engagement
// pack 2026-08-14). The ring fills as the payout date approaches; the
// figures are the exact unpaid schedule rows for that day.
const props = defineProps({
  payout: { type: Object, required: true },
})

const CIRC = 2 * Math.PI * 26

const dashOffset = computed(() => CIRC * (1 - Math.min(1, Math.max(0, props.payout.period_progress || 0))))

const daysLabel = computed(() => {
  const d = props.payout.days_left
  if (d === 0) return 'днес'
  if (d === 1) return 'утре'
  return `след ${d} дни`
})

function formatAmount(val) {
  return (parseFloat(val) || 0).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

// 'T00:00:00' (no Z) parses as LOCAL midnight — a bare 'YYYY-MM-DD' would be
// UTC midnight and label the previous day for viewers west of UTC.
const dateLabel = computed(() =>
  new Date(props.payout.due_date + 'T00:00:00').toLocaleDateString('bg-BG', { day: 'numeric', month: 'long' }))
</script>

<template>
  <div class="rounded-2xl border border-gray-100 bg-white p-6">
    <h2 class="text-base font-bold text-navy-700 mb-4">Следващо плащане</h2>
    <div class="flex items-center gap-4">
      <div class="relative size-[64px] shrink-0" aria-hidden="true">
        <svg viewBox="0 0 60 60" class="size-full -rotate-90">
          <circle cx="30" cy="30" r="26" fill="none" stroke="#f3f4f6" stroke-width="6" />
          <circle
            cx="30" cy="30" r="26" fill="none"
            stroke="#22C55E" stroke-width="6" stroke-linecap="round"
            :stroke-dasharray="CIRC"
            :stroke-dashoffset="dashOffset"
            class="ring-progress"
          />
        </svg>
        <span class="absolute inset-0 flex items-center justify-center text-xs font-extrabold text-navy-700 tabular-nums">
          {{ payout.days_left === 0 ? '🎉' : payout.days_left + 'д' }}
        </span>
      </div>
      <div>
        <p class="text-2xl font-extrabold text-accent-500 tabular-nums leading-tight">+{{ formatAmount(payout.amount) }} €</p>
        <p class="text-sm text-gray-500 mt-0.5">{{ daysLabel }} · {{ dateLabel }}</p>
        <p class="text-[11px] text-gray-400">по погасителния план</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ring-progress {
  transition: stroke-dashoffset 1s cubic-bezier(0.22, 1, 0.36, 1);
}
</style>
