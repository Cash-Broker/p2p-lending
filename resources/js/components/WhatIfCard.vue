<script setup>
import { computed, ref } from 'vue'
import { projectTwelveMonthProfit, sliderMax } from '../utils/whatIf'

// «Какво-ако» слайдер (engagement pack 2026-08-14): drag an amount, watch
// the yearly profit projection redraw live — goal visualization. Honest
// math at the CURRENT offer ranges, mirroring OfferProjectionService per
// structure. Plan picker (Reni 2026-08-15): «човек сам да избира от трите
// варианта и да види как ще му пораснат парите».
const props = defineProps({
  // Keyed by payout type: { amortizing: {min,max}, interest_only: {…}, capitalized: {…} }
  rateRanges: { type: Object, required: true },
  available: { type: String, default: '0.00' },
})

const plans = [
  { value: 'amortizing', label: 'Анюитет', hint: 'вноска с главница и лихва всеки месец' },
  { value: 'interest_only', label: 'Само лихва', hint: 'лихва всеки месец, главницата в края' },
  { value: 'capitalized', label: 'Капитализация', hint: 'лихва върху лихвата, всичко на падежа' },
]
// Interest-only was the card's original (and only) projection — stays default.
const selectedPlan = ref('interest_only')
const activePlan = computed(() => plans.find((p) => p.value === selectedPlan.value))
const rateRange = computed(() => props.rateRanges[selectedPlan.value] ?? { min: '0', max: '0' })

function planRateBadge(value) {
  const r = props.rateRanges[value]
  if (!r) return '—'
  return parseFloat(r.min) === parseFloat(r.max)
    ? `${parseFloat(r.max)}%`
    : `${parseFloat(r.min)}–${parseFloat(r.max)}%`
}

const amount = ref(Math.min(Math.max(500, Math.round((parseFloat(props.available) || 0) / 50) * 50), sliderMax(props.available)))

// Freely typed amounts (Reni 2026-08-14: «да може да се набират каквито
// искат, а не до 2000») — the slider max stretches to whatever was typed.
const max = computed(() => {
  const base = sliderMax(props.available)
  const typed = parseFloat(amount.value) || 0
  return Math.max(base, Math.ceil(typed / 500) * 500)
})

function onAmountInput(event) {
  const raw = parseFloat(String(event.target.value).replace(/[^\d]/g, ''))
  amount.value = Number.isFinite(raw) ? Math.max(0, Math.min(raw, 100_000_000)) : 0
}

function onAmountBlur() {
  // Settle below the 50 € minimum only when they stop typing — snapping mid-
  // keystroke would fight the input.
  if ((parseFloat(amount.value) || 0) < 50) amount.value = 50
}

const low = computed(() => projectTwelveMonthProfit(amount.value, rateRange.value.min, selectedPlan.value))
const high = computed(() => projectTwelveMonthProfit(amount.value, rateRange.value.max, selectedPlan.value))
const sameRate = computed(() => parseFloat(rateRange.value.min) === parseFloat(rateRange.value.max))

function fmt(v) {
  return v.toLocaleString('bg-BG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const fillPercent = computed(() =>
  Math.round(((Math.min(amount.value, max.value) - 50) / (max.value - 50)) * 100))
</script>

<template>
  <div class="rounded-2xl border border-gray-100 bg-white p-6">
    <h2 class="text-base font-bold text-navy-700 mb-1">Ами ако инвестираш…</h2>
    <div class="flex items-baseline gap-1 mb-3">
      <input
        :value="amount"
        @input="onAmountInput"
        @blur="onAmountBlur"
        type="text"
        inputmode="numeric"
        aria-label="Сума за проекция — въведи свободно"
        class="whatif-amount w-full max-w-[220px] text-3xl font-extrabold text-navy-700 tabular-nums bg-transparent border-0 border-b-2 border-dashed border-gray-200 focus:border-accent-400 focus:outline-none focus:ring-0 p-0"
      />
      <span class="text-base font-semibold text-gray-400">€</span>
    </div>

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

    <!-- The three payout structures — pick one, watch the number redraw.
         aria-pressed toggle buttons, NOT role=radio: buttons already honor
         Tab+Enter/Space natively, while radio semantics would promise an
         arrow-key roving-tabindex contract this widget doesn't implement. -->
    <div class="mt-4 grid grid-cols-3 gap-1.5" role="group" aria-label="План на изплащане">
      <button
        v-for="p in plans"
        :key="p.value"
        type="button"
        :aria-pressed="selectedPlan === p.value"
        @click="selectedPlan = p.value"
        class="rounded-xl border px-1 py-2 text-center transition-colors overflow-hidden"
        :class="selectedPlan === p.value
          ? 'border-accent-400 bg-accent-50 text-navy-700'
          : 'border-gray-200 text-gray-500 hover:border-accent-300'"
      >
        <!-- «Капитализация» is ~77px at 10px / 92px at 12px (real Inter
             metrics). The card is NARROWEST on lg–2xl laptops (right 1/3
             dashboard column ⇒ ~42–88px of pill text box at 1024–1440px), so
             the 12px upsize is safe only from 2xl; phones ≥360px fit 10px. -->
        <span class="block text-[10px] sm:text-xs lg:text-[10px] 2xl:text-xs font-semibold leading-tight">{{ p.label }}</span>
        <span class="block text-[11px] font-bold mt-0.5" :class="selectedPlan === p.value ? 'text-accent-500' : 'text-gray-400'">{{ planRateBadge(p.value) }}</span>
      </button>
    </div>

    <div class="mt-3 rounded-xl bg-accent-400/10 border border-accent-400/25 px-4 py-3">
      <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-0.5">Печалба за 12 месеца</p>
      <p class="text-xl font-extrabold text-accent-500 tabular-nums">
        <template v-if="sameRate">+{{ fmt(high) }} €</template>
        <template v-else>+{{ fmt(low) }} до +{{ fmt(high) }} €</template>
      </p>
      <p class="text-[11px] text-gray-400 mt-0.5">
        при годишна доходност
        <template v-if="sameRate">{{ parseFloat(rateRange.max) }}%</template>
        <template v-else>{{ parseFloat(rateRange.min) }}–{{ parseFloat(rateRange.max) }}%</template>
        («{{ activePlan.label }}» — {{ activePlan.hint }}) — проекция; реалният срок зависи от избрания кредит
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
