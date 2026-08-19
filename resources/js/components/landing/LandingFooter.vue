<template>
  <footer class="border-t border-gray-100 bg-gray-50/50">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 py-12 sm:py-16">
        <div class="sm:col-span-2 lg:col-span-1">
          <div class="flex items-center mb-4">
            <img :src="'/logo/logo-mark.png'" alt="Vamaasset" class="h-16 w-auto" width="74" height="64" />
          </div>
          <p class="text-sm text-gray-400 leading-relaxed">
            Платформа за peer-to-peer инвестиции в кредити от утвърдени финансови институции.
          </p>
        </div>

        <div>
          <p class="text-sm font-semibold text-navy-700 mb-4">Платформа</p>
          <ul class="space-y-2.5">
            <li><a href="#how-it-works" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Как работи</a></li>
            <li><a href="#faq" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Въпроси</a></li>
            <li><a href="#" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">За нас</a></li>
          </ul>
        </div>
        <div>
          <p class="text-sm font-semibold text-navy-700 mb-4">Правна информация</p>
          <ul class="space-y-2.5">
            <li><router-link to="/legal/terms" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Общи условия</router-link></li>
            <li><router-link to="/legal/privacy" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Политика за поверителност</router-link></li>
            <li><router-link to="/legal/risk" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Декларация за риска</router-link></li>
            <li><router-link to="/legal/cookies" class="text-sm text-gray-500 hover:text-navy-700 transition-colors">Политика за бисквитки</router-link></li>
          </ul>
        </div>
        <div>
          <p class="text-sm font-semibold text-navy-700 mb-4">Контакти</p>
          <ul class="space-y-2.5">
            <!-- Phone + email withheld until the public-comms channel is signed off:
              <li><span class="text-sm text-gray-500">info@p2pinvest.bg</span></li>
              <li><span class="text-sm text-gray-500">+359 2 123 4567</span></li>
            -->
            <li>
              <address class="text-sm text-gray-500 leading-relaxed not-italic">
                БЪЛГАРИЯ, гр. Пловдив (4000),<br>
                р-н Централен,<br>
                ул. Капитан Райчо, 59, ет. 2, ап. 12
              </address>
            </li>
          </ul>
        </div>
      </div>

      <div class="border-t border-gray-200 py-6">
        <p class="text-xs text-gray-400 leading-relaxed text-center max-w-4xl mx-auto">
          <strong class="text-gray-500">Предупреждение за риск:</strong>
          Инвестирането в кредити носи риск. Инвестираните средства не са гарантирани от гаранционни схеми.
          Миналите резултати не са гаранция за бъдещи доходи. Не инвестирайте повече от 10% от нетното си богатство.
          Vamaasset не предоставя инвестиционни съвети.
        </p>
      </div>

      <div class="border-t border-gray-100 py-5 flex flex-col sm:flex-row items-center justify-between gap-2">
        <p class="text-xs text-gray-400">&copy; {{ new Date().getFullYear() }} Vamaasset. Всички права запазени.</p>
        <p class="text-xs text-gray-400">Направено в България</p>
      </div>
    </div>
  </footer>
</template>
