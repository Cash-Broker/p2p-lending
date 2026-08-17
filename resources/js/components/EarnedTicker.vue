<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { animatedRateText, countUpProgress, formatEuro, splitEuroParts, tickerValue } from '../utils/earningsTicker'

// «Текуща печалба» (client decisions 2026-08-13/14, Reni): the green
// stock-ticker of schedule-accrued interest not yet paid out. Always live —
// the big number ticks every second («стои много борсово») while the badges
// show the daily AND hourly pace at once («хем на час, хем на ден», no admin
// variant switch). On load the numbers count up in two stages («засече ли,
// че е влязъл, първо върти едното, после другото» — psychology on purpose).
// The «Спечелени» / «Изтеглени» buttons open the lifetime totals without
// cluttering the front. Informational only — no money moves here.
const props = defineProps({
  accrual: { type: Object, required: true },
  lifetime: { type: Object, default: null },
})

const INTRO_MS = 1100 // stage 1: the big number counts up
const BADGES_MS = 600 // stage 2: the pace badges count up after it

// Anchor on the client clock at mount: the backend amount is a snapshot at
// `as_of`, and ticking from "received now" avoids server/client clock-skew
// jumps. Worst case the ticker starts a request-latency behind — invisible.
const anchorMs = Date.now()
const nowMs = ref(anchorMs)
const introProgress = ref(0)
const badgeProgress = ref(0)
const showTotals = ref(false)

let timer = null
let rafId = null

onMounted(() => {
  const start = performance.now()
  const animate = (t) => {
    const elapsed = t - start
    introProgress.value = countUpProgress(elapsed, INTRO_MS)
    badgeProgress.value = countUpProgress(elapsed - INTRO_MS, BADGES_MS)
    if (elapsed < INTRO_MS + BADGES_MS) {
      rafId = requestAnimationFrame(animate)
    }
  }
  rafId = requestAnimationFrame(animate)
  timer = setInterval(() => {
    nowMs.value = Date.now()
    // rAF starves in hidden/background tabs — if the intro deadline passed
    // without the final frame, snap both stages to done so the widget never
    // sticks mid-count-up.
    if (performance.now() - start >= INTRO_MS + BADGES_MS) {
      introProgress.value = 1
      badgeProgress.value = 1
    }
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
  if (rafId) cancelAnimationFrame(rafId)
})

const liveValue = computed(() =>
  tickerValue(props.accrual.amount_live, props.accrual.per_second_rate, nowMs.value - anchorMs))

// Reni's final spec (2026-08-18, Катя-случаят — «106,0035» прочетено като
// 106 хиляди): whole euros big, a DOT, стотинки small — «махни стотните
// след 1.82», no micro-digits. The number now moves only when a real
// стотинка accrues; the daily/hourly badges carry the "alive" feel.
const displayParts = computed(() => splitEuroParts(liveValue.value * introProgress.value))

const dailyBadge = computed(() => animatedRateText(props.accrual.daily_rate, badgeProgress.value))
const hourlyBadge = computed(() => animatedRateText(props.accrual.hourly_rate, badgeProgress.value))

// Lifetime figures for the modal. «Спечелени (общо)» = everything earned
// since investing = paid out interest + the currently accruing profit.
const paidOut = computed(() => props.lifetime ? formatEuro(parseFloat(props.lifetime.earned_paid) || 0) : null)
const withdrawn = computed(() => props.lifetime ? formatEuro(parseFloat(props.lifetime.withdrawn_total) || 0) : null)
const lifetimeEarned = computed(() => {
  if (!props.lifetime) return null
  return formatEuro((parseFloat(props.lifetime.earned_paid) || 0) + liveValue.value)
})
</script>

<template>
  <div class="rounded-2xl border border-gray-100 bg-white px-5 py-4 sm:min-w-[260px]">
    <span class="text-xs font-medium text-gray-400 uppercase tracking-wider">Текуща печалба</span>
    <p class="mt-1 text-3xl font-bold text-accent-500 tabular-nums">
      {{ displayParts.main }}<span class="text-base font-semibold text-accent-500/50 tabular-nums">.{{ displayParts.micro }}</span> <span class="text-sm font-medium text-accent-500/70">€</span>
    </p>
    <div class="mt-1 flex items-center gap-3 min-h-4">
      <span v-if="dailyBadge" class="text-xs font-semibold text-accent-500 tabular-nums">{{ dailyBadge }} € / ден</span>
      <span v-if="hourlyBadge" class="text-xs font-semibold text-accent-500/80 tabular-nums">{{ hourlyBadge }} € / час</span>
    </div>

    <!-- Front-of-dashboard totals (Reni: «спечелени да бъдат в черно и
         изтеглени да е в червено, отпред на информационното табло») — shown
         as obviously-clickable pills; the modal holds the breakdown. -->
    <div v-if="lifetime" class="mt-3 pt-3 border-t border-gray-50 flex flex-wrap items-center gap-2">
      <button
        type="button"
        @click="showTotals = true"
        class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-semibold text-navy-700 hover:bg-gray-50 hover:border-gray-300 transition-colors"
      >
        Спечелени <span class="tabular-nums">{{ lifetimeEarned }} €</span>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-3 text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
      </button>
      <button
        type="button"
        @click="showTotals = true"
        class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-semibold text-red-500 hover:bg-red-50 hover:border-red-200 transition-colors"
      >
        Изтеглени <span class="tabular-nums">{{ withdrawn }} €</span>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-3 text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
      </button>
    </div>

    <Teleport to="body">
      <div v-if="showTotals" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-navy-700/40" @click="showTotals = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-sm p-6" role="dialog" aria-modal="true" aria-label="Обобщение на печалбата">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-bold text-navy-700">Обобщение</h3>
            <button type="button" @click="showTotals = false" class="text-gray-400 hover:text-navy-700" aria-label="Затвори">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="space-y-4 text-sm">
            <div>
              <div class="flex items-center justify-between">
                <span class="text-gray-500">Спечелени (общо)</span>
                <span class="font-bold text-accent-500 tabular-nums">{{ lifetimeEarned }} €</span>
              </div>
              <p class="text-xs text-gray-400 mt-1">Изплатени {{ paidOut }} € + текущата печалба</p>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-gray-500">Изтеглени (общо)</span>
              <span class="font-bold text-navy-700 tabular-nums">{{ withdrawn }} €</span>
            </div>
            <p class="text-[11px] text-gray-400 pt-1 border-t border-gray-50">
              Текущата печалба се изплаща автоматично по сметката на падежите от погасителния план.
            </p>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
