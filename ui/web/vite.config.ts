/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import symfonyPlugin from 'vite-plugin-symfony';
import { build } from 'esbuild';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const WORKER_SRC = resolve(__dirname, 'src/features/player/services/auth-stream-worker.ts');
const WORKER_DEST = resolve(__dirname, '../../public/auth-stream-worker.js');

function compileWorker() {
  mkdirSync(resolve(__dirname, '../../public'), { recursive: true });
  build({
    entryPoints: [WORKER_SRC],
    outfile: WORKER_DEST,
    bundle: false,
    format: 'iife',
    target: 'es2020',
    write: true,
    logLevel: 'silent',
  });
}

function copyServiceWorker(): import('vite').Plugin {
  return {
    name: 'copy-service-worker',
    buildEnd() {
      compileWorker();
    },
    configureServer(server) {
      compileWorker();
      server.watcher.on('change', (file) => {
        if (file === WORKER_SRC) {
          compileWorker();
        }
      });
    },
  };
}

export default defineConfig({
  base: '/',
  plugins: [react(),
    symfonyPlugin({
      viteDevServerHostname: 'localhost',
    }),
    copyServiceWorker(),
  ],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@/shared': resolve(__dirname, 'src/shared'),
    },
  },
  build: {
    outDir: resolve(__dirname, '../../public'),
    emptyOutDir: false,
    rollupOptions: {
      input: {
        app: './src/main.tsx',
      },
    },
  },
  server: {
    host: '0.0.0.0',
    cors: true,
    port: 5174,
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./tests/setup.ts'],
  },
});
