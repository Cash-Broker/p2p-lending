<script setup>
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="min-h-screen bg-gray-50 p-8">
    <div class="mx-auto max-w-4xl">
      <h1 class="text-2xl font-bold text-navy-700">Dashboard</h1>
      <p class="text-gray-500 mt-2">Добре дошъл, {{ auth.user?.name }}</p>
    </div>
  </div>
</template>
