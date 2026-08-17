<script setup>
// Notification invitation — a QUIET strip in the house style (one line, small
// action, X to dismiss), not a boxed call-to-action: «трябва да е нещо
// небрежно» (Yordan 2026-08-17, after the first version came out too loud).
//
// Asks in two steps on purpose: our own line explains the benefit, and only
// «Включи» triggers the browser prompt — a reflexive block is near-permanent.
import { onMounted, ref } from 'vue'
import { permissionState, isSubscribed, enablePush } from '../utils/push'
import {
  promptMode, snoozeUntil, readSnooze, writeSnooze, detectIos, detectStandalone,
} from '../utils/pushPrompt'

const mode = ref(null) // 'ask' | 'denied' | 'ios-install' | null
const busy = ref(false)
const enabled = ref(false)
const failed = ref(false)

onMounted(async () => {
  // A device already carrying a subscription needs no invitation.
  if (await isSubscribed()) return

  mode.value = promptMode({
    permission: permissionState(),
    snoozedUntil: readSnooze(),
    isIos: detectIos(),
    isStandalone: detectStandalone(),
  })
})

async function accept() {
  busy.value = true
  failed.value = false
  try {
    await enablePush()
    enabled.value = true
    setTimeout(() => { mode.value = null }, 3000)
  } catch (e) {
    if (e?.message === 'denied') {
      // They just blocked us — switch to the explanation instead of vanishing.
      mode.value = 'denied'
    } else {
      failed.value = true
    }
  } finally {
    busy.value = false
  }
}

function dismiss() {
  writeSnooze(snoozeUntil())
  mode.value = null
}
</script>

<template>
  <!-- A proper card in the house style — same shell as the dashboard's other
       cards, so it reads without shouting («дай като някаква картичка там
       горе» — 2026-08-17, after both a boxed CTA and a one-liner missed). -->
  <div v-if="mode" class="relative mb-6 rounded-2xl border border-gray-100 bg-white p-5">
    <button
      v-if="!enabled"
      type="button"
      @click="dismiss"
      aria-label="Скрий"
      class="absolute right-3 top-3 text-gray-300 hover:text-gray-500 transition-colors"
    >
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
    </button>

    <div class="flex items-start gap-4">
      <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent-50 text-accent-500">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
      </div>

      <div class="min-w-0 flex-1 pr-6">
        <template v-if="enabled">
          <p class="text-base font-bold text-navy-700">Известията са включени</p>
          <p class="mt-0.5 text-sm text-gray-500">Ще ти пишем при всяко движение по парите ти.</p>
        </template>

        <template v-else-if="mode === 'ask'">
          <p class="text-base font-bold text-navy-700">Известия за парите ти</p>
          <p class="mt-0.5 text-sm text-gray-500 leading-relaxed">
            Изплатена лихва, потвърден депозит, изпълнено теглене — веднага на това
            устройство, дори когато приложението е затворено.
          </p>
          <p v-if="failed" role="alert" class="mt-1.5 text-sm text-red-600">
            Не се получи — провери връзката и опитай пак.
          </p>
          <button
            type="button"
            @click="accept"
            :disabled="busy"
            class="mt-3 rounded-xl bg-navy-700 hover:bg-navy-800 disabled:opacity-50 px-5 py-2.5 text-sm font-bold text-white transition-colors"
          >
            {{ busy ? 'Включване…' : 'Включи известията' }}
          </button>
        </template>

        <template v-else-if="mode === 'denied'">
          <p class="text-base font-bold text-navy-700">Известията са блокирани</p>
          <p class="mt-0.5 text-sm text-gray-500 leading-relaxed">
            Този браузър блокира известията за сайта. Разреши ги от
            <strong class="font-semibold text-navy-700">катинарчето до адреса</strong> → Известия,
            после презареди страницата.
          </p>
        </template>

        <template v-else>
          <p class="text-base font-bold text-navy-700">Известия на iPhone</p>
          <p class="mt-0.5 text-sm text-gray-500 leading-relaxed">
            Apple ги дава само на инсталираното приложение: натисни
            <strong class="font-semibold text-navy-700">Сподели</strong> →
            <strong class="font-semibold text-navy-700">Добави в начален екран</strong>, отвори
            Vamaasset от иконата и включи известията оттам. Работят и при затворено приложение.
          </p>
        </template>
      </div>
    </div>
  </div>
</template>
