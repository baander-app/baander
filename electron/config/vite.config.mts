import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import electron from 'vite-plugin-electron/simple';
import Info from 'unplugin-info/vite';
import richSvg from 'vite-plugin-react-rich-svg';

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
    server: {
      port: 5175,
      fs: {
        allow: [
          resolve(process.cwd(), ''),
          resolve(process.cwd(), 'resources'),
          resolve(process.cwd(), 'node_modules'),
        ],
      },
    },
    build: {
      outDir: resolve(process.cwd(), 'electron/dist'),
      emptyOutDir: true,
      sourcemap: true,
      target: ['chrome128', 'esnext'],
      rollupOptions: {
        input: {
          main: resolve(process.cwd(), 'electron/src/renderer/index.html'),
          config: resolve(process.cwd(), 'electron/src/renderer/config/index.html'),
        },
      },
    },

    resolve: {
      alias: {
        '@': fileURLToPath(new URL('../../resources/app', import.meta.url)),
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
      electron({
        main: {
          entry: mainEntry,
          vite: {
            envDir: process.cwd(),
            build: {
              outDir: resolve(process.cwd(), 'electron/dist-electron/main'),
              emptyOutDir: true,
              sourcemap: true,
            },
            define: {
              ...defineForNode,
              'process.env.NODE_ENV': JSON.stringify(mode),
            },
          },
          onstart({ startup }) {
            startup();
          },
        },
        preload: {
          input: { preload: preloadEntry },
          vite: {
            envDir: process.cwd(),
            build: {
              outDir: resolve(process.cwd(), 'electron/dist-electron'),
              emptyOutDir: false,
              sourcemap: true,
              // Build preload as CommonJS and name it preload.cjs
              rollupOptions: {
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
