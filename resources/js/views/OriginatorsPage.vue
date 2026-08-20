<script setup>
import { useDocumentMeta } from '../composables/useDocumentMeta'
import LandingHeader from '../components/landing/LandingHeader.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'
import OriginatorsSection from '../components/landing/OriginatorsSection.vue'

// Standalone /originators — same section the homepage renders, on its own
// canonical URL. No data is loaded for anyone; see OriginatorsSection.vue.
useDocumentMeta({
  title: 'Оригинатори',
  description: 'Оригинаторите са небанковите финансови институции, които отпускат и обслужват кредитите във Vamaasset.',
  path: '/originators',
})
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">
    <LandingHeader />

    <main class="pt-16">
      <!-- The only heading on this page, so the section title is the h1 here. -->
      <OriginatorsSection heading-level="h1" />
    </main>

    <LandingFooter />
  </div>
</template>
