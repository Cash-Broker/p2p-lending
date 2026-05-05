<script setup>
const advantages = [
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>`,
    title: 'Прозрачност',
    short: 'Пълна проследимост на всяка инвестиция. Виждаш точно къде отиват парите ти.',
    detail: 'Всяка транзакция е видима в твоя акаунт. Следи всяко движение на парите си — от депозита до получаване на лихва.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>`,
    title: 'Диверсификация',
    short: 'Избирай между различни кредити и финансови институции за оптимален баланс.',
    detail: 'Разпредели инвестициите си между различни видове кредити, финансови институции и рискови класове за по-балансирано портфолио.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" /></svg>`,
    title: 'Контрол',
    short: 'Ти решаваш къде и колко да инвестираш. Без автоматични разпределения.',
    detail: 'Няма автоматично инвестиране без твое съгласие. Ти избираш всеки кредит, сумата и момента на инвестиция.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>`,
    title: 'Сигурност',
    short: 'Работим само с лицензирани финансови институции.',
    detail: 'Партнираме само с лицензирани финансови институции с доказан опит. Всички кредити минават през стриктна оценка.',
  },
]
</script>

<template>
  <section id="advantages" class="py-20 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-16">
        <p class="text-sm font-semibold text-accent-500 uppercase tracking-wider mb-3">Предимства</p>
        <h2 class="text-3xl sm:text-4xl font-bold text-navy-700">Защо да избереш нас</h2>
        <p class="mt-4 text-gray-500">Създадохме платформа, която поставя инвеститора на първо място</p>
      </div>

      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div
          v-for="(item, i) in advantages"
          :key="i"
          class="group rounded-2xl border border-gray-100 bg-white p-6 hover:border-accent-200 hover:shadow-lg hover:shadow-accent-400/5 transition-all duration-300"
        >
          <div class="flex size-12 items-center justify-center rounded-xl bg-navy-700/5 text-navy-700 group-hover:bg-accent-400 group-hover:text-white transition-colors duration-300 mb-5">
            <div v-html="item.icon"></div>
          </div>
          <h3 class="text-lg font-bold text-navy-700">{{ item.title }}</h3>
          <p class="mt-2 text-sm text-gray-500 leading-relaxed">{{ item.short }}</p>
          <p class="mt-2 text-sm text-gray-400 leading-relaxed">{{ item.detail }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
