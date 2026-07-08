<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { isInAppBrowser, isStandaloneDisplayMode } from '../utils/browser'
import { streamHealth } from '../utils/mediaStream'

// Camera-only selfie capture with live face guidance. There is intentionally NO
// file-upload fallback: the point of the selfie is liveness.
//
// The framing guide is generous (a large portrait oval, not a tight circle) and
// — where the browser exposes the native FaceDetector API — a box tracks the
// face in real time and the guide turns green. Detection is GUIDANCE ONLY: it
// never blocks the shutter, so a missed detection (glasses, lighting, an
// unsupported browser) can't trap the user.
const emit = defineEmits(['captured'])

const video = ref(null)
const overlay = ref(null)
const canvas = ref(null)
const stream = ref(null)
const state = ref('idle') // idle | starting | live | captured | error
const previewUrl = ref(null)
const errorMsg = ref(null)
const errorKind = ref(null) // 'in-app' | 'denied' | 'unsupported' | 'interrupted'
const linkCopied = ref(false)
const faceDetected = ref(false)

// Messenger WebViews (Viber/Facebook/Instagram…) don't forward the camera
// permission from the host app, so "allow access" is a dead end there — the
// only fix is opening the link in a real browser. Links to the platform are
// shared mostly over Viber, so this case gets its own message + copy button.
// Only consulted AFTER a camera failure — a false positive can't block anything.
// Standalone check first: an iOS home-screen PWA has the same UA as a WebView
// but a working camera, so its failures must get the normal retry path.
const inAppBrowser = !isStandaloneDisplayMode()
  && isInAppBrowser(typeof navigator !== 'undefined' ? navigator.userAgent : '')

const IN_APP_MSG = 'Отворили сте сайта във вграден браузър (напр. Viber или Facebook), който не дава достъп до камерата. Копирайте линка и го отворете в Chrome или Safari, за да направите селфито.'
const INTERRUPTED_MSG = 'Камерата беше прекъсната — това се случва при превключване към друго приложение. Натиснете „Опитай отново“.'

const detectorSupported = typeof window !== 'undefined' && 'FaceDetector' in window
let detector = null
let detectTimer = null
let detectionActive = false
let unmounted = false
let muteRecoveryTimer = null

const statusText = computed(() => {
  if (!detectorSupported) return 'Центрирайте лицето си в рамката и натиснете „Снимай".'
  return faceDetected.value ? 'Лицето е в кадър — можете да снимате.' : 'Позиционирайте лицето си в рамката…'
})

async function start() {
  errorMsg.value = null
  errorKind.value = null

  if (!navigator.mediaDevices?.getUserMedia) {
    state.value = 'error'
    if (inAppBrowser) {
      errorKind.value = 'in-app'
      errorMsg.value = IN_APP_MSG
    } else {
      errorKind.value = 'unsupported'
      errorMsg.value = 'Браузърът или устройството ви не поддържа достъп до камера, затова селфи не може да бъде направено тук.'
    }
    return
  }

  state.value = 'starting'
  try {
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: false,
    })
    // iOS Safari kills the stream when the tab is backgrounded (e.g. the user
    // switches away to photograph their ID card for steps 1-2). Without these
    // hooks the UI stays "live" over a frozen frame and the shutter silently
    // does nothing.
    stream.value.getTracks().forEach((t) => t.addEventListener('ended', onStreamInterrupted))
    document.addEventListener('visibilitychange', onVisibilityChange)
    state.value = 'live'
    if (video.value) {
      video.value.srcObject = stream.value
      await video.value.play()
    }
    startDetection()
  } catch {
    // video.play() can reject AFTER getUserMedia succeeded (e.g. backgrounded
    // mid-start on iOS) — release the acquired stream or it leaks: a retry
    // would overwrite stream.value and the orphaned camera stays on forever.
    stop()
    // If a track-'ended' event already routed through onStreamInterrupted,
    // keep its accurate message — don't relabel the error as a permission one.
    if (errorKind.value === 'interrupted') return
    state.value = 'error'
    if (inAppBrowser) {
      errorKind.value = 'in-app'
      errorMsg.value = IN_APP_MSG
    } else {
      errorKind.value = 'denied'
      errorMsg.value = 'Няма достъп до камерата. Разрешете достъпа в браузъра и опитайте отново — без камера не може да направите селфи.'
    }
  }
}

