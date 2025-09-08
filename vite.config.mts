import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'url';
import { resolve } from 'path';
import richSvg from 'vite-plugin-react-rich-svg';
import manifestSRI from 'vite-plugin-manifest-sri';
import react from '@vitejs/plugin-react';
import Info from 'unplugin-info/vite';
import laravelTranslations from 'vite-plugin-laravel-translations';
import Icons from 'unplugin-icons/vite';

const ReactCompilerConfig = {};

// https://vitejs.dev/config/
export default defineConfig(config => {

  // Load env file based on `mode` in the current working directory.
  // https://main.vitejs.dev/config/#using-environment-variables-in-config
  const env = loadEnv(config.mode, process.cwd(), '');

  return {
    define: {
      __APP_ENV__: JSON.stringify(env.APP_ENV),
    },
    server: {
      port: 3000,
    },
    build: {
      sourcemap: true,
      target: ['chrome128', 'firefox128', 'safari16', 'esnext'],
      rollupOptions: {
        treeshake: true,
      },
    },
    plugins: [
      laravel({
        input: [
          'resources/app/index.tsx',
        ],
        refresh: true,
      }),
      react({
        babel: {
          plugins: [['babel-plugin-react-compiler', ReactCompilerConfig]],
        },
      }),
      laravelTranslations({ namespace: 'translation' }),
      Info(),
      Icons({
        compiler: 'jsx',
        jsx: 'react',
        autoInstall: false,
        scale: 1,
        defaultClass: 'icon',
      }),
      richSvg(),
      manifestSRI(),
    ],
    resolve: {
      alias: {
        // for TypeScript path alias import like : @/x/y/z
        '@': fileURLToPath(new URL('./resources/app', import.meta.url)),
        'ziggy-js': resolve('vendor/tightenco/ziggy'),
      },
    },
    css: {
      preprocessorOptions: {
        scss: {
          api: 'modern-compiler',
        },
      },
    },
  };
});
