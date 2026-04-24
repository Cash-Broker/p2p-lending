<script setup>
import { ref } from 'vue'

const openFaq = ref(null)
function toggleFaq(i) {
  openFaq.value = openFaq.value === i ? null : i
}

const faqs = [
  {
    question: 'Какво е P2P инвестиране?',
    answer: 'P2P (peer-to-peer) инвестирането ви позволява да инвестирате директно в кредити, издадени от финансови институции. Вие получавате лихва от погашенията на кредитополучателите.',
  },
  {
    question: 'Каква е минималната инвестиция?',
    answer: 'Можете да започнете с минимум 50 €. Няма максимален лимит.',
  },
  {
    question: 'Как се захранва сметката?',
    answer: 'Чрез банков превод. След потвърждение от нашия екип средствата се отразяват в рамките на 1 работен ден.',
  },
  {
    question: 'Какви са таксите?',
    answer: 'Платформата в момента не начислява такси. Инвестирането, обслужването на портфейла и тегленето на средства са безплатни.',
  },
  {
    question: 'Какво става при забава на кредит?',
    answer: 'При забава над 60 дни оригинаторът може да изкупи обратно кредита (buyback гаранция), в зависимост от условията.',
  },
  {
    question: 'Гарантирани ли са инвестициите?',
    answer: 'Не. Инвестирането в кредити носи риск, включително частична или пълна загуба. Не инвестирайте средства, които не можете да си позволите да загубите.',
  },
]
</script>

<template>
  <section id="faq" class="py-20 sm:py-28 bg-gray-50/70">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-16">
        <p class="text-sm font-semibold text-accent-500 uppercase tracking-wider mb-3">Поддръжка</p>
        <h2 class="text-3xl sm:text-4xl font-bold text-navy-700">Често задавани въпроси</h2>
        <p class="mt-4 text-gray-500">Намери отговор на най-честите въпроси за платформата</p>
      </div>

      <div class="space-y-3">
        <div
          v-for="(faq, i) in faqs"
          :key="i"
          class="rounded-2xl border bg-white overflow-hidden transition-colors duration-200"
          :class="openFaq === i ? 'border-accent-200' : 'border-gray-100'"
        >
          <button
            class="w-full flex items-center justify-between px-6 py-5 text-left"
            @click="toggleFaq(i)"
          >
            <span class="text-sm sm:text-base font-semibold text-navy-700 pr-4">{{ faq.question }}</span>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="2"
              stroke="currentColor"
              class="size-5 shrink-0 text-gray-400 transition-transform duration-300"
              :class="openFaq === i ? 'rotate-180' : ''"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
          </button>
          <div
            class="grid transition-all duration-300"
            :class="openFaq === i ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
          >
            <div class="overflow-hidden">
              <p class="px-6 pb-5 text-sm text-gray-500 leading-relaxed">{{ faq.answer }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
