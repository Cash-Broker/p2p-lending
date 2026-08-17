<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useConsentStore } from '../stores/consent'
import ChatbotWidget from '../components/ChatbotWidget.vue'
import ReConsentModal from '../components/ReConsentModal.vue'
import api from '../api/axios'
// Static import on purpose: a dynamic import() on the logout path can REJECT
// after a deploy rotates chunk hashes, and that would abort logout before the
// session call ever runs (review 2026-08-17). disablePush itself never throws.
import { assertOwnership, disablePush } from '../utils/push'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const consent = useConsentStore()
const sidebarOpen = ref(false)

// Re-consent prompt — shown when Terms/Privacy were updated. Dismissible
// ("По-късно"), but re-opens whenever a gated action is refused (403), which
// bumps promptNonce.
const consentDismissed = ref(false)
const showConsent = computed(() => consent.needsConsent && !consentDismissed.value)
watch(() => consent.promptNonce, () => { consentDismissed.value = false })

async function acceptConsent() {
  await consent.accept()
}

// Notifications
const notifications = ref([])
const unreadCount = ref(0)
const showNotifications = ref(false)

async function loadNotifications() {
  try {
    const { data } = await api.get('/notifications')
    notifications.value = data.notifications
    unreadCount.value = data.unread_count
  } catch {
    // Intentional: notifications are secondary UI.
    // Silent fail preserves main app flow.
  }
}

async function markAsRead(id) {
  await api.post(`/notifications/${id}/read`)
  const n = notifications.value.find(n => n.id === id)
  if (n) n.read_at = new Date().toISOString()
  unreadCount.value = Math.max(0, unreadCount.value - 1)
}

async function markAllRead() {
  await api.post('/notifications/read-all')
  notifications.value.forEach(n => n.read_at = new Date().toISOString())
  unreadCount.value = 0
}

async function deleteNotification(id) {
  await api.delete(`/notifications/${id}`)
  notifications.value = notifications.value.filter(n => n.id !== id)
  unreadCount.value = notifications.value.filter(n => !n.read_at).length
}

async function deleteAllNotifications() {
  await api.delete('/notifications')
  notifications.value = []
  unreadCount.value = 0
}

onMounted(() => {
  loadNotifications()
  consent.check()
  // Claim this device's push subscription for the investor now using it —
  // the admin panel does the same on its pages, so ownership follows the
  // active session on a shared browser.
  assertOwnership()
})

// ── PWA resume re-validation (Reni 2026-08-16) ──
// The installed app resumes the SAME page after hours in the background — no
// page load, no router navigation, so nothing notices the session died until
// a tap dead-ends in «Опитай отново». After being HIDDEN for 5+ minutes,
// becoming visible probes /user: if the session expired, the axios 401
// interceptor hard-redirects to /login BEFORE Reni taps anything; if it is
// alive, the header wallet figures get a free refresh. (fetchUser ignores
// transient network errors — a probe with the radio still down is harmless.)
const RESUME_PROBE_AFTER_MS = 5 * 60_000
let hiddenAt = null

function onVisibilityChange() {
  if (document.visibilityState === 'hidden') {
    hiddenAt = Date.now()
    return
  }
  const wasHiddenFor = hiddenAt ? Date.now() - hiddenAt : 0
  hiddenAt = null
  if (wasHiddenFor >= RESUME_PROBE_AFTER_MS) {
    auth.fetchUser()
  }
}

onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange))
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibilityChange))

// A push tapped while this exact screen is already open: the service worker
// focuses the window and asks for a refresh, so the figures the notification
// announced are the ones actually shown (review 2026-08-17).
function onServiceWorkerMessage(event) {
  if (event.data?.type === 'vama-push-refresh') {
    window.location.reload()
  }
}

onMounted(() => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', onServiceWorkerMessage)
  }
})
onBeforeUnmount(() => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.removeEventListener('message', onServiceWorkerMessage)
  }
})

