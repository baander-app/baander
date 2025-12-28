import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import electron from 'vite-plugin-electron/simple';
import Info from 'unplugin-info/vite';
import richSvg from 'vite-plugin-react-rich-svg';
import Icons from 'unplugin-icons/vite';

const ReactCompilerConfig = {};
const mainEntry = resolve(process.cwd(), 'electron/src/main/index.ts');
const preloadEntry = resolve(process.cwd(), 'electron/src/preload/index.ts');

export default defineConfig(({ mode }) => {
  // Load only .env* variables (not the entire OS env) to avoid invalid define keys like ProgramFiles(x86)
  const raw = loadEnv(mode, process.cwd(), '');
  const env = Object.fromEntries(
    Object.entries(raw).filter(([k]) => /^([A-Z_][A-Z0-9_]*)$/.test(k))
  );

  const defineForNode = Object.fromEntries(
    Object.entries(env).map(([k, v]) => [`process.env.${k}`, JSON.stringify(v)])
  );

  return {
    envDir: process.cwd(),
    root: resolve(process.cwd(), 'electron/src/renderer'),
    // Use absolute base in dev (Vite server), relative base in production (file://)
    base: mode === 'development' ? '/' : './',
    publicDir: resolve(process.cwd(), 'electron/public'),
    server: {
      port: 5175,
      fs: {
        allow: [
          resolve(process.cwd(), ''),
          resolve(process.cwd(), 'resources'),
          resolve(process.cwd(), 'node_modules'),
          resolve(process.cwd(), 'electron/src'),
        ],
      },
    },
    build: {
      outDir: resolve(process.cwd(), 'electron/dist-electron/renderer'),
      emptyOutDir: true,
      sourcemap: false,
      target: ['chrome128', 'esnext'],
      minify: 'esbuild',
      assetsInlineLimit: 0, // Keep WASM as separate files
      rollupOptions: {
        input: {
          main: resolve(process.cwd(), 'electron/src/renderer/index.html'),
          config: resolve(process.cwd(), 'electron/src/renderer/config/index.html'),
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
    esbuild: {
      // drop: ['console', 'debugger'],
    },
    resolve: {
      alias: {
        '@/app': fileURLToPath(new URL('../../resources/app', import.meta.url)),
        '@/dsp': fileURLToPath(new URL('../src/dsp', import.meta.url)),
      },
    },
    define: {
      __APP_ENV__: JSON.stringify(env.APP_ENV ?? ''),
    },
    plugins: [
      react({
        babel: { plugins: [['babel-plugin-react-compiler', ReactCompilerConfig]] },
      }),
      richSvg(),
      Info(),
      Icons({
        compiler: 'jsx',
        jsx: 'react',
        autoInstall: false,            // set true if you want it to auto-add @iconify-json/* on demand
        // Optional niceties:
        scale: 1,                      // 1 = 1em; can be number or string like '1.2em'
        defaultClass: 'icon',
        // defaultStyle: 'vertical-align: -0.125em;', // handy for baseline alignment
      }),
      electron({
        main: {
          entry: mainEntry,
          vite: {
            envDir: process.cwd(),
            build: {
              outDir: resolve(process.cwd(), 'electron/dist-electron/main'),
              emptyOutDir: true,
              sourcemap: false,
              rollupOptions: {
                external: ['@napi-rs/keyring'],
              },
            },
            define: {
              ...defineForNode,
              'process.env.NODE_ENV': JSON.stringify(mode),
            },
          },
          onstart({ startup }) {
            startup(['.', '--remote-debugging-port=9222'])
          },
        },
        preload: {
          input: { preload: preloadEntry },
          vite: {
            envDir: process.cwd(),
            build: {
              outDir: resolve(process.cwd(), 'electron/dist-electron/preload'),
              emptyOutDir: false,
              sourcemap: false,
              // Build preload as CommonJS and name it preload.cjs
              rollupOptions: {
                external: ['@napi-rs/keyring'],
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
