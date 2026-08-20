<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { publicPageState, STATE_GUEST, STATE_ADMIN } from '../../utils/publicGate'
import GatedNotice from './GatedNotice.vue'

// «Оригинатори» — rendered BOTH as a section of the landing page and as the
// body of /originators. A guest gets the same register-first gate as the loans
// section; a registered visitor gets an explanation of who is behind the
// loans. NO data is loaded for anyone: there is no public partner directory
// yet, and the placeholder cards that used to live under this name (invented
// company names and yields) were deleted on purpose — do not bring them back.
// The definition below is quoted from Общи условия so the marketing copy and
// the contract cannot drift apart.
defineProps({
  /** h1 on the standalone page; h2 inside the landing flow. */
  headingLevel: { type: String, default: 'h2' },
})

const auth = useAuthStore()

const state = computed(() => publicPageState(auth.user))
const isGuest = computed(() => state.value === STATE_GUEST)
const isAdmin = computed(() => state.value === STATE_ADMIN)

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
  <section id="originators" class="py-20 sm:py-28 bg-gray-50/50 scroll-mt-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <p class="text-center text-sm font-semibold text-accent-500 uppercase tracking-wider mb-6">Оригинатори</p>

      <!-- Guest: same rule as the loans section — nothing loads, register first. -->
      <GatedNotice
        v-if="isGuest"
        :heading-level="headingLevel"
        title="Партньорите се виждат след регистрация"
        lead="Информацията за оригинаторите, с които работим, е достъпна за регистрирани инвеститори."
        icon="lock"
      />

      <!-- Registered: an explanation, not a list. -->
      <template v-else>
        <component :is="headingLevel" class="text-center text-3xl sm:text-4xl font-bold text-navy-700">
          Кой стои зад кредитите
        </component>
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
            to="/invest"
            class="mt-7 inline-flex items-center px-7 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm"
          >
            Разгледай кредитите
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-2 size-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
          </router-link>
        </div>
      </template>
    </div>
  </section>
</template>
