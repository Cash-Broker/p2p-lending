<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

// ── Mobile menu ──
const mobileMenuOpen = ref(false)

// ── FAQ accordion ──
const openFaq = ref(null)
function toggleFaq(i) {
  openFaq.value = openFaq.value === i ? null : i
}

// ── Count-up animation on scroll ──
const statsRef = ref(null)
const statsVisible = ref(false)
const animatedValues = ref([0, 0, 0, 0])
let observer = null

const platformStats = [
  { target: 2.4, suffix: 'M €', label: 'инвестирани', decimals: 1 },
  { target: 1200, suffix: '+', label: 'инвеститори', decimals: 0 },
  { target: 350, suffix: '+', label: 'финансирани кредита', decimals: 0 },
  { target: 0, suffix: '%', label: 'загуби до момента', decimals: 0 },
]

function animateCountUp() {
  const duration = 2000
  const start = performance.now()
  function tick(now) {
    const elapsed = Math.min((now - start) / duration, 1)
    const ease = 1 - Math.pow(1 - elapsed, 3) // easeOutCubic
    animatedValues.value = platformStats.map((s) =>
      s.decimals > 0
        ? parseFloat((s.target * ease).toFixed(s.decimals))
        : Math.round(s.target * ease)
    )
    if (elapsed < 1) requestAnimationFrame(tick)
  }
  requestAnimationFrame(tick)
}

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting && !statsVisible.value) {
        statsVisible.value = true
        animateCountUp()
      }
    },
    { threshold: 0.3 }
  )
  if (statsRef.value) observer.observe(statsRef.value)
})

onUnmounted(() => {
  observer?.disconnect()
})

