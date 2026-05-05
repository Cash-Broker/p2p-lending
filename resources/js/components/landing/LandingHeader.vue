<script setup>
import { ref } from 'vue'

const mobileMenuOpen = ref(false)
</script>

<template>
  <header class="fixed top-0 inset-x-0 z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex h-24 items-center justify-between">
        <a href="/" class="flex items-center" aria-label="Vamaasset — начална страница">
          <img :src="'/logo/logo.png'" alt="Vamaasset" class="h-20 w-auto" width="80" height="80" />
        </a>

        <nav class="hidden md:flex items-center gap-8">
          <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">Как работи</a>
          <a href="#advantages" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">За инвеститори</a>
          <a href="#loans" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">Кредити</a>
          <a href="#originators" class="text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors">Контакти</a>
        </nav>

        <div class="hidden md:flex items-center gap-3">
          <router-link to="/login" class="text-sm font-medium text-navy-700 hover:text-navy-600 transition-colors px-4 py-2">Вход</router-link>
          <router-link to="/register" class="text-sm font-medium text-white bg-navy-700 hover:bg-navy-600 transition-colors px-5 py-2 rounded-lg">Регистрация</router-link>
        </div>

        <button :aria-label="mobileMenuOpen ? 'Затвори меню' : 'Отвори меню'" class="md:hidden flex items-center justify-center size-10 text-gray-600" @click="mobileMenuOpen = !mobileMenuOpen">
          <svg v-if="!mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
        </button>
      </div>

      <div v-if="mobileMenuOpen" class="md:hidden border-t border-gray-100 py-4 space-y-2">
        <a href="#how-it-works" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Как работи</a>
        <a href="#advantages" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">За инвеститори</a>
        <a href="#loans" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Кредити</a>
        <a href="#originators" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Контакти</a>
        <div class="flex gap-3 pt-3 border-t border-gray-100 px-3">
          <router-link to="/login" class="flex-1 text-center text-sm font-medium text-navy-700 border border-navy-700 px-4 py-2 rounded-lg">Вход</router-link>
          <router-link to="/register" class="flex-1 text-center text-sm font-medium text-white bg-navy-700 px-4 py-2 rounded-lg">Регистрация</router-link>
        </div>
      </div>
    </div>
  </header>
</template>
