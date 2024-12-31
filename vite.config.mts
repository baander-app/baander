import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
// import react from '@vitejs/plugin-react-swc';
import { fileURLToPath } from 'url';
import { resolve } from 'path';
import richSvg from 'vite-plugin-react-rich-svg';
import manifestSRI from 'vite-plugin-manifest-sri';
import filterReplace from 'vite-plugin-filter-replace';
// import inspect from 'vite-plugin-inspect';
import react from "@vitejs/plugin-react";

const ReactCompilerConfig = {};


const lottieScopeVariables = [
  'value',
  'content',
  'loopOut',
  'numKeys',
  '$bm_mul',
  '$bm_sum',
  '$bm_sub',
  '$bm_div',
  '$bm_mod',
  '$bm_isInstanceOfArray',
  '$bm_transform',
  'anchorPoint',
  'time',
  'velocity',
  'inPoint',
  'outPoint',
  'width',
  'height',
  'name',
  'loop_in',
  'loop_out',
  'smooth',
  'toComp',
  'fromCompToSurface',
  'toWorld',
  'fromWorld',
  'mask',
  'position',
  'rotation',
  'scale',
  'thisComp',
  'active',
  'wiggle',
  'loopInDuration',
  'loopOutDuration',
  'comp',
  'lookAt',
  'easeOut',
  'easeIn',
  'ease',
  'nearestKey',
  'key',
  'text',
  'textIndex',
  'textTotal',
  'selectorValue',
  'framesToTime',
  'timeToFrames',
  'sourceRectAtTime',
  'substring',
  'substr',
  'posterizeTime',
  'index',
  'globalData',
  'frames',
  '$bm_neg',
  'add',
  'clamp',
  'radians_to_degrees',
  'degreesToRadians',
  'degrees_to_radians',
  'normalize',
  'rgbToHsl',
  'hslToRgb',
  'linear',
  'random',
  'createPath',
  '_lottieGlobal',
  'transform',
  'effect',
  'thisProperty',
  'loopIn',
  'fromComp',
  'thisLayer',
  'valueAtTime',
  'velocityAtTime',
];

// https://vitejs.dev/config/
export default defineConfig(config => {

  // Load env file based on `mode` in the current working directory.
  // https://main.vitejs.dev/config/#using-environment-variables-in-config
  const env = loadEnv(config.mode, process.cwd(), '');

  return {
    define: {
      __APP_ENV__: JSON.stringify(env.APP_ENV),
    },
    build: {
      sourcemap: true,
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
          plugins: [["babel-plugin-react-compiler", ReactCompilerConfig]],
        },
      }),
      richSvg(),
      manifestSRI(),
      // workaround for a warning with lottie https://github.com/airbnb/lottie-web/issues/2927
      filterReplace([
        {
          filter: ['node_modules/lottie-web/build/player/lottie.js'],
          replace: {
            from: "eval('[function _expression_function(){' + val + ';scoped_bm_rt=$bm_rt}]')[0]",
            to: `
          function _expression_function() {
            var valToEval = val;
            scoped_bm_rt = (new Function(
              'valToEval', ${lottieScopeVariables.map((v) => `'${v}'`).join(',')},
              'try {'
                + val + \`;
                return $bm_rt;
              } catch (e) {
                console.error("Error in lottie-web workaround. Fix the issue in vite.config.ts:", e, "Failed expression:", valToEval);
                throw e;
              }\`
            ))(valToEval, ${lottieScopeVariables.join(',')});
          }`,
          },
        },
      ]),
      // inspect(),
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
          additionalData: `@import "./resources/app/_mantine";`,
        },
      },
    },
  };
});