function onStreamInterrupted() {
  if (state.value !== 'live' && state.value !== 'starting') return
  stop()
  state.value = 'error'
  errorKind.value = 'interrupted'
  errorMsg.value = INTERRUPTED_MSG
}

function onVisibilityChange() {
  if (document.visibilityState !== 'visible' || state.value !== 'live') return
  const health = streamHealth(stream.value?.getTracks())
  if (health === 'ended') {
    onStreamInterrupted()
    return
  }
  if (health === 'muted') {
    // iOS mutes (doesn't end) the track on backgrounding: readyState stays
    // 'live' but the video is frozen black. A play() nudge usually resumes
    // it; if the track is still muted after a grace period, surface the
    // interruption so the user restarts instead of shooting a black selfie.
    video.value?.play().catch(() => {})
    clearTimeout(muteRecoveryTimer)
    muteRecoveryTimer = setTimeout(() => {
      if (state.value !== 'live') return
      if (streamHealth(stream.value?.getTracks()) !== 'ok') onStreamInterrupted()
    }, 1200)
  }
}

async function copyLink() {
  const url = window.location.href
  try {
    await navigator.clipboard.writeText(url)
    linkCopied.value = true
  } catch {
    // Older WebViews without the async clipboard API.
    const ta = document.createElement('textarea')
    ta.value = url
    ta.setAttribute('readonly', '')
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.select()
    try {
      document.execCommand('copy')
      linkCopied.value = true
    } catch {
      // Clipboard completely unavailable — the user can still copy from the address bar.
    }
    document.body.removeChild(ta)
  }
}

function startDetection() {
  if (!detectorSupported) return
  try {
    detector = detector || new window.FaceDetector({ fastMode: true, maxDetectedFaces: 1 })
  } catch {
    detector = null
    return
  }

  detectionActive = true
  const tick = async () => {
    // detectionActive (not just state) — an in-flight detect() survives
    // clearTimeout, and unmount doesn't change state, so without the flag the
    // loop would re-arm forever after onBeforeUnmount.
    if (!detectionActive || state.value !== 'live' || !detector) return
    const v = video.value
    const c = overlay.value
    if (v && c && v.videoWidth) {
      if (c.width !== v.videoWidth) {
        c.width = v.videoWidth
        c.height = v.videoHeight
      }
      try {
        const faces = await detector.detect(v)
        faceDetected.value = faces.length > 0
        drawFaces(c, faces)
      } catch {
        // FaceDetector can throw intermittently — skip this frame.
      }
    }
    if (detectionActive) detectTimer = setTimeout(tick, 160) // ~6fps is plenty for a guide overlay
  }
  tick()
}

function drawFaces(c, faces) {
  const ctx = c.getContext('2d')
  ctx.clearRect(0, 0, c.width, c.height)
  ctx.strokeStyle = 'rgba(74, 222, 128, 0.95)' // green-400
  ctx.lineWidth = Math.max(3, c.width * 0.006)
  for (const f of faces) {
    const b = f.boundingBox
    const r = Math.min(b.width, b.height) * 0.18
    ctx.beginPath()
    ctx.moveTo(b.x + r, b.y)
    ctx.arcTo(b.x + b.width, b.y, b.x + b.width, b.y + b.height, r)
    ctx.arcTo(b.x + b.width, b.y + b.height, b.x, b.y + b.height, r)
    ctx.arcTo(b.x, b.y + b.height, b.x, b.y, r)
    ctx.arcTo(b.x, b.y, b.x + b.width, b.y, r)
    ctx.closePath()
    ctx.stroke()
  }
}

