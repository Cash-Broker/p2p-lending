<script setup>
import { ref } from 'vue'

const isOpen = ref(false)

const messages = ref([
  { from: 'bot', text: 'Здравейте! Как мога да ви помогна?' },
])

const freeText = ref('')

const quickQuestions = [
  { q: 'Как да депозирам?', a: 'Направете банков превод към посочената сметка с вашия уникален reference код. Средствата се отразяват след потвърждение от нашия екип в рамките на 1 работен ден.' },
  { q: 'Как да инвестирам?', a: 'Отидете на страница Инвестиране, изберете кредит, въведете сума и потвърдете. Минимална инвестиция: 50 €.' },
  { q: 'Какви са таксите?', a: 'Платформата в момента не начислява такси. Инвестирането, обслужването на портфейла и тегленето на средства са безплатни.' },
  { q: 'Какво е buyback гаранция?', a: 'Buyback гаранция означава, че при забава над 60 дни оригинаторът изкупува обратно кредита и ви връща инвестираната сума.' },
  { q: 'Как се разпределят погашенията?', a: 'Погашенията се разпределят пропорционално. Ако сте инвестирали 30% от кредита, получавате 30% от всяко погашение.' },
  { q: 'Какво става при закъснение?', a: 'При закъснение статусът на кредита се променя на „Закъснял". Ако закъснението надвиши 60 дни и има buyback гаранция, оригинаторът изкупува кредита.' },
]

function askQuestion(question) {
  messages.value.push({ from: 'user', text: question.q })
  setTimeout(() => {
    messages.value.push({ from: 'bot', text: question.a })
  }, 300)
}

function sendFreeText() {
  if (!freeText.value.trim()) return
  messages.value.push({ from: 'user', text: freeText.value })
  freeText.value = ''
  setTimeout(() => {
    messages.value.push({ from: 'bot', text: 'За допълнителни въпроси, свържете се с нас на support@p2pinvest.com или на телефон +359 2 123 4567.' })
  }, 500)
}
</script>

<template>
  <!-- Floating button -->
  <button
    v-if="!isOpen"
    @click="isOpen = true"
    class="fixed bottom-6 right-6 z-40 flex size-14 items-center justify-center rounded-full bg-accent-400 hover:bg-accent-500 text-white shadow-lg shadow-accent-400/30 transition-all"
  >
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>
  </button>

  <!-- Chat window -->
  <div
    v-if="isOpen"
    class="fixed bottom-6 right-6 z-40 w-[360px] max-h-[520px] rounded-2xl bg-white border border-gray-200 shadow-xl flex flex-col overflow-hidden"
  >
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 bg-navy-700 text-white shrink-0">
      <div class="flex items-center gap-2">
        <div class="flex size-8 items-center justify-center rounded-lg bg-white/20 text-sm font-bold">V</div>
        <div>
          <p class="text-sm font-semibold">Имате въпрос?</p>
          <p class="text-xs text-navy-200">Vamaasset поддръжка</p>
        </div>
      </div>
      <button @click="isOpen = false" class="text-white/60 hover:text-white">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Messages -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3 min-h-0">
      <div v-for="(msg, i) in messages" :key="i" class="flex" :class="msg.from === 'user' ? 'justify-end' : 'justify-start'">
        <div
          class="max-w-[80%] px-3 py-2 rounded-xl text-sm"
          :class="msg.from === 'user' ? 'bg-navy-700 text-white rounded-br-sm' : 'bg-gray-100 text-gray-700 rounded-bl-sm'"
        >
          {{ msg.text }}
        </div>
      </div>
    </div>

    <!-- Quick questions -->
    <div class="px-4 pb-2 shrink-0">
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="q in quickQuestions"
          :key="q.q"
          @click="askQuestion(q)"
          class="px-2.5 py-1 bg-gray-50 border border-gray-100 rounded-lg text-xs text-gray-600 hover:bg-gray-100 transition-colors text-left"
        >
          {{ q.q }}
        </button>
      </div>
    </div>

    <!-- Input -->
    <form @submit.prevent="sendFreeText" class="flex items-center gap-2 px-4 py-3 border-t border-gray-100 shrink-0">
      <input
        v-model="freeText"
        type="text"
        aria-label="Вашият въпрос"
        placeholder="Напишете въпрос..."
        class="flex-1 px-3 py-2 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400"
      />
      <button type="submit" class="flex size-9 items-center justify-center rounded-xl bg-accent-400 text-white shrink-0">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
      </button>
    </form>
  </div>
</template>
