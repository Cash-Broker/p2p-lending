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

          <p class="mt-6 text-sm text-gray-400 text-center leading-relaxed">
            Присъединете се към над 1,200 инвеститори, които вече печелят пасивен доход чрез нашата платформа.
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
