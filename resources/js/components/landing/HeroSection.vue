<template>
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
            VAMA<br/>
            <span class="text-accent-400">ASSET</span>
          </h1>
          <p class="mt-6 text-lg text-gray-500 leading-relaxed">
            Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции. Прозрачност, диверсификация и контрол върху портфейла Ви.
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

        <!-- Right — motivational quote (replaces pre-launch fake stats; see below) -->
        <div class="relative">
          <div class="absolute inset-0 -z-10">
            <svg viewBox="0 0 500 500" class="w-full h-full opacity-[0.04]">
              <circle cx="250" cy="250" r="200" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
              <circle cx="250" cy="250" r="150" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
              <circle cx="250" cy="250" r="100" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
              <circle cx="250" cy="250" r="50" fill="none" stroke="#1B2A4A" stroke-width="0.5" />
            </svg>
          </div>

          <div class="rounded-3xl bg-navy-700 p-8 sm:p-10 text-white">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="currentColor"
              class="size-10 text-accent-400 mb-5 opacity-80"
              aria-hidden="true"
            >
              <path d="M4.583 17.321C3.553 16.227 3 15 3 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 0 1-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179zm10 0C13.553 16.227 13 15 13 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 0 1-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179z"/>
            </svg>
            <p class="text-xl sm:text-2xl font-semibold leading-relaxed tracking-tight">
              Цената е това, което плащаш.
              <span class="text-accent-400">Стойността е това, което получаваш.</span>
            </p>
            <p class="mt-5 text-sm text-navy-200">— Уорън Бъфет</p>
          </div>

          <p class="mt-6 text-sm text-gray-400 text-center leading-relaxed">
            Започни своето инвестиционно пътуване с прозрачност и контрол.
          </p>
        </div>

        <!--
          ─── Pre-launch (commented out): real stats cards ────────────────────
          Uncomment below + remove the quote card above when production has
          real data (Σ deposits, distinct investor count, configured max yield).
          Wire to a public stats endpoint rather than hard-coding — fake numbers
          on a regulated platform are a misrepresentation risk.

          <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2 rounded-2xl bg-navy-700 p-6 sm:p-8 text-white">
              ...big "Общо инвестирани" card with chart bars...
            </div>
            <div class="rounded-2xl bg-gray-50 border border-gray-100 p-6">
              ...investor count card...
            </div>
            <div class="rounded-2xl bg-gray-50 border border-gray-100 p-6">
              ...avg yield card...
            </div>
          </div>
          <p class="mt-6 text-sm text-gray-400 text-center leading-relaxed">
            Присъединете се към над 1,200 инвеститори, които вече печелят пасивен доход чрез нашата платформа.
          </p>
        -->
      </div>
    </div>
  </section>
</template>
