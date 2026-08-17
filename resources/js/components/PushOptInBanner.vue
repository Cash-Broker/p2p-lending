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
  <div
    v-if="mode"
    class="mb-8 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-gray-100 bg-white px-5 py-3"
  >
    <p v-if="enabled" class="text-sm text-navy-700">
      <span class="inline-block size-2 rounded-full bg-green-500 align-middle mr-1.5"></span>
      Готово — ще ти пишем при всяко движение по парите.
    </p>

    <template v-else-if="mode === 'ask'">
      <p class="text-sm text-gray-600 min-w-0">
        Да ти пишем ли, когато <strong class="font-semibold text-navy-700">получиш лихва</strong> или
        депозитът ти влезе? Известие на това устройство, без реклами.
      </p>
      <p v-if="failed" role="alert" class="w-full text-xs text-red-600">
        Не се получи — провери връзката и опитай пак.
      </p>
      <button
        type="button"
        @click="accept"
        :disabled="busy"
        class="ml-auto shrink-0 rounded-xl bg-navy-700 hover:bg-navy-800 disabled:opacity-50 px-4 py-2 text-sm font-bold text-white transition-colors"
      >
        {{ busy ? 'Включване…' : 'Включи' }}
      </button>
    </template>

    <template v-else-if="mode === 'denied'">
      <p class="text-sm text-gray-600 min-w-0">
        Известията са блокирани за сайта в този браузър. Разреши ги от
        <strong class="font-semibold text-navy-700">катинарчето до адреса</strong> → Известия,
        и се върни тук.
      </p>
    </template>

    <template v-else>
      <p class="text-sm text-gray-600 min-w-0">
        На iPhone известията работят само от инсталираното приложение: Сподели →
        <strong class="font-semibold text-navy-700">Добави в начален екран</strong>, после отвори
        приложението оттам.
      </p>
    </template>

    <button
      v-if="!enabled"
      type="button"
      @click="dismiss"
      aria-label="Скрий"
      class="shrink-0 text-gray-400 hover:text-navy-700 transition-colors"
    >
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
    </button>
  </div>
</template>
