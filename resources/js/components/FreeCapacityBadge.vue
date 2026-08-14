<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { countUpProgress } from '../utils/earningsTicker'

// «Свободни за инвестиране» — the big clear figure Reni asked for at the X
// spot (2026-08-14), dressed up: live pulsing dot, count-up intro (same
// psychology as the dashboard ticker), a periodic shine over the number, and
// a SCARCITY flip — under 20 % of the pool left it turns amber and reads
// «Остават само …» («да ги провокира, че може нещо да изпуснат»).
const props = defineProps({
  remaining: { type: String, required: true },
  cap: { type: String, required: true },
})

const INTRO_MS = 900

const progress = ref(0)
let rafId = null
let settleTimer = null

onMounted(() => {
  const start = performance.now()
  const animate = (t) => {
    progress.value = countUpProgress(t - start, INTRO_MS)
    if (t - start < INTRO_MS) {
      rafId = requestAnimationFrame(animate)
    }
  }
  rafId = requestAnimationFrame(animate)
  // rAF starves in hidden tabs — make sure the number always lands.
  settleTimer = setTimeout(() => { progress.value = 1 }, INTRO_MS + 250)
})

onBeforeUnmount(() => {
  if (rafId) cancelAnimationFrame(rafId)
  clearTimeout(settleTimer)
})

const remainingNum = computed(() => parseFloat(props.remaining) || 0)
const capNum = computed(() => parseFloat(props.cap) || 0)

// Under 20 % of the investable pool left → scarcity mode.
const scarce = computed(() => capNum.value > 0 && remainingNum.value / capNum.value <= 0.2)

const display = computed(() =>
  (remainingNum.value * Math.min(1, progress.value)).toLocaleString('bg-BG', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }))
</script>

<template>
  <div class="free-chip" :class="scarce ? 'free-chip--scarce' : ''">
    <div class="flex items-center justify-end gap-1.5">
      <span class="free-dot" aria-hidden="true"></span>
      <span
        class="text-[11px] font-bold uppercase tracking-wider"
        :class="scarce ? 'text-amber-600' : 'text-accent-500'"
      >{{ scarce ? 'Остават само' : 'Свободни за инвестиране' }}</span>
    </div>
    <p
      class="free-amount relative overflow-hidden text-3xl font-extrabold tabular-nums leading-tight text-right"
      :class="scarce ? 'text-amber-600' : 'text-accent-500'"
    >
      {{ display }} <span class="text-base font-bold opacity-60">€</span>
    </p>
  </div>
</template>

<style scoped>
.free-chip {
  border-radius: 0.75rem;
  padding: 0.5rem 0.9rem 0.6rem;
  background: linear-gradient(135deg, rgba(34, 197, 94, 0.08), rgba(34, 197, 94, 0.16));
  border: 1px solid rgba(34, 197, 94, 0.35);
  animation: free-breathe 3s ease-in-out infinite;
}

.free-chip--scarce {
  background: linear-gradient(135deg, rgba(217, 119, 6, 0.08), rgba(217, 119, 6, 0.16));
  border-color: rgba(217, 119, 6, 0.4);
  animation: free-breathe-scarce 1.6s ease-in-out infinite;
}

@keyframes free-breathe {
  0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
  50% { box-shadow: 0 0 16px 0 rgba(34, 197, 94, 0.22); }
}

@keyframes free-breathe-scarce {
  0%, 100% { box-shadow: 0 0 0 0 rgba(217, 119, 6, 0); }
  50% { box-shadow: 0 0 16px 0 rgba(217, 119, 6, 0.28); }
}

/* Live pulsing dot */
.free-dot {
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background: currentColor;
  color: #22c55e;
  animation: free-pulse 1.6s ease-in-out infinite;
}

.free-chip--scarce .free-dot {
  color: #d97706;
}

@keyframes free-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45); }
  70% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
}

/* Periodic shine sweeping over the amount */
.free-amount::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, 0.55) 50%, transparent 65%);
  background-size: 260% 100%;
  animation: free-shine 4.2s ease-in-out infinite;
  pointer-events: none;
}

@keyframes free-shine {
  0%, 55% { background-position: 130% 0; }
  85%, 100% { background-position: -130% 0; }
}

@media (prefers-reduced-motion: reduce) {
  .free-chip, .free-chip--scarce, .free-dot, .free-amount::after {
    animation: none;
  }
}
</style>
