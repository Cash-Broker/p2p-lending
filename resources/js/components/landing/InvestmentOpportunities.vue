<script setup>
const loans = [
  {
    type: 'Мостов кредит',
    originator: 'ПроИмот Кредит АД',
    rate: '12%',
    term: '3 месеца',
    status: 'Активен',
    funded: 75,
    amount: '8,500',
  },
  {
    type: 'Потребителски кредит',
    originator: 'ФинКредит ООД',
    rate: '10%',
    term: '6 месеца',
    status: 'Активен',
    funded: 40,
    amount: '12,000',
  },
  {
    type: 'Обезпечен кредит',
    originator: 'ФинКредит ООД',
    rate: '8%',
    term: '12 месеца',
    status: 'Активен',
    funded: 90,
    amount: '25,000',
  },
  {
    type: 'Мостов кредит',
    originator: 'ПроИмот Кредит АД',
    rate: '14%',
    term: '4 месеца',
    status: 'Активен',
    funded: 20,
    amount: '6,200',
  },
  {
    type: 'Бизнес кредит',
    originator: 'БизнесЛенд АД',
    rate: '11%',
    term: '9 месеца',
    status: 'Активен',
    funded: 55,
    amount: '18,000',
  },
  {
    type: 'Потребителски кредит',
    originator: 'ФинКредит ООД',
    rate: '9.5%',
    term: '6 месеца',
    status: 'Финансиран',
    funded: 100,
    amount: '10,500',
  },
]
</script>

<template>
  <section id="loans" class="py-20 sm:py-28 bg-gray-50/70">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-16">
        <p class="text-sm font-semibold text-accent-500 uppercase tracking-wider mb-3">Пазар</p>
        <h2 class="text-3xl sm:text-4xl font-bold text-navy-700">Инвестиционни възможности</h2>
        <p class="mt-4 text-gray-500">Разгледай текущите кредити, достъпни за инвестиция</p>
      </div>

      <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div
          v-for="(loan, i) in loans"
          :key="i"
          class="rounded-2xl border bg-white p-6 transition-all duration-300"
          :class="loan.funded === 100 ? 'border-gray-200 opacity-75' : 'border-gray-100 hover:shadow-lg hover:shadow-gray-200/50'"
        >
          <div class="flex items-start justify-between mb-4">
            <div>
              <h3 class="font-bold text-navy-700">{{ loan.type }}</h3>
              <p class="text-sm text-gray-400 mt-0.5">{{ loan.originator }}</p>
            </div>
            <span
              class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
              :class="loan.funded === 100
                ? 'bg-gray-100 text-gray-500'
                : 'bg-accent-50 text-accent-500'"
            >
              <span
                class="size-1.5 rounded-full"
                :class="loan.funded === 100 ? 'bg-gray-400' : 'bg-accent-400'"
              ></span>
              {{ loan.status }}
            </span>
          </div>

          <div class="grid grid-cols-3 gap-4 py-4 border-y border-gray-100">
            <div>
              <p class="text-xs text-gray-400 mb-1">Доходност</p>
              <p class="text-lg font-bold text-accent-500">{{ loan.rate }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-400 mb-1">Срок</p>
              <p class="text-lg font-bold text-navy-700">{{ loan.term }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-400 mb-1">Сума</p>
              <p class="text-lg font-bold text-navy-700">{{ loan.amount }}&nbsp;€</p>
            </div>
          </div>

          <div class="mt-4">
            <div class="flex items-center justify-between text-sm mb-2">
              <span class="text-gray-500">Финансирано</span>
              <span class="font-semibold text-navy-700">{{ loan.funded }}%</span>
            </div>
            <div class="w-full h-2 rounded-full bg-gray-100">
              <div
                class="h-full rounded-full transition-all duration-500"
                :class="loan.funded === 100 ? 'bg-gray-400' : 'bg-accent-400'"
                :style="{ width: loan.funded + '%' }"
              ></div>
            </div>
          </div>

          <div v-if="loan.funded === 100" class="mt-5 flex items-center justify-center w-full py-2.5 text-sm font-semibold text-gray-400 bg-gray-50 rounded-xl cursor-default">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="mr-1.5 size-4"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            Напълно финансиран
          </div>
          <router-link v-else to="/register" class="mt-5 flex items-center justify-center w-full py-2.5 text-sm font-semibold text-navy-700 border border-gray-200 rounded-xl hover:border-navy-200 hover:bg-navy-50 transition-colors">
            Виж детайли
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-1.5 size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
          </router-link>
        </div>
      </div>
    </div>
  </section>
</template>
