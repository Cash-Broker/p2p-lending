<script setup>
import { ref, onBeforeUnmount } from 'vue'

// Camera-only selfie capture. There is intentionally NO file-upload fallback:
// the whole point of the selfie is liveness, so a user without camera access
// simply cannot provide one (rather than uploading an old/3rd-party photo).
const emit = defineEmits(['captured'])

const video = ref(null)
const canvas = ref(null)
const stream = ref(null)
const state = ref('idle') // idle | starting | live | captured | error
const previewUrl = ref(null)
const errorMsg = ref(null)

async function start() {
  errorMsg.value = null

  if (!navigator.mediaDevices?.getUserMedia) {
    state.value = 'error'
    errorMsg.value = 'Браузърът или устройството ви не поддържа достъп до камера, затова селфи не може да бъде направено тук.'
    return
  }

  state.value = 'starting'
  try {
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: false,
    })
    state.value = 'live'
    if (video.value) {
      video.value.srcObject = stream.value
      await video.value.play()
    }
  } catch {
    state.value = 'error'
    errorMsg.value = 'Няма достъп до камерата. Разрешете достъпа в браузъра и опитайте отново — без камера не може да направите селфи.'
  }
}

function stop() {
  if (stream.value) {
    stream.value.getTracks().forEach((t) => t.stop())
    stream.value = null
  }
}

function capture() {
  const v = video.value
  const c = canvas.value
  if (!v || !c || !v.videoWidth) return

  const w = v.videoWidth
  const h = v.videoHeight
  c.width = w
  c.height = h

  // Mirror the capture so the saved image matches the selfie preview the user saw.
  const ctx = c.getContext('2d')
  ctx.translate(w, 0)
  ctx.scale(-1, 1)
  ctx.drawImage(v, 0, 0, w, h)

  c.toBlob(
    (blob) => {
      if (!blob) return
      if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
      previewUrl.value = URL.createObjectURL(blob)
      emit('captured', new File([blob], 'selfie.jpg', { type: 'image/jpeg' }))
      state.value = 'captured'
      stop()
    },
    'image/jpeg',
    0.92,
  )
}

function retake() {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }
  emit('captured', null)
  start()
}

onBeforeUnmount(() => {
  stop()
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <div class="rounded-2xl border border-gray-200 overflow-hidden">
    <!-- Viewport -->
    <div class="relative aspect-[4/3] bg-navy-900 flex items-center justify-center">
      <!-- Live video (mirrored like a selfie) -->
      <video
        ref="video"
        v-show="state === 'live'"
        autoplay
        playsinline
        muted
        class="absolute inset-0 w-full h-full object-cover"
        style="transform: scaleX(-1);"
      ></video>

      <!-- Face guide -->
      <template v-if="state === 'live'">
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
          <div class="size-44 sm:size-52 rounded-full border-2 border-dashed border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]"></div>
        </div>
        <p class="absolute bottom-2 inset-x-0 text-center text-xs text-white/90 pointer-events-none">Позиционирайте лицето си в кръга</p>
      </template>

      <!-- Captured preview -->
      <img v-else-if="state === 'captured' && previewUrl" :src="previewUrl" alt="Селфи" class="absolute inset-0 w-full h-full object-cover" />

      <!-- Idle / starting / error -->
      <div v-else class="flex flex-col items-center gap-2 px-6 text-center text-white/70">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
        </svg>
        <p v-if="state === 'starting'" class="text-sm">Стартиране на камерата…</p>
        <p v-else-if="state === 'error'" class="text-sm text-red-300">{{ errorMsg }}</p>
        <p v-else class="text-sm">Натиснете „Стартирай камера", за да направите селфи на живо.</p>
      </div>
    </div>

    <canvas ref="canvas" class="hidden"></canvas>

    <!-- Controls -->
    <div class="p-3 bg-white border-t border-gray-100 flex items-center justify-center gap-3">
      <button
        v-if="state === 'idle' || state === 'error'"
        type="button"
        @click="start"
        class="px-5 py-2.5 bg-navy-700 hover:bg-navy-600 text-white text-sm font-semibold rounded-xl transition-colors"
      >
        {{ state === 'error' ? 'Опитай отново' : 'Стартирай камера' }}
      </button>

      <button
        v-else-if="state === 'live'"
        type="button"
        @click="capture"
        class="flex items-center gap-2 px-6 py-2.5 bg-accent-400 hover:bg-accent-500 text-white text-sm font-semibold rounded-xl transition-colors"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5"><path d="M12 9a3.75 3.75 0 1 0 0 7.5A3.75 3.75 0 0 0 12 9Z" /><path fill-rule="evenodd" d="M9.344 3.071a49.52 49.52 0 0 1 5.312 0c.967.052 1.83.585 2.332 1.39l.821 1.317c.24.383.645.643 1.11.71.386.054.77.113 1.152.177 1.432.239 2.429 1.493 2.429 2.909V18a3 3 0 0 1-3 3h-15a3 3 0 0 1-3-3V9.574c0-1.416.997-2.67 2.429-2.909.382-.064.766-.123 1.151-.178a1.56 1.56 0 0 0 1.11-.71l.822-1.315a2.942 2.942 0 0 1 2.332-1.39ZM6.75 12.75a5.25 5.25 0 1 1 10.5 0 5.25 5.25 0 0 1-10.5 0Z" clip-rule="evenodd" /></svg>
        Снимай
      </button>

      <template v-else-if="state === 'captured'">
        <span class="flex items-center gap-1.5 text-sm font-medium text-green-600">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          Селфи готово
        </span>
        <button type="button" @click="retake" class="px-4 py-2 border border-navy-700 text-navy-700 hover:bg-navy-50 text-sm font-semibold rounded-xl transition-colors">
          Снимай отново
        </button>
      </template>

      <span v-else-if="state === 'starting'" class="text-sm text-gray-400">Изчакайте…</span>
    </div>
  </div>
</template>
