<script setup>
import LandingHeader from '../../components/landing/LandingHeader.vue'
import LandingFooter from '../../components/landing/LandingFooter.vue'

defineProps({
  title: { type: String, required: true },
  version: { type: String, default: 'v1.0' },
  effectiveDate: { type: String, default: '4 май 2026 г.' },
})
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-gray-700 antialiased">
    <LandingHeader />

    <main class="pt-28 pb-16 sm:pt-32 sm:pb-24">
      <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <header class="mb-10 pb-6 border-b border-gray-100">
          <p class="text-xs font-semibold uppercase tracking-wider text-accent-500 mb-2">Правен документ</p>
          <h1 class="text-3xl sm:text-4xl font-extrabold text-navy-700 tracking-tight">{{ title }}</h1>
          <p class="mt-3 text-sm text-gray-500">
            Версия {{ version }} · в сила от {{ effectiveDate }}
          </p>
        </header>

        <article class="legal-prose">
          <slot />
        </article>

        <div class="mt-16 pt-6 border-t border-gray-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
          <p class="text-xs text-gray-400">
            Този документ е издаден от ВАМА АСЕТ ЕООД, ЕИК 201035515.
          </p>
          <router-link to="/" class="text-sm text-accent-500 hover:text-accent-600 font-medium">
            ← Към началната страница
          </router-link>
        </div>
      </div>
    </main>

    <LandingFooter />
  </div>
</template>

<style>
.legal-prose {
  color: #4b5563;
  line-height: 1.7;
  font-size: 0.9375rem;
}
.legal-prose h2 {
  color: #1B2A4A;
  font-size: 1.375rem;
  font-weight: 700;
  margin-top: 2.5rem;
  margin-bottom: 0.75rem;
  letter-spacing: -0.01em;
}
.legal-prose h3 {
  color: #1B2A4A;
  font-size: 1.0625rem;
  font-weight: 600;
  margin-top: 1.75rem;
  margin-bottom: 0.5rem;
}
.legal-prose p {
  margin-bottom: 1rem;
}
.legal-prose ul, .legal-prose ol {
  margin: 0.75rem 0 1.25rem 1.5rem;
}
.legal-prose ul { list-style: disc; }
.legal-prose ol { list-style: decimal; }
.legal-prose li {
  margin-bottom: 0.4rem;
}
.legal-prose strong { color: #1B2A4A; font-weight: 600; }
.legal-prose a { color: #16a34a; text-decoration: underline; }
.legal-prose a:hover { color: #15803d; }
.legal-prose .placeholder {
  color: #b91c1c;
  background: #fef2f2;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 0.8125rem;
  font-weight: 600;
}
.legal-prose .callout {
  margin: 1.25rem 0;
  padding: 0.875rem 1rem;
  background: #fef9c3;
  border-left: 3px solid #f59e0b;
  border-radius: 4px;
  font-size: 0.875rem;
  color: #713f12;
}
.legal-prose table {
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0 1.5rem;
  font-size: 0.875rem;
}
.legal-prose th, .legal-prose td {
  border: 1px solid #e5e7eb;
  padding: 0.5rem 0.75rem;
  text-align: left;
  vertical-align: top;
}
.legal-prose th {
  background: #f9fafb;
  color: #1B2A4A;
  font-weight: 600;
}
</style>