function stopDetection() {
  detectionActive = false
  if (detectTimer) {
    clearTimeout(detectTimer)
    detectTimer = null
  }
  faceDetected.value = false
  const c = overlay.value
  if (c) c.getContext('2d')?.clearRect(0, 0, c.width, c.height)
}

function stop() {
  stopDetection()
  document.removeEventListener('visibilitychange', onVisibilityChange)
  clearTimeout(muteRecoveryTimer)
  if (stream.value) {
    stream.value.getTracks().forEach((t) => {
      t.removeEventListener('ended', onStreamInterrupted)
      t.stop()
    })
    stream.value = null
  }
}

function capture() {
  const v = video.value
  const c = canvas.value
  if (!v || !c) return

  // A dead OR muted stream (iOS backgrounding mutes instead of ending the
  // track) shows a frozen black frame — capturing would bake a BLACK selfie
  // into the KYC submission. Surface the interruption instead.
  if (streamHealth(stream.value?.getTracks()) !== 'ok') {
    onStreamInterrupted()
    return
  }
  // Healthy but videoWidth still 0 = metadata not loaded yet (the first few
  // hundred ms after start). The camera is fine — just not ready to shoot.
  if (!v.videoWidth) return

  const w = v.videoWidth
  const h = v.videoHeight
  c.width = w
  c.height = h

  // Mirror so the saved image matches the selfie preview the user saw.
  const ctx = c.getContext('2d')
  ctx.translate(w, 0)
  ctx.scale(-1, 1)
  ctx.drawImage(v, 0, 0, w, h)

  c.toBlob(
    (blob) => {
      // The callback can outlive the component (navigation mid-capture) — a
      // fresh object URL created then would never be revoked.
      if (unmounted) return
      if (!blob) {
        onStreamInterrupted()
        return
      }
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
  unmounted = true
  stop()
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <div class="rounded-2xl border border-gray-200 overflow-hidden">
    <!-- Viewport: portrait on phones so a face fits comfortably -->
    <div class="relative aspect-[3/4] sm:aspect-[4/3] bg-navy-900 flex items-center justify-center">
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

      <!-- Live face-tracking overlay (only where FaceDetector exists) -->
      <canvas
        ref="overlay"
        v-show="state === 'live' && detectorSupported"
        class="absolute inset-0 w-full h-full object-cover pointer-events-none"
        style="transform: scaleX(-1);"
      ></canvas>

      <!-- Framing guide + status (live) -->
      <template v-if="state === 'live'">
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
          <div
            class="h-[80%] aspect-[3/4] rounded-[50%] border-2 border-dashed transition-colors duration-300"
            :class="faceDetected ? 'border-green-400 shadow-[0_0_30px_rgba(74,222,128,0.45)]' : 'border-white/60'"
          ></div>
        </div>
        <div class="absolute top-3 inset-x-0 flex justify-center px-3 pointer-events-none">
          <span
            class="px-3 py-1 rounded-full text-xs font-medium backdrop-blur transition-colors"
            :class="faceDetected ? 'bg-green-500/90 text-white' : 'bg-black/45 text-white'"
          >{{ statusText }}</span>
        </div>
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
      <!-- In-app browser: retrying is pointless (the host app never forwards the
           camera permission) — offer copying the link for Chrome/Safari instead. -->
      <button
        v-if="state === 'error' && errorKind === 'in-app'"
        type="button"
        @click="copyLink"
        class="flex items-center gap-2 px-5 py-2.5 bg-navy-700 hover:bg-navy-600 text-white text-sm font-semibold rounded-xl transition-colors"
      >
        <svg v-if="!linkCopied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
        <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        {{ linkCopied ? 'Линкът е копиран' : 'Копирай линка' }}
      </button>

      <button
        v-else-if="state === 'idle' || state === 'error'"
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
        class="flex items-center gap-2 px-6 py-2.5 bg-accent-400 hover:bg-accent-500 text-white text-sm font-semibold rounded-xl transition-all"
        :class="faceDetected ? 'ring-2 ring-green-300 ring-offset-1' : ''"
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
