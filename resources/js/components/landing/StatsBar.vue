<script setup>
// ─────────────────────────────────────────────────────────────────────────────
// Pre-launch state: platform has no real investors / volume yet, so the four
// hard-coded stats below were misleading. Until we have real numbers from the
// production database, this section displays motivational quotes instead.
//
// To re-enable when real stats exist:
//   1. Uncomment the script + animation below.
//   2. Swap the `<template>` block back to the count-up grid.
//   3. Wire `platformStats` to a backend endpoint (e.g. /api/public/stats)
//      that returns Σ-deposits, distinct user_id count, etc.
// ─────────────────────────────────────────────────────────────────────────────

/* DISABLED — pre-launch
import { ref, onMounted, onUnmounted } from 'vue'

const statsRef = ref(null)
const statsVisible = ref(false)
const animatedValues = ref([0, 0, 0, 0])
let observer = null

const platformStats = [
  { target: 2.4, suffix: 'M €', label: 'инвестирани', decimals: 1 },
  { target: 1200, suffix: '+', label: 'инвеститори', decimals: 0 },
  { target: 350, suffix: '+', label: 'финансирани кредита', decimals: 0 },
  { target: 0, suffix: '%', label: 'загуби до момента', decimals: 0 },
]

function animateCountUp() {
  const duration = 2000
  const start = performance.now()
  function tick(now) {
    const elapsed = Math.min((now - start) / duration, 1)
    const ease = 1 - Math.pow(1 - elapsed, 3) // easeOutCubic
    animatedValues.value = platformStats.map((s) =>
      s.decimals > 0
        ? parseFloat((s.target * ease).toFixed(s.decimals))
        : Math.round(s.target * ease)
    )
    if (elapsed < 1) requestAnimationFrame(tick)
  }
  requestAnimationFrame(tick)
}

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting && !statsVisible.value) {
        statsVisible.value = true
        animateCountUp()
      }
    },
    { threshold: 0.3 }
  )
  if (statsRef.value) observer.observe(statsRef.value)
})

onUnmounted(() => {
  observer?.disconnect()
})
*/
</script>

<template>
  <section class="bg-navy-700">
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-16 sm:py-20 text-center">
      <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="currentColor"
        class="size-8 text-accent-400 mx-auto mb-6 opacity-80"
        aria-hidden="true"
      >
        <path d="M4.583 17.321C3.553 16.227 3 15 3 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 0 1-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179zm10 0C13.553 16.227 13 15 13 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 0 1-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179z"/>
      </svg>
      <p class="text-2xl sm:text-3xl font-semibold text-white leading-relaxed tracking-tight">
        Най-добрият момент да започнеш да инвестираш беше преди години.
        <span class="text-accent-400">Вторият най-добър — е сега.</span>
      </p>
      <p class="mt-4 text-sm text-navy-200">— китайска поговорка</p>
    </div>
  </section>
</template>
