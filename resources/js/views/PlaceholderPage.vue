<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()

const titles = {
  deposit: 'Депозиране',
  withdraw: 'Теглене',
  invest: 'Инвестиране',
  portfolio: 'Портфолио',
  transactions: 'Транзакции',
  profile: 'Профил',
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-navy-700 mb-2">{{ titles[route.name] || route.name }}</h1>
    <p class="text-sm text-gray-500">Тази страница е в процес на разработка.</p>
  </div>
</template>
