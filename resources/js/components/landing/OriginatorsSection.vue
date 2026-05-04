<script setup>
// Pre-launch: real partner agreements not yet finalised, so per-originator
// volume / loan-count / avgYield / employee numbers below are placeholders
// and must NOT be shown to visitors. Once we sign real originators, populate
// these from a backend endpoint or seed config and re-enable the commented
// rows in the template.
const originators = [
  {
    name: 'ФинКредит ООД',
    description: 'Водеща финансова институция с дългогодишен опит в кредитирането и широка офис мрежа в страната. Buyback гаранция при забава над 60 дни.',
    /* loans: 230, volume: '1.8M', */
    facts: {
      type: 'Потребителски, обезпечени',
      country: 'България',
      buyback: 'Да (60+ дни)',
      /* avgYield: '9.5%', employees: '1,200+', */
    },
  },
  {
    name: 'ПроИмот Кредит АД',
    description: 'Финтех компания, специализирана в мостови кредити, обезпечени с недвижими имоти. Използва AI за оценка на риска.',
    /* loans: 120, volume: '950K', */
    facts: {
      type: 'Мостови, обезпечени с имоти',
      country: 'България',
      buyback: 'Да (90+ дни)',
      /* avgYield: '12.8%', employees: '85', */
    },
  },
]
</script>

<template>
  <section id="originators" class="py-20 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-16">
        <p class="text-sm font-semibold text-accent-500 uppercase tracking-wider mb-3">Партньори</p>
        <h2 class="text-3xl sm:text-4xl font-bold text-navy-700">Нашите оригинатори</h2>
        <p class="mt-4 text-gray-500">Работим с утвърдени и лицензирани финансови институции</p>
      </div>

      <div class="grid md:grid-cols-2 gap-6 max-w-5xl mx-auto">
        <div
          v-for="(orig, i) in originators"
          :key="i"
          class="rounded-2xl border border-gray-100 bg-white p-6 sm:p-8 hover:shadow-lg hover:shadow-gray-200/50 transition-all duration-300"
        >
          <div class="flex items-center gap-4 mb-5">
            <div class="flex size-14 items-center justify-center rounded-xl bg-navy-700/5 text-navy-700 font-bold text-lg shrink-0">
              {{ orig.name.charAt(0) }}
            </div>
            <div>
              <h3 class="font-bold text-navy-700 text-lg">{{ orig.name }}</h3>
              <p class="text-xs text-gray-400 mt-1">Партньорска финансова институция</p>
              <!-- Pre-launch: per-originator counts hidden until real data
                <div class="flex items-center gap-3 mt-1">
                  <span class="text-xs text-gray-400">{{ orig.loans }} кредита</span>
                  <span class="text-gray-200">|</span>
                  <span class="text-xs text-gray-400">{{ orig.volume }} € обем</span>
                </div>
              -->
            </div>
          </div>

          <p class="text-sm text-gray-500 leading-relaxed mb-5">{{ orig.description }}</p>

          <div class="rounded-xl bg-gray-50 border border-gray-100 overflow-hidden">
            <p class="text-xs font-semibold text-navy-700 uppercase tracking-wider px-4 py-2.5 border-b border-gray-100 bg-gray-50">Ключови факти</p>
            <table class="w-full text-sm">
              <tbody>
                <tr class="border-b border-gray-100">
                  <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Тип кредити</td>
                  <td class="px-4 py-2 text-navy-700 font-medium text-right">{{ orig.facts.type }}</td>
                </tr>
                <tr class="border-b border-gray-100">
                  <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Държава</td>
                  <td class="px-4 py-2 text-navy-700 font-medium text-right">{{ orig.facts.country }}</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Buyback</td>
                  <td class="px-4 py-2 text-navy-700 font-medium text-right">{{ orig.facts.buyback }}</td>
                </tr>
                <!-- Pre-launch: hidden until real per-originator data is available
                  <tr class="border-b border-gray-100">
                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Средна доходност</td>
                    <td class="px-4 py-2 text-accent-500 font-bold text-right">{{ orig.facts.avgYield }}</td>
                  </tr>
                  <tr>
                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Служители</td>
                    <td class="px-4 py-2 text-navy-700 font-medium text-right">{{ orig.facts.employees }}</td>
                  </tr>
                -->
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
