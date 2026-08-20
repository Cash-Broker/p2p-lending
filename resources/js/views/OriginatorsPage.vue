<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useDocumentMeta } from '../composables/useDocumentMeta'
import { publicPageState, STATE_GUEST, STATE_ADMIN } from '../utils/publicGate'
import LandingHeader from '../components/landing/LandingHeader.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'
import GatedNotice from '../components/landing/GatedNotice.vue'

// «Оригинатори» in the public nav (Reni 2026-08-20): a guest gets the same
// register-first gate as /loans, a registered investor gets an explanation of
// what an originator is. NO data is loaded for anyone — there is no public
// partner directory yet, and the placeholder cards that used to sit on the
// landing page were removed on purpose (commit 7d5d0ed) precisely because
// they were invented. Wording follows the Общи условия definition verbatim,
// so the marketing copy and the contract cannot drift apart.
const auth = useAuthStore()

const state = computed(() => publicPageState(auth.user))
const isGuest = computed(() => state.value === STATE_GUEST)
const isAdmin = computed(() => state.value === STATE_ADMIN)

useDocumentMeta({
  title: 'Оригинатори',
  description: 'Оригинаторите са небанковите финансови институции, които отпускат и обслужват кредитите във Vamaasset.',
  path: '/originators',
})

const facts = [
  {
    title: 'Лицензирани партньори',
    // Общи условия, т. «Оригинатор» — same formulation, deliberately.
    text: 'Оригинаторът е лицензирана небанкова финансова институция, вписана в регистъра на БНБ по чл. 3а от Закона за кредитните институции. Той отпуска кредита и го обслужва до последната вноска.',
  },
  {
    title: 'Buyback, когато е договорен',
    text: 'При част от партньорите вземането се изкупува обратно, ако кредитът закъснее над определен брой дни. Дали конкретният кредит има buyback, е изписано в самия кредит.',
  },
  {
    title: 'Условията са ясни предварително',
    text: 'Оригинаторът, срокът, лихвата и рисковият клас се виждат в кредита, преди да инвестираш — не след това.',
  },
]
</script>

<template>
  <div class="min-h-screen bg-gray-50/50 font-sans text-gray-700 antialiased">
    <LandingHeader />

    <main class="pt-16">
      <section class="pt-16 pb-20 sm:pt-24 sm:pb-28">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
          <p class="text-center text-sm font-semibold text-accent-500 uppercase tracking-wider mb-6">Оригинатори</p>

          <!-- Guest: same rule as /loans — nothing loads, register first.
               CTA comes from GatedNotice's default slot. -->
          <GatedNotice
            v-if="isGuest"
            title="Партньорите се виждат след регистрация"
            lead="Информацията за оригинаторите, с които работим, е достъпна за регистрирани инвеститори."
            icon="lock"
          />

          <!-- Registered: an explanation, not a list. -->
          <template v-else>
            <h1 class="text-center text-3xl sm:text-4xl font-bold text-navy-700">Кой стои зад кредитите</h1>
            <p class="mt-4 text-center text-gray-500 leading-relaxed">
              Оригинаторът е финансовата институция, която отпуска кредита на крайния клиент и го обслужва.
              Vamaasset подбира партньорите и структурира сделките, за да инвестираш във вече отпуснат кредит,
              вместо сам да търсиш и оценяваш длъжници.
            </p>

            <div class="mt-12 space-y-4">
              <div v-for="fact in facts" :key="fact.title" class="rounded-2xl border border-gray-100 bg-white p-6">
                <div class="flex items-start gap-4">
                  <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent-50 text-accent-500">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                  </div>
                  <div>
                    <p class="font-semibold text-navy-700">{{ fact.title }}</p>
                    <p class="mt-2 text-sm text-gray-500 leading-relaxed">{{ fact.text }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-8 rounded-3xl bg-navy-700 px-8 py-10 text-center">
              <p class="text-lg font-semibold text-white">Профилите на партньорите още не са публични</p>
              <p class="mt-3 text-sm text-navy-200 leading-relaxed max-w-xl mx-auto">
                Подготвяме ги — с име, тип кредити и условията по buyback. Дотогава оригинаторът на всеки
                кредит е изписан в самия кредит.
              </p>
              <a
                v-if="isAdmin"
                href="/admin"
                class="mt-7 inline-flex items-center px-7 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm"
              >
                Към администрацията
              </a>
              <router-link
                v-else
                to="/dashboard"
                class="mt-7 inline-flex items-center px-7 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm"
              >
                Към таблото
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-2 size-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
              </router-link>
            </div>
          </template>
        </div>
      </section>
    </main>

    <LandingFooter />
  </div>
</template>
