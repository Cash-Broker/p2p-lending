import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api/axios'
import axios from 'axios'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const isAuthenticated = computed(() => !!user.value)

  async function fetchUser() {
    try {
      const { data } = await api.get('/user')
      user.value = data
    } catch (e) {
      // Null the user only when the server actually said "not authenticated".
      // A transient network failure (PWA resuming with the radio still down)
      // must not wipe a live session — that faked a logout with no banner and
      // half-broken UI (review 2026-08-16). Guest boot is unaffected: user
      // starts null and the guest /user probe DOES respond with 401.
      const status = e.response?.status
      if (status === 401 || status === 419) {
        user.value = null
      }
    }
  }

  async function login(credentials) {
    // Sanctum SPA auth requires fetching CSRF cookie first
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
    await api.post('/login', credentials)
    await fetchUser()
  }

  async function register(data) {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
    await api.post('/register', data)
  }

  async function logout() {
    // Swallow ONLY the expired-session statuses: there the server session is
    // already gone and leaving `user` set made the router guard bounce
    // «Изход» straight back into the broken authed area (PWA dead end, Reni
    // 2026-08-16). A network error / 5xx rethrows — the cookie is still
    // VALID then, and pretending she logged out on a financial platform
    // (shared device!) would be a lie; the caller surfaces the failure.
    try {
      await api.post('/logout')
    } catch (e) {
      const status = e.response?.status
      if (status !== 401 && status !== 419) throw e
    }
    user.value = null
  }

  return {
    user,
    isAuthenticated,
    fetchUser,
    login,
    register,
    logout,
  }
})
