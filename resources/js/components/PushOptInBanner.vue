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
  <!-- Deliberately understated: one small line, an inline text action, a tiny
       ✕. Anything boxier reads as an ad on a dashboard people open daily
       («да е нещо малко и небрежно, но да се чете» — 2026-08-17). -->
  <div v-if="mode" class="mb-5 flex items-start gap-2 text-xs text-gray-500">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4 shrink-0 mt-px text-gray-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>

    <p v-if="enabled" class="text-green-700">Готово — ще ти пишем при движение по парите.</p>

    <p v-else-if="mode === 'ask'" class="min-w-0">
      Да ти пишем ли, щом получиш лихва или депозит?
      <button
        type="button"
        @click="accept"
        :disabled="busy"
        class="font-semibold text-accent-500 underline decoration-accent-500/40 hover:decoration-accent-500 disabled:opacity-50"
      >{{ busy ? 'включване…' : 'включи' }}</button>
      <span v-if="failed" role="alert" class="text-red-600"> · не се получи, опитай пак</span>
    </p>

    <p v-else-if="mode === 'denied'" class="min-w-0">
      Известията са блокирани за сайта — разреши ги от катинарчето до адреса → Известия.
    </p>

    <p v-else class="min-w-0">
      На iPhone: Сподели → «Добави в начален екран», и отвори приложението оттам.
    </p>

    <button
      v-if="!enabled"
      type="button"
      @click="dismiss"
      aria-label="Скрий"
      class="ml-auto shrink-0 text-gray-300 hover:text-gray-500 transition-colors"
    >
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
    </button>
  </div>
</template>
