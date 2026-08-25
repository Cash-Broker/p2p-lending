<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/auth'

// Blocking BY DESIGN (client decision 2026-08-25): no «По-късно», no ✕ —
// accounts created before the phone became mandatory must add one to keep
// using the app. Sends ONLY the phone (name rides `sometimes` rules server-
// side — co-submitting a legacy name that fails the 2026-08-07 control-char
// regex would hard-lock the account with a 422 the modal can't fix). The
// modal unmounts when the fresh /user payload carries the phone (see
// utils/phoneGate.js).
//
// «Изход» stays available: blocking the app must not trap the SESSION on a
// shared device. Logging out is not an escape hatch — the modal returns on
// the next login. The parent (AppLayout) owns the logout flow, so we emit.
const emit = defineEmits(['logout'])
defineProps({
  loggingOut: { type: Boolean, default: false },
})

const auth = useAuthStore()

const phone = ref('')
const error = ref(null)
const loading = ref(false)
const phoneInput = ref(null)

// The app behind the overlay must be inert for KEYBOARD users too — the
// backdrop only swallows pointer events, while Tab would happily reach the
// sidebar and forms behind it. `inert` blocks focus + click natively; the
// modal itself is teleported to <body>, outside #app, so it stays usable.
onMounted(() => {
  document.getElementById('app')?.setAttribute('inert', '')
  phoneInput.value?.focus()
})
onBeforeUnmount(() => {
  document.getElementById('app')?.removeAttribute('inert')
})

async function save() {
  if (loading.value) return
  loading.value = true
  error.value = null
  try {
    await api.put('/profile', { phone: phone.value.trim() })
    await auth.fetchUser()
  } catch (e) {
    if (e.response?.status === 422) {
      error.value = e.response.data.errors?.phone?.[0] || 'Невалиден телефонен номер.'
    } else {
      error.value = 'Грешка при записване. Проверете връзката и опитайте отново.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/40"></div>
      <div role="dialog" aria-modal="true" aria-labelledby="required-phone-title" class="relative bg-white rounded-2xl p-6 max-w-md w-full shadow-xl">
        <h3 id="required-phone-title" class="text-lg font-bold text-navy-700 mb-1">Добавете телефонен номер</h3>
        <p class="text-sm text-gray-500 mb-4">
          Телефонният номер вече е задължителен за всички инвеститорски акаунти.
          Използваме го единствено за връзка с вас — при въпроси по депозити,
          тегления и верификация.
        </p>

        <form @submit.prevent="save" class="space-y-4">
          <div>
            <label for="required-phone" class="block text-sm font-medium text-navy-700 mb-1">Телефон</label>
            <input
              id="required-phone"
              ref="phoneInput"
              v-model="phone"
              type="tel"
              required
              autocomplete="tel"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="error ? 'border-red-400' : ''"
              placeholder="+359 88 123 4567"
            />
            <p v-if="error" role="alert" aria-live="polite" class="mt-1 text-xs text-red-500">{{ error }}</p>
          </div>

          <button
            type="submit"
            :disabled="loading || !phone.trim()"
            class="w-full py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Записване...' : 'Запази' }}
          </button>
        </form>

        <button
          type="button"
          @click="emit('logout')"
          :disabled="loggingOut"
          class="mt-4 w-full text-center text-xs text-gray-500 hover:text-red-600 disabled:opacity-50 transition-colors"
        >
          {{ loggingOut ? 'Излизане...' : 'Изход от акаунта' }}
        </button>
      </div>
    </div>
  </Teleport>
</template>
