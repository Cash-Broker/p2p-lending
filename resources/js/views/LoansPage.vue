<script setup>
import { useDocumentMeta } from '../composables/useDocumentMeta'
import LandingHeader from '../components/landing/LandingHeader.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'
import LoansSection from '../components/landing/LoansSection.vue'

// Standalone /loans — the same section the homepage renders, on its own page
// with its own canonical URL (deep links, the sitemap, and the «Кредити» item
// for anyone who lands here directly). The section itself decides what each
// audience sees; nothing is fetched for a guest.
useDocumentMeta({
  title: 'Кредити',
  description: 'Кредитите във Vamaasset са достъпни за регистрирани инвеститори. Създай профил, за да получиш достъп.',
  path: '/loans',
})
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">
    <LandingHeader />

    <main class="pt-16">
      <!-- The only heading on this page, so the gate title is the h1 here. -->
      <LoansSection heading-level="h1" />
    </main>

    <LandingFooter />
  </div>
</template>
