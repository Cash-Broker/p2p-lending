<script setup>
import { computed } from 'vue'

/**
 * Бонус в изчакване (Рени 2026-08-18).
 *
 * Показва начисления, но още незаключен бонус и КАКВО остава до него —
 * сумата се вижда в профила, така че условието трябва да стои до нея, а не
 * в имейл отпреди месец. Рендерира се само когато има заключен бонус.
 */
const props = defineProps({
  bonus: { type: Object, required: true },
})

function formatAmount(val) {
  return parseFloat(val).toLocaleString('bg-BG', { minimumFractionDigits: 2 })
}

const progress = computed(() => {
  const base = parseFloat(props.bonus.base_amount)
  if (!base) return 0
  const done = parseFloat(props.bonus.qualified_amount)
  return Math.min(100, Math.max(0, Math.round((done / base) * 100)))
})

const remaining = computed(() => parseFloat(props.bonus.remaining_amount) > 0)
</script>

<template>
  <div class="rounded-2xl border border-gray-100 bg-white p-5 mb-6">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
      <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v13m0-13V6a2 2 0 1 1 2 2h-2Zm0 0V5.5A2.5 2.5 0 1 0 9.5 8H12ZM5 12h14M5 12a2 2 0 1 1 0-4h14a2 2 0 1 1 0 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" />
        </svg>
      </div>

      <div class="min-w-0">
        <p class="text-sm font-bold text-navy-700">
          Бонус в изчакване: {{ formatAmount(bonus.amount) }} €
        </p>
        <p class="text-xs text-gray-500 mt-0.5">
          <template v-if="remaining">
            Инвестирайте още {{ formatAmount(bonus.remaining_amount) }} € и след
            {{ bonus.required_installments }} погашения бонусът става свободен.
          </template>
          <template v-else>
            Сумата е налице — бонусът се освобождава след
            {{ bonus.required_installments }} погашения по инвестициите.
          </template>
        </p>
      </div>

      <router-link
        v-if="remaining"
        to="/marketplace"
        class="ml-auto shrink-0 rounded-xl bg-navy-700 px-4 py-2 text-xs font-semibold text-white hover:bg-navy-800 transition"
      >
        Виж кредитите
      </router-link>
    </div>

    <!-- Progress: инвестирано от изискваното -->
    <div class="mt-4">
      <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
        <div class="h-full rounded-full bg-accent-400 transition-all duration-700" :style="{ width: progress + '%' }"></div>
      </div>
      <p class="mt-1.5 text-[11px] text-gray-400">
        {{ formatAmount(bonus.qualified_amount) }} от {{ formatAmount(bonus.base_amount) }} € с навършени погашения
      </p>
    </div>
  </div>
</template>
