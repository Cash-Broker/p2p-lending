<script setup>
// Active invitation to turn notifications on (Yordan/Reni 2026-08-17:
// «трябва да ги питаме дали искат да получават известия» — the Профил card is
// passive and existing investors would never find it).
//
// Deliberately asks in TWO steps: our own banner explains the benefit first,
// and only «Да, включи» triggers the browser's permission prompt. A reflexive
// «Блокирай» is close to permanent (the browser never re-asks), so the prompt
// must arrive when the person already knows what it is for.
import { onMounted, ref } from 'vue'
import { permissionState, isSubscribed, enablePush } from '../utils/push'
import { shouldAskAboutPush, snoozeUntil, readSnooze, writeSnooze } from '../utils/pushPrompt'

const visible = ref(false)
const busy = ref(false)
const enabled = ref(false)
const failed = ref(false)

onMounted(async () => {
  const state = permissionState()
  if (state === 'unsupported') return
  // A device already carrying a subscription never needs the invitation.
  if (await isSubscribed()) return
  visible.value = shouldAskAboutPush(state, readSnooze())
})

async function accept() {
  busy.value = true
  failed.value = false
  try {
    await enablePush()
    enabled.value = true
    // Leave the success state on screen for a beat, then fold the banner.
    setTimeout(() => { visible.value = false }, 3000)
  } catch (e) {
    if (e?.message === 'denied') {
      // The browser will not ask again — nothing more we can offer here.
      visible.value = false
    } else {
      failed.value = true
    }
  } finally {
    busy.value = false
  }
}

function later() {
  writeSnooze(snoozeUntil())
  visible.value = false
}
</script>

<template>
  <div
    v-if="visible"
    class="rounded-2xl border border-accent-400/40 bg-accent-50/60 p-4 mb-6"
    role="region"
    aria-label="Известия"
  >
    <div v-if="enabled" class="flex items-center gap-2 text-sm font-semibold text-green-700">
      <span class="size-2 rounded-full bg-green-500"></span>
      Готово — ще ви известяваме за движенията по парите ви.
    </div>

    <template v-else>
      <div class="flex items-start gap-3">
        <span class="text-xl leading-none" aria-hidden="true">🔔</span>
        <div class="flex-1">
          <p class="text-sm font-bold text-navy-700">Да ви известяваме ли, когато получите пари?</p>
          <p class="text-xs text-gray-500 mt-0.5 leading-relaxed">
            Изплатена лихва, потвърден депозит, изпълнено теглене — известие на този
            телефон или компютър, дори когато приложението е затворено. Без реклами.
          </p>
          <p v-if="failed" role="alert" class="text-xs text-red-600 mt-1.5">
            Не се получи. Проверете връзката и опитайте отново.
          </p>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 mt-3">
        <button
          @click="accept"
          :disabled="busy"
          class="flex-1 min-w-[140px] py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
        >
          {{ busy ? 'Включване…' : 'Да, включи' }}
        </button>
        <button
          @click="later"
          :disabled="busy"
          class="px-4 py-2.5 border border-gray-200 bg-white text-sm font-medium text-gray-600 rounded-xl hover:bg-gray-50 disabled:opacity-50"
        >
          Не сега
        </button>
      </div>
    </template>
  </div>
</template>
