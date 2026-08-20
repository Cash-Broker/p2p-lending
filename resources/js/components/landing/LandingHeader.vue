<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { sectionLink } from '../../utils/landingNav'
import { loansPageRedirect } from '../../utils/publicGate'

const auth = useAuthStore()
const route = useRoute()
const mobileMenuOpen = ref(false)

// This header is no longer homepage-only — /loans and /originators render it
// too, so a section link has to carry the visitor back to «/» + hash from
// there (resolved by the router's scrollBehavior).
const section = (id) => sectionLink(id, route.path)

// «Кредити» scrolls to the section like every other nav item — except for an
// approved investor, who gets what Reni asked for: their own loans, one click.
const loansTarget = computed(() => loansPageRedirect(auth.user) ?? section('loans'))
</script>

<template>
  <header class="fixed top-0 inset-x-0 z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex h-16 items-center justify-between">
        <router-link to="/" class="flex items-center" aria-label="Vamaasset — начална страница">
          <img :src="'/logo/logo-mark.png'" alt="Vamaasset" class="h-12 w-auto" width="56" height="48" />
        </router-link>

        <nav class="hidden md:flex items-center gap-4 lg:gap-8">
          <router-link :to="section('how-it-works')" class="text-[13px] lg:text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors whitespace-nowrap">Как работи</router-link>
          <router-link :to="section('advantages')" class="text-[13px] lg:text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors whitespace-nowrap">За инвеститори</router-link>
          <router-link :to="loansTarget" class="text-[13px] lg:text-sm font-medium transition-colors whitespace-nowrap"
            :class="route.path === '/loans' ? 'text-navy-700 font-semibold' : 'text-gray-600 hover:text-navy-700'">Кредити</router-link>
          <router-link :to="section('originators')" class="text-[13px] lg:text-sm font-medium transition-colors whitespace-nowrap"
            :class="route.path === '/originators' ? 'text-navy-700 font-semibold' : 'text-gray-600 hover:text-navy-700'">Оригинатори</router-link>
          <router-link :to="section('faq')" class="text-[13px] lg:text-sm font-medium text-gray-600 hover:text-navy-700 transition-colors whitespace-nowrap">Въпроси</router-link>
        </nav>

        <div class="hidden md:flex items-center gap-3">
          <template v-if="auth.isAuthenticated">
            <router-link to="/dashboard" class="text-sm font-medium text-white bg-navy-700 hover:bg-navy-600 transition-colors px-5 py-2 rounded-lg whitespace-nowrap">Към таблото</router-link>
          </template>
          <template v-else>
            <router-link to="/login" class="text-sm font-medium text-navy-700 hover:text-navy-600 transition-colors px-3 lg:px-4 py-2">Вход</router-link>
            <router-link to="/register" class="text-sm font-medium text-white bg-navy-700 hover:bg-navy-600 transition-colors px-4 lg:px-5 py-2 rounded-lg">Регистрация</router-link>
          </template>
        </div>

        <button :aria-label="mobileMenuOpen ? 'Затвори меню' : 'Отвори меню'" class="md:hidden flex items-center justify-center size-10 text-gray-600" @click="mobileMenuOpen = !mobileMenuOpen">
          <svg v-if="!mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
        </button>
      </div>

      <div v-if="mobileMenuOpen" class="md:hidden border-t border-gray-100 py-4 space-y-2">
        <router-link :to="section('how-it-works')" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Как работи</router-link>
        <router-link :to="section('advantages')" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">За инвеститори</router-link>
        <router-link :to="loansTarget" class="block px-3 py-2 text-sm font-medium rounded-lg"
          :class="route.path === '/loans' ? 'text-navy-700 font-semibold bg-navy-50' : 'text-gray-600 hover:bg-gray-50'" @click="mobileMenuOpen = false">Кредити</router-link>
        <router-link :to="section('originators')" class="block px-3 py-2 text-sm font-medium rounded-lg"
          :class="route.path === '/originators' ? 'text-navy-700 font-semibold bg-navy-50' : 'text-gray-600 hover:bg-gray-50'" @click="mobileMenuOpen = false">Оригинатори</router-link>
        <router-link :to="section('faq')" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-lg" @click="mobileMenuOpen = false">Въпроси</router-link>
        <div class="flex gap-3 pt-3 border-t border-gray-100 px-3">
          <template v-if="auth.isAuthenticated">
            <router-link to="/dashboard" class="flex-1 text-center text-sm font-medium text-white bg-navy-700 px-4 py-2 rounded-lg">Към таблото</router-link>
          </template>
          <template v-else>
            <router-link to="/login" class="flex-1 text-center text-sm font-medium text-navy-700 border border-navy-700 px-4 py-2 rounded-lg">Вход</router-link>
            <router-link to="/register" class="flex-1 text-center text-sm font-medium text-white bg-navy-700 px-4 py-2 rounded-lg">Регистрация</router-link>
          </template>
        </div>
      </div>
    </div>
  </header>
</template>