// ── Data ──
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
    detail: 'Разгледай детайлна информация за всеки кредит — доходност, срок, рисков клас, оригинатор и обезпечение. Инвестирай от 50 €.',
  },
  {
    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>`,
    title: 'Получавай доходност',
    short: 'Следи печалбата си в реално време',
    detail: 'Получавай месечни погашения директно в портфейла си. Следи доходността в реално време от твоя dashboard.',
  },
]

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
    short: 'Избирай между различни кредити и оригинатори за оптимален баланс.',
    detail: 'Разпредели инвестициите си между различни видове кредити, оригинатори и рискови класове за по-балансирано портфолио.',
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

const originators = [
  {
    name: 'ФинКредит ООД',
    description: 'Водеща финансова институция с над 20 години опит в кредитирането. Над 100 физически офиса в цялата страна. Листвана на фондовата борса. Buyback гаранция при забава над 60 дни.',
    loans: 230,
    volume: '1.8M',
    facts: {
      type: 'Потребителски, обезпечени',
      country: 'България',
      buyback: 'Да (60+ дни)',
      avgYield: '9.5%',
      employees: '1,200+',
    },
  },
  {
    name: 'ПроИмот Кредит АД',
    description: 'Иновативна финтех компания специализирана в мостови кредити, обезпечени с недвижими имоти. Използва AI за оценка на риска. 4 години опит, 6% пазарен дял.',
    loans: 120,
    volume: '950K',
    facts: {
      type: 'Мостови, обезпечени с имоти',
      country: 'България',
      buyback: 'Да (90+ дни)',
      avgYield: '12.8%',
      employees: '85',
    },
  },
]

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
    answer: 'Инвестирането е безплатно. Обслужването на портфейла е безплатно. При теглене се начислява минимална такса.',
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
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">

    <!-- ==================== HEADER ==================== -->
    <header class="fixed top-0 inset-x-0 z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
          <a href="/" class="flex items-center gap-2">
            <div class="flex size-8 items-center justify-center rounded-lg bg-navy-700 text-white text-sm font-bold">P2</div>
            <span class="text-lg font-bold text-navy-700">P2P Invest</span>
          </a>

          <nav class="hidden md:flex items-center gap-8">
            <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">Как работи</a>
            <a href="#advantages" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">За инвеститори</a>
            <a href="#loans" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">Кредити</a>
            <a href="#originators" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">Контакти</a>
          </nav>

          <div class="hidden md:flex items-center gap-3">
            <router-link to="/login" class="text-sm font-medium text-navy-700 hover:text-navy-600 transition-colors px-4 py-2">Вход</router-link>
            <router-link to="/register" class="text-sm font-medium text-white bg-navy-700 hover:bg-navy-600 transition-colors px-5 py-2 rounded-lg">Регистрация</router-link>
          </div>

          <button class="md:hidden flex items-center justify-center size-10 text-gray-600" @click="mobileMenuOpen = !mobileMenuOpen">
            <svg v-if="!mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <div v-if="mobileMenuOpen" class="md:hidden border-t border-gray-100 py-4 space-y-2">
          <a href="#how-it-works" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Как работи</a>
          <a href="#advantages" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">За инвеститори</a>
          <a href="#loans" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Кредити</a>
          <a href="#originators" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Контакти</a>
          <div class="flex gap-3 pt-3 border-t border-gray-100 px-3">
            <router-link to="/login" class="flex-1 text-center text-sm font-medium text-navy-700 border border-navy-700 px-4 py-2 rounded-lg">Вход</router-link>
            <router-link to="/register" class="flex-1 text-center text-sm font-medium text-white bg-navy-700 px-4 py-2 rounded-lg">Регистрация</router-link>
          </div>
        </div>
      </div>
    </header>

    <!-- ==================== HERO ==================== -->
    <section class="pt-28 pb-16 sm:pt-36 sm:pb-24 lg:pt-40 lg:pb-32">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          <!-- Left content -->
          <div class="max-w-xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-accent-50 text-accent-500 text-xs font-semibold mb-6 border border-accent-200">
              <span class="relative flex size-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent-400 opacity-75"></span>
                <span class="relative inline-flex size-2 rounded-full bg-accent-400"></span>
              </span>
              Активни инвестиционни възможности
            </div>
            <h1 class="text-4xl sm:text-5xl lg:text-[3.5rem] font-extrabold text-navy-700 leading-tight tracking-tight">
              Инвестирай в кредити.<br/>
              <span class="text-accent-400">Получавай доходност.</span>
            </h1>
            <p class="mt-6 text-lg text-gray-500 leading-relaxed">
              Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции.
            </p>
            <div class="mt-8 flex flex-wrap gap-4">
              <router-link to="/register" class="inline-flex items-center px-7 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm shadow-lg shadow-accent-400/25">
                Започни сега
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-2 size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
              </router-link>
              <a href="#how-it-works" class="inline-flex items-center px-7 py-3.5 border border-gray-200 hover:border-navy-200 hover:bg-navy-50 text-navy-700 font-semibold rounded-xl transition-colors text-sm">
                Научи повече
              </a>
            </div>
          </div>

          <!-- Right — stats cards -->
          <div class="relative">
            <div class="absolute inset-0 -z-10">
              <svg viewBox="0 0 500 500" class="w-full h-full opacity-[0.04]">
                <circle cx="250" cy="250" r="200" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
                <circle cx="250" cy="250" r="150" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
                <circle cx="250" cy="250" r="100" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
                <circle cx="250" cy="250" r="50" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
              </svg>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2 rounded-2xl bg-navy-700 p-6 sm:p-8 text-white">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-navy-200 text-sm font-medium">Общо инвестирани</p>
                    <p class="text-3xl sm:text-4xl font-bold mt-1">2.4M&nbsp;€</p>
                  </div>
                  <div class="flex size-12 items-center justify-center rounded-xl bg-white/10">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                  </div>
                </div>
                <div class="mt-6 flex items-end gap-1 h-12">
                  <div class="w-full rounded-sm bg-white/15 h-[30%]"></div>
                  <div class="w-full rounded-sm bg-white/15 h-[45%]"></div>
                  <div class="w-full rounded-sm bg-white/15 h-[35%]"></div>
                  <div class="w-full rounded-sm bg-white/15 h-[60%]"></div>
                  <div class="w-full rounded-sm bg-white/15 h-[50%]"></div>
                  <div class="w-full rounded-sm bg-white/15 h-[75%]"></div>
                  <div class="w-full rounded-sm bg-white/15 h-[65%]"></div>
                  <div class="w-full rounded-sm bg-white/20 h-[85%]"></div>
                  <div class="w-full rounded-sm bg-accent-400 h-full"></div>
                </div>
              </div>

              <div class="rounded-2xl bg-gray-50 border border-gray-100 p-6">
                <div class="flex size-10 items-center justify-center rounded-lg bg-navy-700/10 text-navy-700 mb-3">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </div>
                <p class="text-2xl font-bold text-navy-700">1,200+</p>
                <p class="text-sm text-gray-500 mt-1">инвеститори</p>
              </div>

              <div class="rounded-2xl bg-gray-50 border border-gray-100 p-6">
                <div class="flex size-10 items-center justify-center rounded-lg bg-accent-400/10 text-accent-500 mb-3">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>
                </div>
                <p class="text-2xl font-bold text-navy-700">до 12%</p>
                <p class="text-sm text-gray-500 mt-1">годишна доходност</p>
              </div>
            </div>

            <!-- Social proof under stats -->
            <p class="mt-6 text-sm text-gray-400 text-center leading-relaxed">
              Присъединете се към над 1,200 инвеститори, които вече печелят пасивен доход чрез нашата платформа.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ==================== STATS BANNER ==================== -->
    <section ref="statsRef" class="bg-navy-700">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-14 sm:py-16">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-4">
          <div v-for="(stat, i) in platformStats" :key="i" class="text-center">
            <p class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white tracking-tight">
              {{ animatedValues[i] }}{{ stat.suffix }}
            </p>
            <p class="mt-2 text-sm sm:text-base text-navy-200">{{ stat.label }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ==================== HOW IT WORKS ==================== -->
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

    <!-- ==================== WHY US ==================== -->
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

    <!-- ==================== INVESTMENT OPPORTUNITIES ==================== -->
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
            <!-- Header -->
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

            <!-- Stats -->
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

            <!-- Progress -->
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

            <!-- Action -->
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

    <!-- ==================== ORIGINATORS ==================== -->
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
            <!-- Header -->
            <div class="flex items-center gap-4 mb-5">
              <div class="flex size-14 items-center justify-center rounded-xl bg-navy-700/5 text-navy-700 font-bold text-lg shrink-0">
                {{ orig.name.charAt(0) }}
              </div>
              <div>
                <h3 class="font-bold text-navy-700 text-lg">{{ orig.name }}</h3>
                <div class="flex items-center gap-3 mt-1">
                  <span class="text-xs text-gray-400">{{ orig.loans }} кредита</span>
                  <span class="text-gray-200">|</span>
                  <span class="text-xs text-gray-400">{{ orig.volume }} € обем</span>
                </div>
              </div>
            </div>

            <!-- Description -->
            <p class="text-sm text-gray-500 leading-relaxed mb-5">{{ orig.description }}</p>

            <!-- Key facts table -->
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
                  <tr class="border-b border-gray-100">
                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Buyback</td>
                    <td class="px-4 py-2 text-navy-700 font-medium text-right">{{ orig.facts.buyback }}</td>
                  </tr>
                  <tr class="border-b border-gray-100">
                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Средна доходност</td>
                    <td class="px-4 py-2 text-accent-500 font-bold text-right">{{ orig.facts.avgYield }}</td>
                  </tr>
                  <tr>
                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap">Служители</td>
                    <td class="px-4 py-2 text-navy-700 font-medium text-right">{{ orig.facts.employees }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ==================== FAQ ==================== -->
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

    <!-- ==================== CTA ==================== -->
    <section class="py-20 sm:py-28">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-navy-700 px-8 py-16 sm:px-16 sm:py-20 text-center">
          <h2 class="text-3xl sm:text-4xl font-bold text-white">Готов ли си да инвестираш?</h2>
          <p class="mt-4 text-navy-200 text-lg max-w-xl mx-auto">
            Присъедини се към над 1,200 инвеститори, които вече получават доходност от кредити.
          </p>
          <div class="mt-8 flex flex-wrap justify-center gap-4">
            <router-link to="/register" class="inline-flex items-center px-8 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm">
              Създай акаунт безплатно
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-2 size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
            </router-link>
          </div>
        </div>
      </div>
    </section>

    <!-- ==================== FOOTER ==================== -->
    <footer class="border-t border-gray-100 bg-gray-50/50">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 py-12 sm:py-16">
          <div class="sm:col-span-2 lg:col-span-1">
            <div class="flex items-center gap-2 mb-4">
              <div class="flex size-8 items-center justify-center rounded-lg bg-navy-700 text-white text-sm font-bold">P2</div>
              <span class="text-lg font-bold text-navy-700">P2P Invest</span>
            </div>
            <p class="text-sm text-gray-400 leading-relaxed">
              Платформа за peer-to-peer инвестиции в кредити от утвърдени финансови институции.
            </p>
          </div>

          <div>
            <p class="text-sm font-semibold text-navy-700 mb-4">Платформа</p>
            <ul class="space-y-2.5">
              <li><a href="#how-it-works" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Как работи</a></li>
              <li><a href="#loans" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Кредити</a></li>
              <li><a href="#originators" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Оригинатори</a></li>
              <li><a href="#faq" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Въпроси</a></li>
              <li><a href="#" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">За нас</a></li>
            </ul>
          </div>
          <div>
            <p class="text-sm font-semibold text-navy-700 mb-4">Правна информация</p>
            <ul class="space-y-2.5">
              <li><a href="#" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Условия за ползване</a></li>
              <li><a href="#" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Политика за поверителност</a></li>
              <li><a href="#" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Бисквитки</a></li>
            </ul>
          </div>
          <div>
            <p class="text-sm font-semibold text-navy-700 mb-4">Контакти</p>
            <ul class="space-y-2.5">
              <li><span class="text-sm text-gray-500">info@p2pinvest.bg</span></li>
              <li><span class="text-sm text-gray-500">+359 2 123 4567</span></li>
              <li><span class="text-sm text-gray-500">София, България</span></li>
            </ul>
          </div>
        </div>

        <div class="border-t border-gray-200 py-6">
          <p class="text-xs text-gray-400 leading-relaxed text-center max-w-4xl mx-auto">
            <strong class="text-gray-500">Предупреждение за риск:</strong>
            Инвестирането в кредити носи риск. Инвестираните средства не са гарантирани от гаранционни схеми.
            Миналите резултати не са гаранция за бъдещи доходи. Не инвестирайте повече от 10% от нетното си богатство.
            P2P Invest не предоставя инвестиционни съвети.
          </p>
        </div>

        <div class="border-t border-gray-100 py-5 flex flex-col sm:flex-row items-center justify-between gap-2">
          <p class="text-xs text-gray-400">&copy; {{ new Date().getFullYear() }} P2P Invest. Всички права запазени.</p>
          <p class="text-xs text-gray-400">Направено в България</p>
        </div>
      </div>
    </footer>

  </div>
</template>