const navigation = [
  { name: 'Начало', path: '/dashboard', icon: 'home' },
  { name: 'Инвестиране', path: '/invest', icon: 'search' },
  { name: 'Портфолио', path: '/portfolio', icon: 'briefcase' },
  { name: 'Депозиране', path: '/deposit', icon: 'plus-circle' },
  { name: 'Теглене', path: '/withdraw', icon: 'minus-circle' },
  { name: 'Транзакции', path: '/transactions', icon: 'list' },
  { name: 'Профил', path: '/profile', icon: 'user' },
]

function isActive(path) {
  return route.path === path
}

const totalBalance = computed(() => auth.user?.wallet?.total ?? '0.00')

// Legal entities should be identified by their company, not the contact person.
const isLegalEntity = computed(() => auth.user?.account_type === 'legal_entity')
const displayName = computed(() =>
  isLegalEntity.value
    ? (auth.user?.legal_entity_profile?.legal_name || auth.user?.name || '')
    : (auth.user?.name || '')
)
const displayRole = computed(() => (isLegalEntity.value ? 'Юридическо лице' : 'Инвеститор'))

const loggingOut = ref(false)
const logoutError = ref(false)

async function logout() {
  loggingOut.value = true
  logoutError.value = false
  // Forget this device's push subscription BEFORE the session dies — a
  // logged-out (possibly shared) device must not keep receiving pushes.
  // Best-effort by design: never blocks leaving.
  await disablePush()
  try {
    await auth.logout()
    router.push('/login')
  } catch {
    // Network/5xx — the server session may still be ALIVE. Navigating to
    // /login anyway would fake a successful logout on a financial app;
    // stay put and say it didn't work.
    logoutError.value = true
  } finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 font-sans antialiased">
    <!-- Mobile overlay -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-40 bg-black/30 lg:hidden"
      @click="sidebarOpen = false"
    ></div>

    <!-- Sidebar -->
    <aside
      class="fixed top-0 left-0 z-50 h-full w-64 bg-white border-r border-gray-100 flex flex-col transition-transform duration-300 lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <!-- Logo -->
      <div class="h-16 flex items-center px-5 border-b border-gray-100 shrink-0">
        <router-link to="/dashboard" class="flex items-center" aria-label="Vamaasset — табло">
          <img :src="'/logo/logo-mark.png'" alt="Vamaasset" class="h-12 w-auto" width="56" height="48" />
        </router-link>
      </div>

      <!-- Nav -->
      <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
        <router-link
          v-for="item in navigation"
          :key="item.path"
          :to="item.path"
          class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors"
          :class="isActive(item.path) ? 'bg-navy-700 text-white' : 'text-gray-600 hover:bg-gray-50 hover:text-navy-700'"
          @click="sidebarOpen = false"
        >
          <!-- Icons -->
          <svg v-if="item.icon === 'home'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
          <svg v-else-if="item.icon === 'search'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
          <svg v-else-if="item.icon === 'briefcase'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z" /></svg>
          <svg v-else-if="item.icon === 'plus-circle'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
          <svg v-else-if="item.icon === 'minus-circle'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
          <svg v-else-if="item.icon === 'list'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
          <svg v-else-if="item.icon === 'user'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
          {{ item.name }}
        </router-link>
      </nav>

      <!-- Logout (safe-bottom: clears the iOS home indicator when installed) -->
      <div class="p-3 border-t border-gray-100 shrink-0 safe-bottom">
        <button
          @click="logout"
          :disabled="loggingOut"
          class="flex items-center gap-3 w-full px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 hover:bg-red-50 hover:text-red-600 disabled:opacity-50 transition-colors"
        >
          <div v-if="loggingOut" class="size-5 border-2 border-gray-300 border-t-gray-600 rounded-full animate-spin shrink-0"></div>
          <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
          {{ loggingOut ? 'Излизане...' : 'Изход' }}
        </button>
        <p v-if="logoutError" role="alert" class="mt-1.5 px-3 text-xs text-red-500">
          Изходът не се изпълни — проверете връзката и опитайте пак.
        </p>
      </div>
    </aside>

    <!-- Main area -->
    <div class="lg:pl-64">
      <!-- Top header -->
      <header class="sticky top-0 z-30 h-16 bg-white/80 backdrop-blur-lg border-b border-gray-100 flex items-center justify-between px-4 sm:px-6 lg:px-8">
        <!-- Mobile hamburger -->
        <button aria-label="Отвори меню" class="lg:hidden flex items-center justify-center size-10 text-gray-600" @click="sidebarOpen = true">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
        </button>

        <!-- Balance pill -->
        <div class="hidden lg:flex items-center gap-2 px-4 py-1.5 rounded-full bg-gray-50 border border-gray-100">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4 text-accent-500"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" /></svg>
          <span class="text-sm font-semibold text-navy-700">{{ auth.user?.wallet?.available ?? '0.00' }} €</span>
          <span class="text-xs text-gray-500">свободни</span>
        </div>

        <div class="flex items-center gap-4">
          <!-- Notification bell + dropdown -->
          <div class="relative">
            <button aria-label="Известия" @click="showNotifications = !showNotifications; if(showNotifications) loadNotifications()" class="relative flex items-center justify-center size-9 rounded-lg text-gray-500 hover:bg-gray-50 transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
              <span v-if="unreadCount > 0" class="absolute -top-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-red-500 text-white text-[10px] font-bold">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </button>
            <!-- Dropdown -->
            <div v-if="showNotifications" class="absolute right-0 top-11 w-80 rounded-2xl bg-white border border-gray-100 shadow-xl z-50 overflow-hidden">
              <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                <p class="text-sm font-bold text-navy-700">Известия</p>
                <div class="flex items-center gap-2">
                  <button v-if="unreadCount > 0" @click="markAllRead" class="text-xs text-accent-500 font-medium">Прочетени</button>
                  <button v-if="notifications.length" @click="deleteAllNotifications" class="text-xs text-red-500 font-medium">Изтрий всички</button>
                </div>
              </div>
              <div class="max-h-80 overflow-y-auto divide-y divide-gray-50">
                <div v-if="!notifications.length" class="px-4 py-8 text-center text-sm text-gray-400">Няма известия</div>
                <div v-for="n in notifications.slice(0, 10)" :key="n.id" class="flex items-start gap-2 px-4 py-3 hover:bg-gray-50 transition-colors" :class="!n.read_at ? 'bg-accent-50/30' : ''">
                  <button @click="markAsRead(n.id)" class="flex-1 text-left">
                    <p class="text-sm text-navy-700">{{ { deposit_approved: 'Депозит одобрен', deposit_rejected: 'Депозит отхвърлен', withdrawal_approved: 'Теглене одобрено', withdrawal_rejected: 'Теглене отхвърлено', kyc_approved: 'KYC одобрен', kyc_rejected: 'KYC отхвърлен', loan_status_changed: 'Промяна на кредит', bonus_credited: 'Получен бонус', promo_started: '⚡ Промо оферта' }[n.data?.type] || 'Известие' }}</p>
                    <p v-if="n.data?.message" class="text-xs text-gray-600 mt-0.5">{{ n.data.message }}</p>
                    <p v-if="n.data?.amount" class="text-xs text-accent-500 font-medium">{{ n.data.amount }} €</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ new Date(n.created_at).toLocaleString('bg-BG', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</p>
                  </button>
                  <button aria-label="Изтрий известие" @click.stop="deleteNotification(n.id)" class="text-gray-500 hover:text-red-500 mt-1 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                  </button>
                </div>
              </div>
            </div>
            <div v-if="showNotifications" class="fixed inset-0 z-40" @click="showNotifications = false"></div>
          </div>

          <!-- User info -->
          <div class="flex items-center gap-3">
            <div class="hidden sm:block text-right">
              <p class="text-sm font-semibold text-navy-700 leading-tight">{{ displayName }}</p>
              <p class="text-xs text-gray-400">{{ displayRole }}</p>
            </div>
            <div class="flex size-9 items-center justify-center rounded-full bg-navy-700/10 text-navy-700 text-sm font-bold">
              {{ displayName?.charAt(0) ?? '?' }}
            </div>
          </div>
        </div>
      </header>

      <!-- Content -->
      <main class="p-4 sm:p-6 lg:p-8">
        <router-view />
      </main>
    </div>

    <ChatbotWidget />

    <ReConsentModal
      v-if="showConsent"
      :docs="consent.pending"
      @accept="acceptConsent"
      @later="consentDismissed = true"
    />
  </div>
</template>
