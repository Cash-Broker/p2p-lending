<script setup>
const steps = [
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>`,
    title: 'Регистрирай се',
    short: 'Създай акаунт за минути',
    detail: 'Процесът отнема по-малко от 5 минути. Необходими са само имейл и лична карта за верификация.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>`,
    title: 'Захрани сметка',
    short: 'Направи банков превод',
    detail: 'Направи банков превод към платформата. Средствата се отразяват след потвърждение от нашия екип в рамките на 1 работен ден.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm3.75 11.625a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>`,
    title: 'Избери кредит',
    short: 'Разгледай възможностите и инвестирай',
    detail: 'Разгледай детайлна информация за всеки кредит — доходност, срок, рисков клас и обезпечение. Инвестирай от 50 €.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>`,
    title: 'Получавай доходност',
    short: 'Следи печалбата си в реално време',
    detail: 'Получавай месечни погашения директно в портфейла си. Следи доходността в реално време от твоя dashboard.',
  },
]
</script>

<template>
  <section id="how-it-works" class="py-20 sm:py-28 bg-gray-50/70">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-16">
        <p class="text-sm font-semibold text-accent-500 uppercase tracking-wider mb-3">Процес</p>
        <h2 class="text-3xl sm:text-4xl font-bold text-navy-700">Как работи</h2>
        <p class="mt-4 text-gray-500">Четири прости стъпки до първата ти инвестиция</p>
      </div>

      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8">
        <div v-for="(step, i) in steps" :key="i" class="relative text-center">
          <div class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-white border border-gray-100 shadow-sm text-navy-700 mb-5">
            <div v-html="step.icon"></div>
          </div>
          <div v-if="i < steps.length - 1" class="hidden lg:block absolute top-8 left-[calc(50%+2.5rem)] w-[calc(100%-5rem)] h-px bg-gray-200"></div>
          <p class="text-xs font-bold text-accent-400 uppercase tracking-wider mb-2">Стъпка {{ i + 1 }}</p>
          <h3 class="text-lg font-bold text-navy-700">{{ step.title }}</h3>
          <p class="mt-2 text-sm font-medium text-gray-600">{{ step.short }}</p>
          <p class="mt-2 text-sm text-gray-400 leading-relaxed">{{ step.detail }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
