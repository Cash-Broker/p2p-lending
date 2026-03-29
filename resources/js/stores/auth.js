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
    } catch {
      user.value = null
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
    await api.post('/logout')
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
