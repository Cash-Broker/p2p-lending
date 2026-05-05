<script setup>
import { useDocumentMeta } from '../composables/useDocumentMeta'
import LandingHeader from '../components/landing/LandingHeader.vue'
import HeroSection from '../components/landing/HeroSection.vue'
import StatsBar from '../components/landing/StatsBar.vue'
import HowItWorks from '../components/landing/HowItWorks.vue'
import WhyUs from '../components/landing/WhyUs.vue'
import InvestmentOpportunities from '../components/landing/InvestmentOpportunities.vue'
// OriginatorsSection is intentionally NOT rendered on the public landing —
// originator info (names, volumes, buyback policies) is gated behind
// authentication. Authenticated investors see real originators on /invest
// and /invest/:id. The component file is kept so it can be re-imported on
// a "/originators" private page later if we add one.
// import OriginatorsSection from '../components/landing/OriginatorsSection.vue'
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
    <InvestmentOpportunities />
    <!-- <OriginatorsSection /> auth-gated; see import block above -->
    <FaqSection />
    <CtaSection />
    <LandingFooter />
  </div>
</template>
