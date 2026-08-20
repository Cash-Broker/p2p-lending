<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { publicPageState, awaitsKycUpload, STATE_GUEST, STATE_ADMIN, STATE_APPROVED, STATE_REJECTED } from '../../utils/publicGate'
import GatedNotice from './GatedNotice.vue'

// «Кредити» — rendered BOTH as a section of the landing page (Йордан
// 2026-08-20: «трябва да е и на самата страница, не да цъкам менюто») and as
// the body of the standalone /loans page. One copy of the copy.
//
// Nothing is fetched here for anyone: a guest gets the invitation to register,
// an investor gets the way into their own positions. Real money stays on
// /portfolio, which is the single source of truth for it.
defineProps({
  /** h1 on the standalone page; h2 inside the landing flow (hero owns the h1). */
  headingLevel: { type: String, default: 'h2' },
})

const auth = useAuthStore()

const state = computed(() => publicPageState(auth.user))
const isGuest = computed(() => state.value === STATE_GUEST)
const isAdmin = computed(() => state.value === STATE_ADMIN)
const isApproved = computed(() => state.value === STATE_APPROVED)
const isRejected = computed(() => state.value === STATE_REJECTED)
const awaitingUpload = computed(() => awaitsKycUpload(auth.user))

const benefits = [
  {
    title: 'Реални кредити, не идеи',
    // Loan terms are editable in every status (Reni 2026-08-10 reversed
    // IMMUTABLE_AFTER_DRAFT) — what protects the investor is the snapshot
    // taken at invest time plus the frozen contract. Say that, not "fixed".
    text: 'Всеки кредит е отпуснат и обслужван от лицензиран оригинатор, а условията, при които влизаш, се заключват в момента на инвестицията.',
  },
  {
    title: 'От 50 € на кредит',
    text: 'Разпределяш сумата между няколко кредита, вместо да заложиш всичко на един длъжник.',
  },
  {
    title: 'Договор за всяка инвестиция',
    // The preview is watermarked «ПРОЕКТ» and its schedule annex says the
    // dates are indicative until the loan is activated — do not promise exact.
    text: 'Преди да потвърдиш, виждаш проекта на договора — сумата, лихвата и ориентировъчния погасителен план.',
  },
]
</script>

<template>
  <section id="loans" class="py-20 sm:py-28 scroll-mt-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <p class="text-center text-sm font-semibold text-accent-500 uppercase tracking-wider mb-6">Кредити</p>

      <!-- Guest: a deliberately empty teaser (shapes only, never numbers) so
           the section does not stand bare, followed by the invitation. -->
      <template v-if="isGuest">
        <div aria-hidden="true" class="relative mb-8 select-none">
          <div class="space-y-3">
            <div
              v-for="n in 3"
              :key="n"
              class="flex items-center gap-4 rounded-2xl border border-gray-100 bg-white p-4 blur-[2px]"
            >
              <div class="size-10 shrink-0 rounded-xl bg-navy-700/5"></div>
              <div class="flex-1 space-y-2">
                <div class="h-3 w-1/3 rounded bg-gray-200"></div>
                <div class="h-3 w-1/4 rounded bg-gray-100"></div>
              </div>
              <div class="h-7 w-16 rounded-lg bg-accent-50"></div>
            </div>
          </div>
          <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/20 to-white"></div>
        </div>

        <!-- Register / login CTA comes from GatedNotice's default slot. -->
        <GatedNotice
          :heading-level="headingLevel"
          title="Кредитите се виждат след регистрация"
          lead="Списъкът с кредити, доходността и условията по тях са само за регистрирани инвеститори. Регистрацията е безплатна."
          icon="lock"
        />

        <div class="mt-14 grid sm:grid-cols-2 md:grid-cols-3 gap-6">
          <div v-for="benefit in benefits" :key="benefit.title" class="rounded-2xl border border-gray-100 bg-white p-6">
            <div class="flex size-10 items-center justify-center rounded-xl bg-accent-50 text-accent-500 mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <p class="font-semibold text-navy-700">{{ benefit.title }}</p>
            <p class="mt-2 text-sm text-gray-500 leading-relaxed">{{ benefit.text }}</p>
          </div>
        </div>
      </template>

      <!-- Approved investor: straight into their own positions. -->
      <GatedNotice
        v-else-if="isApproved"
        :heading-level="headingLevel"
        title="Твоите кредити"
        lead="Докато кредитите не станат публични, тук стои входът към позициите ти — вложена сума, получени плащания и текуща печалба."
        icon="chart"
      >
        <template #actions>
          <router-link
            to="/portfolio"
            class="inline-flex items-center px-7 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm shadow-lg shadow-accent-400/25"
          >
            Виж моите кредити
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-2 size-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
          </router-link>
          <router-link
            to="/dashboard"
            class="inline-flex items-center px-7 py-3.5 border border-gray-200 hover:border-navy-200 hover:bg-navy-50 text-navy-700 font-semibold rounded-xl transition-colors text-sm"
          >
            Към таблото
          </router-link>
        </template>
      </GatedNotice>

      <!-- Admin browsing the public site (Reni runs Filament and her own
           investor profile in the same browser) — no forced redirect. -->
      <GatedNotice
        v-else-if="isAdmin"
        :heading-level="headingLevel"
        title="Това е администраторски профил"
        lead="Кредитите се управляват в администрацията. Тази секция показва на инвеститорите техните собствени позиции."
        icon="alert"
      >
        <template #actions>
          <a
            href="/admin"
            class="inline-flex items-center px-7 py-3.5 bg-navy-700 hover:bg-navy-600 text-white font-semibold rounded-xl transition-colors text-sm"
          >
            Към администрацията
          </a>
        </template>
      </GatedNotice>

      <!-- Registered, verification rejected -->
      <GatedNotice
        v-else-if="isRejected"
        :heading-level="headingLevel"
        title="Верификацията не е одобрена"
        lead="Качи нови документи в профила си и ще ги прегледаме отново. След одобрение тук ще виждаш кредитите, в които участваш."
        icon="alert"
      >
        <template #actions>
          <router-link
            to="/profile"
            class="inline-flex items-center px-7 py-3.5 bg-navy-700 hover:bg-navy-600 text-white font-semibold rounded-xl transition-colors text-sm"
          >
            Към профила
          </router-link>
        </template>
      </GatedNotice>

      <!-- Registered, still waiting for approval -->
      <GatedNotice
        v-else
        :heading-level="headingLevel"
        :title="awaitingUpload ? 'Остава да потвърдим самоличността ти' : 'Профилът ти се проверява'"
        :lead="awaitingUpload
          ? 'Качи документите за верификация от профила си. Веднага след одобрение тук ще виждаш кредитите, в които участваш.'
          : 'Документите ти са получени. Щом одобрим профила, тук ще виждаш кредитите, в които участваш.'"
        icon="clock"
      >
        <template #actions>
          <router-link
            to="/profile"
            class="inline-flex items-center px-7 py-3.5 bg-navy-700 hover:bg-navy-600 text-white font-semibold rounded-xl transition-colors text-sm"
          >
            {{ awaitingUpload ? 'Завърши верификацията' : 'Към профила' }}
          </router-link>
          <router-link
            to="/dashboard"
            class="inline-flex items-center px-7 py-3.5 border border-gray-200 hover:border-navy-200 hover:bg-navy-50 text-navy-700 font-semibold rounded-xl transition-colors text-sm"
          >
            Към таблото
          </router-link>
        </template>
      </GatedNotice>
    </div>
  </section>
</template>
