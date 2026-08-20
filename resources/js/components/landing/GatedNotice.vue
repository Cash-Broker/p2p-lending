<script setup>
// Locked-content panel for the «Кредити» and «Оригинатори» surfaces
// (Reni 2026-08-20). Deliberately dumb: it renders a message and a CTA — no
// data fetching lives here, because for a guest nothing is supposed to load
// at all. The default `actions` slot IS the guest CTA, so every caller shares
// one copy of it; the registered-visitor panels override the slot.
defineProps({
  title: { type: String, required: true },
  lead: { type: String, default: '' },
  /** lock (guest) | clock (waiting for approval) | alert | chart (own loans) */
  icon: { type: String, default: 'lock' },
  /**
   * h1 on a standalone page where this is the only heading; h2 inside the
   * landing flow, where HeroSection already owns the page's h1.
   */
  headingLevel: { type: String, default: 'h2' },
})
</script>

<template>
  <div class="rounded-3xl border border-gray-100 bg-white p-8 sm:p-12 text-center">
    <div class="mx-auto mb-6 flex size-14 items-center justify-center rounded-2xl bg-navy-700/5 text-navy-700">
      <svg v-if="icon === 'lock'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
      </svg>
      <svg v-else-if="icon === 'clock'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
      </svg>
      <svg v-else-if="icon === 'chart'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
      </svg>
      <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
      </svg>
    </div>

    <component :is="headingLevel" class="text-2xl sm:text-3xl font-bold text-navy-700">{{ title }}</component>
    <p v-if="lead" class="mt-4 text-gray-500 leading-relaxed max-w-xl mx-auto">{{ lead }}</p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
      <slot name="actions">
        <router-link
          to="/register"
          class="inline-flex items-center px-7 py-3.5 bg-accent-400 hover:bg-accent-500 text-white font-semibold rounded-xl transition-colors text-sm shadow-lg shadow-accent-400/25"
        >
          Създай акаунт безплатно
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="ml-2 size-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
        </router-link>
        <router-link
          to="/login"
          class="inline-flex items-center px-7 py-3.5 border border-gray-200 hover:border-navy-200 hover:bg-navy-50 text-navy-700 font-semibold rounded-xl transition-colors text-sm"
        >
          Вече имам профил
        </router-link>
      </slot>
    </div>

    <slot />
  </div>
</template>
