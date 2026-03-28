<script setup>
import LandingHeader from '../components/landing/LandingHeader.vue'
import HeroSection from '../components/landing/HeroSection.vue'
import StatsBar from '../components/landing/StatsBar.vue'
import HowItWorks from '../components/landing/HowItWorks.vue'
import WhyUs from '../components/landing/WhyUs.vue'
import InvestmentOpportunities from '../components/landing/InvestmentOpportunities.vue'
import OriginatorsSection from '../components/landing/OriginatorsSection.vue'
import FaqSection from '../components/landing/FaqSection.vue'
import CtaSection from '../components/landing/CtaSection.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">
    <LandingHeader />
    <HeroSection />
    <StatsBar />
    <HowItWorks />
    <WhyUs />
    <InvestmentOpportunities />
    <OriginatorsSection />
    <FaqSection />
    <CtaSection />
    <LandingFooter />
  </div>
</template>
