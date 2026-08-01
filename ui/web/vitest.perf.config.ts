import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': new URL('./src', import.meta.url).pathname,
      '@/shared': new URL('./src/shared', import.meta.url).pathname,
      '@tests': new URL('./tests', import.meta.url).pathname,
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    include: ['tests/**/*.perf-test.{ts,tsx}'],
    setupFiles: ['./tests/setup.ts'],
  },
})
