<template>
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
</template>
