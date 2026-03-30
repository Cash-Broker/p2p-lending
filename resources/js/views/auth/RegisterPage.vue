<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  terms_accepted: false,
})
const errors = ref({})
const loading = ref(false)

async function submit() {
  errors.value = {}
  loading.value = true
  try {
    await auth.register(form.value)
    router.push('/verify-email')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      errors.value = { email: ['Възникна грешка. Опитайте отново.'] }
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <router-link to="/" class="inline-flex items-center gap-2">
          <div class="flex size-10 items-center justify-center rounded-xl bg-navy-700 text-white text-sm font-bold">P2</div>
          <span class="text-xl font-bold text-navy-700">P2P Invest</span>
        </router-link>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8">
        <h1 class="text-2xl font-bold text-navy-700 mb-1">Регистрация</h1>
        <p class="text-sm text-gray-500 mb-6">Създай акаунт и започни да инвестираш</p>

        <form @submit.prevent="submit" class="space-y-4">
          <div>
            <label for="name" class="block text-sm font-medium text-navy-700 mb-1">Име</label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              required
              autocomplete="name"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.name ? 'border-red-400' : ''"
              placeholder="Иван Иванов"
            />
            <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name[0] }}</p>
          </div>

          <div>
            <label for="email" class="block text-sm font-medium text-navy-700 mb-1">Имейл</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              required
              autocomplete="email"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.email ? 'border-red-400' : ''"
              placeholder="ime@example.com"
            />
            <p v-if="errors.email" class="mt-1 text-xs text-red-500">{{ errors.email[0] }}</p>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-navy-700 mb-1">Парола</label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              required
              autocomplete="new-password"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              :class="errors.password ? 'border-red-400' : ''"
              placeholder="Минимум 8 символа"
            />
            <p v-if="errors.password" class="mt-1 text-xs text-red-500">{{ errors.password[0] }}</p>
          </div>

          <div>
            <label for="password_confirmation" class="block text-sm font-medium text-navy-700 mb-1">Потвърди парола</label>
            <input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              required
              autocomplete="new-password"
              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-accent-400/50 focus:border-accent-400 transition-colors"
              placeholder="Повтори паролата"
            />
          </div>

          <!-- Legal consent checkbox -->
          <div>
            <label class="flex items-start gap-2.5">
              <input
                v-model="form.terms_accepted"
                type="checkbox"
                class="mt-0.5 size-4 rounded border-gray-300 text-accent-400 focus:ring-accent-400/50"
              />
              <span class="text-xs text-gray-500 leading-relaxed">
                Съгласявам се с
                <a href="#" class="text-accent-500 hover:text-accent-600 underline">Условията за ползване</a>,
                <a href="#" class="text-accent-500 hover:text-accent-600 underline">Политиката за поверителност</a>
                и <a href="#" class="text-accent-500 hover:text-accent-600 underline">Предупреждението за риск</a>.
                Разбирам, че инвестирането в кредити носи риск.
              </span>
            </label>
            <p v-if="errors.terms_accepted" class="mt-1 text-xs text-red-500">{{ errors.terms_accepted[0] }}</p>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Регистриране...' : 'Регистрирай се' }}
          </button>
        </form>
      </div>

      <p class="text-center text-sm text-gray-500 mt-6">
        Вече имаш акаунт?
        <router-link to="/login" class="text-accent-500 hover:text-accent-600 font-medium">Влез</router-link>
      </p>
    </div>
  </div>
</template>
