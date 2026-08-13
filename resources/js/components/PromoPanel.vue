<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api from '../api/axios'
import { formatCountdown, remainingMs } from '../utils/promoCountdown'

// Flash-promo panel (Reni 2026-08-14): «динамичен панел... кредити с висока
// доходност за събиране на пари за кратко време», казино усещане — тъмната
// карта е ЕДИНСТВЕНИЯТ тъмен елемент на таблото, зеленото сияние пулсира,
// звънчето звъни, броячът тик-така и почервенява в последните 5 минути.
// Renders nothing when no promo is running.
const promos = ref([])
const nowMs = ref(Date.now())
let tickTimer = null
let pollTimer = null

async function load() {
  try {
    const { data } = await api.get('/promotions/active')
    promos.value = data.promotions ?? []
  } catch {
    // Secondary UI — a failed poll must never break the dashboard.
  }
}

onMounted(() => {
  load()
  tickTimer = setInterval(() => { nowMs.value = Date.now() }, 1000)
  pollTimer = setInterval(load, 60_000)
})

onBeforeUnmount(() => {
  clearInterval(tickTimer)
  clearInterval(pollTimer)
})

const active = computed(() => promos.value
  .map(p => ({ ...p, remaining: remainingMs(p.ends_at, nowMs.value) }))
  .filter(p => p.remaining > 0))

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }

function rateText(p) {
  const r = p.loan?.offer_rate_range
  if (!r) return null
  return r[0] === r[1] ? `${parseFloat(r[1])}%` : `до ${parseFloat(r[1])}%`
}

function bonusText(p) {
  return `${parseFloat(p.bonus_percent)}%`
}

function isUrgent(p) {
  return p.remaining < 5 * 60_000
}
</script>

<template>
  <TransitionGroup v-if="active.length" tag="div" name="promo" class="space-y-3 mb-8">
    <div
      v-for="p in active"
      :key="p.id"
      class="promo-card relative overflow-hidden rounded-2xl px-5 py-4 text-white"
      :class="isUrgent(p) ? 'promo-urgent' : ''"
    >
      <!-- Diagonal shine sweep -->
      <div class="promo-shine pointer-events-none absolute inset-0"></div>

      <div class="relative flex flex-col sm:flex-row sm:items-center gap-4">
        <!-- Bell + title -->
        <div class="flex items-center gap-3 min-w-0">
          <div class="promo-bell flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent-400/20 text-accent-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
          </div>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-accent-400">⚡ Светкавична оферта</p>
            <p class="text-sm font-semibold text-white truncate">
              {{ typeLabels[p.loan.type] || p.loan.type }} кредит #{{ p.loan.id }}
              <span v-if="rateText(p)" class="text-accent-400">· доходност {{ rateText(p) }}</span>
            </p>
          </div>
        </div>

        <!-- Bonus badge -->
        <div class="promo-badge relative overflow-hidden rounded-xl bg-accent-400 px-3.5 py-2 text-center shrink-0 sm:ml-auto">
          <p class="text-sm font-extrabold leading-tight text-navy-700">+{{ bonusText(p) }} БОНУС</p>
          <p class="text-[10px] font-semibold uppercase tracking-wider text-navy-700/70">веднага при инвестиция</p>
        </div>

        <!-- Countdown + CTA -->
        <div class="flex items-center gap-4 shrink-0">
          <div class="text-center">
            <p
              class="text-2xl font-extrabold tabular-nums leading-none"
              :class="isUrgent(p) ? 'text-red-400' : 'text-white'"
            >{{ formatCountdown(p.remaining) }}</p>
            <p class="text-[10px] uppercase tracking-wider text-white/60 mt-0.5">до края</p>
          </div>
          <router-link
            :to="`/invest/${p.loan.id}`"
            class="promo-cta rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-navy-700 transition-transform hover:scale-105"
          >
            Инвестирай сега
          </router-link>
        </div>
      </div>
    </div>
  </TransitionGroup>
</template>

<style scoped>
.promo-card {
  background: linear-gradient(120deg, #16223c 0%, #1b2a4a 55%, #21365e 100%);
  box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.35), 0 0 24px rgba(34, 197, 94, 0.25);
  animation: promo-glow 2.6s ease-in-out infinite;
}

.promo-urgent {
  box-shadow: 0 0 0 1px rgba(248, 113, 113, 0.5), 0 0 26px rgba(248, 113, 113, 0.3);
  animation: promo-glow-urgent 1.1s ease-in-out infinite;
}

@keyframes promo-glow {
  0%, 100% { box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.35), 0 0 18px rgba(34, 197, 94, 0.18); }
  50% { box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.6), 0 0 34px rgba(34, 197, 94, 0.38); }
}

@keyframes promo-glow-urgent {
  0%, 100% { box-shadow: 0 0 0 1px rgba(248, 113, 113, 0.45), 0 0 20px rgba(248, 113, 113, 0.22); }
  50% { box-shadow: 0 0 0 1px rgba(248, 113, 113, 0.8), 0 0 38px rgba(248, 113, 113, 0.45); }
}

/* Diagonal shine sweeping across the card every few seconds */
.promo-shine::after {
  content: '';
  position: absolute;
  top: -60%;
  bottom: -60%;
  width: 34%;
  left: -40%;
  transform: skewX(-18deg);
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.09), transparent);
  animation: promo-sweep 3.8s ease-in-out infinite;
}

@keyframes promo-sweep {
  0% { left: -40%; }
  55%, 100% { left: 120%; }
}

/* Bell ring — a short double swing, then rest */
.promo-bell {
  transform-origin: top center;
  animation: promo-ring 2.4s ease-in-out infinite;
}

@keyframes promo-ring {
  0%, 60%, 100% { transform: rotate(0deg); }
  64% { transform: rotate(12deg); }
  70% { transform: rotate(-10deg); }
  76% { transform: rotate(6deg); }
  82% { transform: rotate(-4deg); }
  88% { transform: rotate(0deg); }
}

/* Shimmer on the bonus badge */
.promo-badge::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(110deg, transparent 30%, rgba(255, 255, 255, 0.5) 50%, transparent 70%);
  background-size: 220% 100%;
  animation: promo-shimmer 2.2s linear infinite;
}

@keyframes promo-shimmer {
  0% { background-position: 130% 0; }
  100% { background-position: -110% 0; }
}

/* Entry / exit */
.promo-enter-active { transition: all 0.5s cubic-bezier(0.22, 1, 0.36, 1); }
.promo-leave-active { transition: all 0.4s ease; }
.promo-enter-from { opacity: 0; transform: translateY(-14px) scale(0.98); }
.promo-leave-to { opacity: 0; transform: translateY(-8px) scale(0.98); }

@media (prefers-reduced-motion: reduce) {
  .promo-card, .promo-urgent, .promo-bell, .promo-shine::after, .promo-badge::after {
    animation: none;
  }
}
</style>
