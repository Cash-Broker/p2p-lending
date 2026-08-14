<script setup>
import { computed, ref } from 'vue'
import { projectYearlyInterest, sliderMax } from '../utils/whatIf'

// «Какво-ако» слайдер (engagement pack 2026-08-14): drag an amount, watch
// the yearly profit projection redraw live — goal visualization. Honest
// math: simple yearly interest at the CURRENT «само лихва» offer range,
// labeled as such. Hidden entirely when nothing is investable.
const props = defineProps({
  rateRange: { type: Object, required: true }, // { min, max } — «само лихва» годишно
  available: { type: String, default: '0.00' },
})

const max = computed(() => sliderMax(props.available))
const amount = ref(Math.min(Math.max(500, Math.round((parseFloat(props.available) || 0) / 50) * 50), sliderMax(props.available)))

const low = computed(() => projectYearlyInterest(amount.value, props.rateRange.min))
const high = computed(() => projectYearlyInterest(amount.value, props.rateRange.max))
const sameRate = computed(() => props.rateRange.min === props.rateRange.max)

function fmt(v) {
  return v.toLocaleString('bg-BG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const fillPercent = computed(() => Math.round(((amount.value - 50) / (max.value - 50)) * 100))
</script>

<template>
  <div class="rounded-2xl border border-gray-100 bg-white p-6">
    <h2 class="text-base font-bold text-navy-700 mb-1">Ами ако инвестираш…</h2>
    <p class="text-3xl font-extrabold text-navy-700 tabular-nums mb-3">{{ fmt(amount) }} <span class="text-base font-semibold text-gray-400">€</span></p>

    <input
      v-model.number="amount"
      type="range"
      :min="50"
      :max="max"
      :step="50"
      class="whatif-slider w-full"
      :style="{ '--fill': fillPercent + '%' }"
      aria-label="Сума за проекция"
    />

    <div class="mt-4 rounded-xl bg-accent-400/10 border border-accent-400/25 px-4 py-3">
      <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-0.5">Печалба за 12 месеца</p>
      <p class="text-xl font-extrabold text-accent-500 tabular-nums">
        <template v-if="sameRate">+{{ fmt(high) }} €</template>
        <template v-else>+{{ fmt(low) }} до +{{ fmt(high) }} €</template>
      </p>
      <p class="text-[11px] text-gray-400 mt-0.5">
        при годишна доходност
        <template v-if="sameRate">{{ parseFloat(rateRange.max) }}%</template>
        <template v-else>{{ parseFloat(rateRange.min) }}–{{ parseFloat(rateRange.max) }}%</template>
        («само лихва») — проекция; реалният срок зависи от избрания кредит
      </p>
    </div>

    <router-link
      to="/invest"
      class="mt-4 flex items-center justify-center gap-2 w-full rounded-xl bg-navy-700 hover:bg-navy-800 px-4 py-2.5 text-sm font-bold text-white transition-colors"
    >
      Разгледай кредитите
    </router-link>
  </div>
</template>

<style scoped>
.whatif-slider {
  appearance: none;
  height: 8px;
  border-radius: 9999px;
  background: linear-gradient(to right, #22C55E var(--fill), #f3f4f6 var(--fill));
  outline-offset: 4px;
  cursor: pointer;
}

.whatif-slider::-webkit-slider-thumb {
  appearance: none;
  width: 22px;
  height: 22px;
  border-radius: 9999px;
  background: #fff;
  border: 3px solid #22C55E;
  box-shadow: 0 1px 4px rgba(27, 42, 74, 0.25);
  transition: transform 0.15s ease;
}

.whatif-slider::-webkit-slider-thumb:hover { transform: scale(1.12); }

.whatif-slider::-moz-range-thumb {
  width: 22px;
  height: 22px;
  border-radius: 9999px;
  background: #fff;
  border: 3px solid #22C55E;
  box-shadow: 0 1px 4px rgba(27, 42, 74, 0.25);
}
</style>
