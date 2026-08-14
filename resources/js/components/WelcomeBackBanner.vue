<script setup>
import { ref } from 'vue'

// «Докато те нямаше…» (engagement pack 2026-08-14): rewards the RETURN
// itself — the strongest retention hook. Server sends the block only when
// the visit gap ≥ 6 h AND there is real news; everything shown is exact
// ledger truth. Dismiss lives only in memory — next qualifying visit brings
// a fresh banner.
const props = defineProps({
  data: { type: Object, required: true },
})

const dismissed = ref(false)

const typeLabels = { consumer: 'Потребителски', business: 'Бизнес', mortgage: 'Ипотечен', bridge: 'Мостов' }

function formatAmount(val) {
  return (parseFloat(val) || 0).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}
</script>

<template>
  <Transition name="wb">
    <div v-if="!dismissed" class="wb-banner relative overflow-hidden rounded-2xl border border-accent-400/30 bg-white px-5 py-4 mb-8">
      <div class="wb-shine pointer-events-none absolute inset-0" aria-hidden="true"></div>

      <div class="relative flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="min-w-0">
          <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-accent-500 mb-1">👋 Докато те нямаше</p>
          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-navy-700">
            <span v-if="parseFloat(data.received) > 0" class="font-bold text-accent-500 text-base">
              +{{ formatAmount(data.received) }} € постъпления
            </span>
            <span v-if="data.new_promos > 0" class="font-semibold">
              ⚡ {{ data.new_promos === 1 ? 'Нова промо оферта те чака' : `${data.new_promos} нови промо оферти те чакат` }}
            </span>
            <span v-if="data.hot_loan" class="font-semibold">
              🔥 {{ typeLabels[data.hot_loan.type] || data.hot_loan.type }} кредит #{{ data.hot_loan.id }} е на
              {{ data.hot_loan.funded_percentage }}% — остават {{ formatAmount(data.hot_loan.free) }} €
            </span>
          </div>
        </div>

        <router-link
          v-if="data.hot_loan"
          :to="`/invest/${data.hot_loan.id}`"
          class="sm:ml-auto shrink-0 rounded-xl bg-accent-400 hover:bg-accent-500 px-4 py-2 text-sm font-bold text-white transition-colors"
        >
          Виж кредита
        </router-link>
        <router-link
          v-else
          to="/invest"
          class="sm:ml-auto shrink-0 rounded-xl border border-navy-700 px-4 py-2 text-sm font-bold text-navy-700 hover:bg-navy-50 transition-colors"
        >
          Инвестирай
        </router-link>

        <button
          type="button"
          @click="dismissed = true"
          aria-label="Скрий"
          class="absolute -top-1 -right-1 sm:static sm:shrink-0 text-gray-400 hover:text-navy-700 transition-colors"
        >
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
        </button>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.wb-banner {
  box-shadow: 0 0 18px rgba(34, 197, 94, 0.12);
}

.wb-shine::after {
  content: '';
  position: absolute;
  top: -60%;
  bottom: -60%;
  width: 30%;
  left: -35%;
  transform: skewX(-18deg);
  background: linear-gradient(90deg, transparent, rgba(34, 197, 94, 0.07), transparent);
  animation: wb-sweep 5s ease-in-out infinite;
}

@keyframes wb-sweep {
  0% { left: -35%; }
  60%, 100% { left: 120%; }
}

.wb-enter-active { transition: all 0.5s cubic-bezier(0.22, 1, 0.36, 1); }
.wb-leave-active { transition: all 0.3s ease; }
.wb-enter-from, .wb-leave-to { opacity: 0; transform: translateY(-10px); }

@media (prefers-reduced-motion: reduce) {
  .wb-shine::after { animation: none; }
}
</style>
