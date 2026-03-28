<script setup>
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
</script>

<template>
  <section ref="statsRef" class="bg-navy-700">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-14 sm:py-16">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-4">
        <div v-for="(stat, i) in platformStats" :key="i" class="text-center">
          <p class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white tracking-tight">
            {{ animatedValues[i] }}{{ stat.suffix }}
          </p>
          <p class="mt-2 text-sm sm:text-base text-navy-200">{{ stat.label }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
