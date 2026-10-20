import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdir, copyFile, readFile } from 'node:fs/promises';
import type { Plugin } from 'vite';
import { readFileSync } from 'node:fs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(__dirname, '../../..');
const electronDir = resolve(__dirname, '..');

// Read WASM modules from shared JSON file
const wasmModulesJson = readFileSync(resolve(electronDir, 'src/shared/wasm-modules.json'), 'utf-8');
const WASM_MODULES = JSON.parse(wasmModulesJson) as readonly ['spectral_features', 'dynamics_meter', 'fft2048', 'loudness_r128', 'partitioned_convolver', 'resampler_hq'];

const REQUIRED_LOADERS = new Set([
  'spectral_features', 'dynamics_meter', 'loudness_r128',
  'partitioned_convolver', 'resampler_hq',
]);
const DSP_ASSETS = WASM_MODULES.flatMap(module => [
  { module, filename: `${module}.wasm` },
  ...(REQUIRED_LOADERS.has(module) ? [{ module, filename: `${module}.js` }] : []),
]);

async function validateDspAssets(): Promise<void> {
  for (const { module, filename } of DSP_ASSETS) {
    const path = resolve(rootDir, 'packages/dsp', module, filename);
    try {
      await readFile(path);
    } catch (cause) {
      throw new Error(
        `Required DSP asset unavailable: ${path}. Restore the checked-in DSP assets, ` +
        'or regenerate qualified analysis modules with yarn --cwd ui/electron build:wasm.',
        { cause },
      );
    }
  }
}

/**
 * Vite plugin to serve WASM modules and audio worklets in dev
 * and copy them to the build output in production
 */
export function copyAssets(): Plugin {
  const packagesDspDir = resolve(rootDir, 'packages/dsp');
  const publicWorkletsDir = resolve(rootDir, 'public/audio-worklets');
  const ziggyJsPath = resolve(rootDir, 'resources/app/ziggy.js');

  return {
    name: 'copy-electron-assets',

    // In dev mode, serve the files via middleware
    async configureServer(server) {
      await validateDspAssets();

      // Add middleware to serve files
      server.middlewares.use(async (req, res, next) => {
        // Handle DSP files (/dsp/*.wasm and /dsp/*.js)
        if (req.url?.startsWith('/dsp/')) {
          const filename = req.url.slice(5); // Remove '/dsp/'
          const module = WASM_MODULES.find(m => filename === `${m}.wasm` || filename === `${m}.js`);

          if (module) {
            try {
              const srcPath = resolve(packagesDspDir, module, filename);
              const content = await readFile(srcPath);

              res.setHeader('Content-Type', filename.endsWith('.wasm')
                ? 'application/wasm'
                : 'application/javascript; charset=utf-8');
              res.setHeader('Content-Length', content.length);
              res.setHeader('Cache-Control', 'no-cache');
              res.end(content);
              return;
            } catch (err) {
              console.error(`[copy-assets] Failed to serve ${filename}:`, err);
            }
          }
        }

        // Handle audio worklets (/audio-worklets/*.js)
        if (req.url?.startsWith('/audio-worklets/')) {
          const filename = req.url.slice(16); // Remove '/audio-worklets/'
          try {
            const srcPath = resolve(publicWorkletsDir, filename);
            const content = await readFile(srcPath);

            res.setHeader('Content-Type', 'application/javascript; charset=utf-8');
            res.setHeader('Content-Length', content.length);
            res.setHeader('Cache-Control', 'no-cache');
            res.end(content);
            return;
          } catch (err) {
            console.error(`[copy-assets] Failed to serve ${filename}:`, err);
          }
        }

        // Handle ziggy.js
        if (req.url === '/ziggy.js') {
          try {
            const content = await readFile(ziggyJsPath, 'utf-8');

            res.setHeader('Content-Type', 'application/javascript; charset=utf-8');
            res.setHeader('Content-Length', Buffer.byteLength(content));
            res.setHeader('Cache-Control', 'no-cache');
            res.end(content);
            return;
          } catch (err) {
            console.error('[copy-assets] Failed to serve ziggy.js:', err);
          }
        }

        next();
      });
    },

    // In production, copy files to build output
    async closeBundle() {
      await validateDspAssets();

      const outDir = resolve(electronDir, 'dist/renderer');
      const dspOutDir = resolve(outDir, 'dsp');
      const workletsOutDir = resolve(outDir, 'audio-worklets');

      await mkdir(dspOutDir, { recursive: true });
      await mkdir(workletsOutDir, { recursive: true });

      // Required DSP assets must arrive unchanged in the renderer output.
      for (const { module, filename } of DSP_ASSETS) {
        const source = resolve(packagesDspDir, module, filename);
        const destination = resolve(dspOutDir, filename);
        try {
          await copyFile(source, destination);
          const [sourceBytes, destinationBytes] = await Promise.all([
            readFile(source), readFile(destination),
          ]);
          if (!sourceBytes.equals(destinationBytes)) {
            throw new Error('Destination bytes differ from source');
          }
        } catch (cause) {
          throw new Error(`Failed to copy required DSP asset ${filename}: ${cause}`, { cause });
        }
        console.log(`✓ Copied ${filename}`);
      }

      // Copy audio worklets from public/audio-worklets
      const { readdirSync } = await import('node:fs');
      const workletFiles = readdirSync(publicWorkletsDir).filter((f: string) =>
        f.endsWith('.js')
      );

      for (const file of workletFiles) {
        const src = resolve(publicWorkletsDir, file);
        const dst = resolve(workletsOutDir, file);
        await copyFile(src, dst);
        console.log(`✓ Copied audio-worklet/${file}`);
      }

      // Copy ziggy.js
      try {
        const ziggyDst = resolve(outDir, 'ziggy.js');
        await copyFile(ziggyJsPath, ziggyDst);
        console.log('✓ Copied ziggy.js');
      } catch (err) {
        console.warn('  ⚠ Skipping ziggy.js (not found)');
      }

      console.log('\n✓ Electron assets copied successfully');
    },
  };
}