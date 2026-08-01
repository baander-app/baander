import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    lib: {
      entry: resolve(__dirname, 'src/index.ts'),
      name: 'BaanderPlayer',
      formats: ['es', 'cjs'],
      fileName: 'baander-player',
    },
    rollupOptions: {
      external: ['three'],
    },
    target: 'es2022',
    sourcemap: true,
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    include: ['tests/unit/**/*.test.ts', 'tests/e2e/**/*.test.ts'],
  },
});
