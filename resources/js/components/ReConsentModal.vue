<script setup>
import { ref } from 'vue'

const props = defineProps({
  docs: { type: Array, required: true }, // [{ type, title, url, version }]
})
const emit = defineEmits(['accept', 'later'])

const agreed = ref(false)
const loading = ref(false)
const error = ref(null)

async function accept() {
  if (!agreed.value) return
  loading.value = true
  error.value = null
  try {
    await Promise.resolve(emit('accept'))
  } catch {
    error.value = 'Грешка при записване на съгласието. Опитайте отново.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/40"></div>
      <div class="relative bg-white rounded-2xl p-6 max-w-md w-full shadow-xl">
        <h3 class="text-lg font-bold text-navy-700 mb-1">Обновени условия</h3>
        <p class="text-sm text-gray-500 mb-4">
          Актуализирахме следните документи. Моля, прегледайте ги и потвърдете съгласието си, за да продължите да ползвате финансовите функции.
        </p>

        <ul class="space-y-2 mb-4">
          <li v-for="doc in docs" :key="doc.type" class="flex items-center justify-between rounded-xl bg-gray-50 px-3 py-2.5">
            <span class="text-sm font-medium text-navy-700">{{ doc.title }}</span>
            <router-link :to="doc.url" target="_blank" class="text-xs text-accent-500 hover:text-accent-600 font-medium underline">
              Прегледай ({{ doc.version }})
            </router-link>
          </li>
        </ul>

        <div v-if="error" class="rounded-xl bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-4">{{ error }}</div>

        <label class="flex items-start gap-2.5 mb-5">
          <input v-model="agreed" type="checkbox" class="mt-0.5 size-4 rounded border-gray-300 text-accent-400 focus:ring-accent-400/50" />
          <span class="text-sm text-gray-600 leading-relaxed">
            Прочетох и приемам обновените документи по-горе.
          </span>
        </label>

        <div class="flex gap-3">
          <button
            type="button"
            @click="emit('later')"
            class="flex-1 py-2.5 border border-gray-200 text-sm font-medium text-gray-600 rounded-xl hover:bg-gray-50 transition-colors"
          >
            По-късно
          </button>
          <button
            type="button"
            @click="accept"
            :disabled="!agreed || loading"
            class="flex-1 py-2.5 bg-accent-400 hover:bg-accent-500 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
          >
            {{ loading ? 'Записване...' : 'Приемам' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
