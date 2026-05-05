<script setup>
import { useDocumentMeta } from '../composables/useDocumentMeta'
import { useAuthStore } from '../stores/auth'
import LandingHeader from '../components/landing/LandingHeader.vue'
import HeroSection from '../components/landing/HeroSection.vue'
import StatsBar from '../components/landing/StatsBar.vue'
import HowItWorks from '../components/landing/HowItWorks.vue'
import WhyUs from '../components/landing/WhyUs.vue'
import InvestmentOpportunities from '../components/landing/InvestmentOpportunities.vue'
// Originator names + buyback policies are commercial info we only want to
// show to people who've passed the registration gate. Guests get the
// non-originator version of the landing; authenticated users see the
// originators block on the same scroll.
import OriginatorsSection from '../components/landing/OriginatorsSection.vue'
import FaqSection from '../components/landing/FaqSection.vue'
import CtaSection from '../components/landing/CtaSection.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'

const auth = useAuthStore()

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
    <OriginatorsSection v-if="auth.isAuthenticated" />
    <FaqSection />
    <CtaSection />
    <LandingFooter />
  </div>
</template>
