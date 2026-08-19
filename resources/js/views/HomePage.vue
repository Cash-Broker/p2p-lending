<script setup>
import { useDocumentMeta } from '../composables/useDocumentMeta'
import LandingHeader from '../components/landing/LandingHeader.vue'
import HeroSection from '../components/landing/HeroSection.vue'
import StatsBar from '../components/landing/StatsBar.vue'
import HowItWorks from '../components/landing/HowItWorks.vue'
import WhyUs from '../components/landing/WhyUs.vue'
// «Инвестиционни възможности» и «Партньори» са свалени от лендинга
// (Йордан 2026-08-19) — за всички посетители, без значение дали са
// регистрирани. Компонентите остават в repo-то, но никъде не се рендерират.
import FaqSection from '../components/landing/FaqSection.vue'
import CtaSection from '../components/landing/CtaSection.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'

useDocumentMeta({
  // Homepage uses the document defaults (set in app.blade.php), so we just
  // pin the canonical path and let the title/description fall through.
  path: '/',
})
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">
    <LandingHeader />
    <HeroSection />
    <StatsBar />
    <HowItWorks />
    <WhyUs />
    <FaqSection />
    <CtaSection />
    <LandingFooter />
  </div>
</template>
