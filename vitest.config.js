import { defineConfig } from 'vitest/config'

// Standalone Vitest config — deliberately does NOT reuse vite.config.js
// (the Laravel plugin there expects a running Laravel context). Pure unit
// tests over plain JS modules only; component tests would need jsdom + Vue
// plugin added here.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['resources/js/**/*.test.js'],
  },
})
