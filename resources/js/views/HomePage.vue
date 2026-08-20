<script setup>
import { onMounted, nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDocumentMeta } from '../composables/useDocumentMeta'
import { sectionScrollTop } from '../utils/landingNav'
import LandingHeader from '../components/landing/LandingHeader.vue'
import HeroSection from '../components/landing/HeroSection.vue'
import StatsBar from '../components/landing/StatsBar.vue'
import HowItWorks from '../components/landing/HowItWorks.vue'
import WhyUs from '../components/landing/WhyUs.vue'
// «Кредити» и «Оригинатори» стоят и тук, в потока на страницата (Йордан
// 2026-08-20: «трябва да е и на самата страница, не да цъкам менюто»), и на
// собствените си адреси /loans и /originators — един и същ компонент, за да
// няма два текста. Тези секции НЕ заместват старите с измислени данни: те не
// зареждат нищо и се съобразяват кой гледа.
import LoansSection from '../components/landing/LoansSection.vue'
import OriginatorsSection from '../components/landing/OriginatorsSection.vue'
import FaqSection from '../components/landing/FaqSection.vue'
import CtaSection from '../components/landing/CtaSection.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'

useDocumentMeta({
  // Homepage uses the document defaults (set in app.blade.php), so we just
  // pin the canonical path and let the title/description fall through.
  path: '/',
})

const route = useRoute()

// Section links live in the header/footer, which now also render on /loans and
// /originators — from there they navigate here as «/» + hash. The scrolling is
// done locally instead of through a global router scrollBehavior, which would
// take native scroll restoration away from the entire SPA (see router/index.js).
async function scrollToSection(hash) {
  if (!hash) return

  // Await the render so the sections exist and are laid out. nextTick, not
  // requestAnimationFrame: rAF does not fire in a hidden/background tab, which
  // would leave the visitor at the top of the page when they switch to it.
  await nextTick()

  // getElementById, not querySelector: a hand-typed «/#1» is not a valid CSS
  // selector and would throw.
  const target = document.getElementById(hash.slice(1))
  if (!target) return

  window.scrollTo({
    top: sectionScrollTop(target.getBoundingClientRect().top, window.scrollY),
    behavior: 'smooth',
  })
}

// On mount: arriving from a subpage. On watch: clicking a section link while
// already here (the component stays mounted, only the hash changes).
onMounted(() => scrollToSection(route.hash))
watch(() => route.hash, scrollToSection)
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">
    <LandingHeader />
    <HeroSection />
    <StatsBar />
    <HowItWorks />
    <WhyUs />
    <LoansSection />
    <OriginatorsSection />
    <FaqSection />
    <CtaSection />
    <LandingFooter />
  </div>
</template>
