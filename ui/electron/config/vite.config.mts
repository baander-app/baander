import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import electron from 'vite-plugin-electron/simple';
import Info from 'unplugin-info/vite';
import richSvg from 'vite-plugin-react-rich-svg';
import { copyAssets } from './vite-plugin-copy-assets';
import { copyWasmHashes } from './vite-plugin-copy-wasm-hashes';

const mainEntry = resolve(process.cwd(), 'src/main/index.ts');
const preloadEntry = resolve(process.cwd(), 'src/preload/index.ts');

export default defineConfig(({mode}) => {
  // Load only .env* variables (not the entire OS env) to avoid invalid define keys like ProgramFiles(x86)
  const raw = loadEnv(mode, process.cwd(), '');
  const env = Object.fromEntries(
    Object.entries(raw).filter(([k]) => /^([A-Z_][A-Z0-9_]*)$/.test(k)),
  );

  const defineForNode = Object.fromEntries(
    Object.entries(env).map(([k, v]) => [`process.env.${k}`, JSON.stringify(v)]),
  );

  return {
    envDir: process.cwd(),
    root: resolve(process.cwd(), 'src/renderer'),
    // Use absolute base in dev (Vite server), relative base in production (file://)
    base: mode === 'development' ? '/' : './',
    publicDir: resolve(process.cwd(), 'public'),
    server: {
      port: 5175,
      fs: {
        allow: [
          resolve(process.cwd(), ''),
          resolve(process.cwd(), 'resources'),
          resolve(process.cwd(), 'node_modules'),
          resolve(process.cwd(), 'src'),
        ],
      },
    },
    build: {
      outDir: resolve(process.cwd(), 'dist/renderer'),
      emptyOutDir: true,
      sourcemap: true,
      target: ['chrome145', 'esnext'],
      minify: 'esbuild',
      assetsInlineLimit: 0, // Keep WASM as separate files
      rollupOptions: {
        input: {
          main: resolve(process.cwd(), 'src/renderer/index.html'),
          config: resolve(process.cwd(), 'src/renderer/config/index.html'),
        },
        output: {
          // Keep WASM files in dsp/ directory structure
          assetFileNames: (assetInfo) => {
            if (assetInfo.name?.endsWith('.wasm')) {
              return 'dsp/[name][extname]';
            }
            return 'assets/[name]-[hash][extname]';
          },
        },
      },
    },
    resolve: {
      dedupe: ['react', 'react-dom'],
      alias: {
        '@/app': fileURLToPath(new URL('../../web/src', import.meta.url)),
        '@/dsp': fileURLToPath(new URL('../src/dsp', import.meta.url)),
        // Web app aliases — the renderer reuses the web SPA via @/app/main.tsx,
        // so @/features, @/shared, @/, etc. must resolve to the web source.
        '@/shared': fileURLToPath(new URL('../../web/src/shared', import.meta.url)),
        '@/features': fileURLToPath(new URL('../../web/src/features', import.meta.url)),
        '@': fileURLToPath(new URL('../../web/src', import.meta.url)),
      },
    },
    define: {
      __APP_ENV__: JSON.stringify(env.APP_ENV ?? ''),
    },
    plugins: [
      react(),
      richSvg(),
      Info(),
      copyAssets(),
      electron({
        main: {
          entry: mainEntry,
          vite: {
            envDir: process.cwd(),
            plugins: [copyWasmHashes()],
            build: {
              outDir: resolve(process.cwd(), 'dist/main'),
              emptyOutDir: true,
              // sourcemap: false,
              rollupOptions: {
                external: ['electron', '@napi-rs/keyring'],
              },
            },
            define: {
              ...defineForNode,
              'process.env.NODE_ENV': JSON.stringify(mode),
            },
          },
          onstart({startup}) {
            startup(['.', '--remote-debugging-port=9222']);
          },
        },
        preload: {
          input: {preload: preloadEntry},
          vite: {
            envDir: process.cwd(),
            build: {
              outDir: resolve(process.cwd(), 'dist/preload'),
              emptyOutDir: false,
              sourcemap: false,
              // Build preload as CommonJS and name it preload.cjs
              rollupOptions: {
                external: ['electron', '@napi-rs/keyring'],
                output: {
                  format: 'cjs',
                  entryFileNames: 'preload.cjs',
                },
              },
            },
            define: {
              ...defineForNode,
              'process.env.NODE_ENV': JSON.stringify(mode),
            },
          },
        },
      }),
    ],
  };
});
