<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useDocumentMeta } from '../composables/useDocumentMeta'
import { publicPageState, awaitsKycUpload, STATE_GUEST, STATE_ADMIN, STATE_REJECTED } from '../utils/publicGate'
import LandingHeader from '../components/landing/LandingHeader.vue'
import LandingFooter from '../components/landing/LandingFooter.vue'
import GatedNotice from '../components/landing/GatedNotice.vue'

// «Кредити» in the public nav (Reni 2026-08-20). An outside visitor loads
// NOTHING here — no loan endpoint is called at all, they only get the
// invitation to register. An approved investor never reaches this view: the
// router guard sends them to /portfolio, their own positions, which stays
// the single source of truth for real money until loans go public.
const auth = useAuthStore()

const state = computed(() => publicPageState(auth.user))
const isGuest = computed(() => state.value === STATE_GUEST)
const isAdmin = computed(() => state.value === STATE_ADMIN)
const isRejected = computed(() => state.value === STATE_REJECTED)
// Nothing submitted yet vs. documents already in the review queue — the
// difference decides whether we ask for an upload or for patience.
const awaitingUpload = computed(() => awaitsKycUpload(auth.user))

useDocumentMeta({
  title: 'Кредити',
  description: 'Кредитите във Vamaasset са достъпни за регистрирани инвеститори. Създай профил, за да получиш достъп.',
  path: '/loans',
})

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
  <div class="min-h-screen bg-gray-50/50 font-sans text-gray-700 antialiased">
    <LandingHeader />

    <main class="pt-16">
      <section class="pt-16 pb-20 sm:pt-24 sm:pb-28">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
          <p class="text-center text-sm font-semibold text-accent-500 uppercase tracking-wider mb-6">Кредити</p>

          <!-- Guest: a deliberately empty teaser (shapes only, never numbers)
               so the page does not stand bare, followed by the invitation. -->
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
              <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-gray-50/40 to-gray-50"></div>
            </div>

            <!-- Register / login CTA comes from GatedNotice's default slot. -->
            <GatedNotice
              title="Кредитите се виждат след регистрация"
              lead="Списъкът с кредити, доходността и условията по тях са само за регистрирани инвеститори. Регистрацията е безплатна."
              icon="lock"
            />
          </template>

          <!-- Admin browsing the public site (Reni runs Filament and her own
               investor profile in the same browser) — no forced redirect. -->
          <GatedNotice
            v-else-if="isAdmin"
            title="Това е администраторски профил"
            lead="Кредитите се управляват в администрацията. Тази страница показва на инвеститорите техните собствени позиции."
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

          <div v-if="isGuest" class="mt-14 grid sm:grid-cols-2 md:grid-cols-3 gap-6">
            <div v-for="benefit in benefits" :key="benefit.title" class="rounded-2xl border border-gray-100 bg-white p-6">
              <div class="flex size-10 items-center justify-center rounded-xl bg-accent-50 text-accent-500 mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
              </div>
              <p class="font-semibold text-navy-700">{{ benefit.title }}</p>
              <p class="mt-2 text-sm text-gray-500 leading-relaxed">{{ benefit.text }}</p>
            </div>
          </div>
        </div>
      </section>
    </main>

    <LandingFooter />
  </div>
</template>
