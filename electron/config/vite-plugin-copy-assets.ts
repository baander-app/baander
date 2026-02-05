import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdir, copyFile, readFile, access, constants } from 'node:fs/promises';
import { execSync } from 'node:child_process';
import type { Plugin } from 'vite';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(__dirname, '../..');

const modules = [
  'spectral_features',
  'dynamics_meter',
  'fft2048',
  'loudness_r128',
  'partitioned_convolver',
  'resampler_hq',
] as const;

/**
 * Check if a file exists
 */
async function fileExists(path: string): Promise<boolean> {
  try {
    await access(path, constants.F_OK);
    return true;
  } catch {
    return false;
  }
}

/**
 * Build a single WASM module using make
 */
async function buildModule(module: string): Promise<void> {
  const moduleDir = resolve(rootDir, 'packages/dsp', module);

  try {
    execSync('make', { cwd: moduleDir, stdio: 'pipe' });
    console.log(`[copy-assets] Built ${module}`);
  } catch (error) {
    throw new Error(`Failed to build ${module}: ${error}`);
  }
}

/**
 * Ensure all WASM modules are built
 */
async function ensureWasmModulesBuilt(): Promise<void> {
  const needsBuild: string[] = [];

  for (const module of modules) {
    const wasmPath = resolve(rootDir, 'packages/dsp', module, `${module}.wasm`);
    if (!(await fileExists(wasmPath))) {
      needsBuild.push(module);
    }
  }

  if (needsBuild.length === 0) {
    return;
  }

  console.log(`[copy-assets] Building missing WASM modules: ${needsBuild.join(', ')}`);

  // Check if emcc is available
  try {
    execSync('emcc --version', { stdio: 'pipe' });
  } catch {
    throw new Error('Emscripten (emcc) is not installed. Run: brew install emscripten');
  }

  for (const module of needsBuild) {
    await buildModule(module);
  }

  console.log('[copy-assets] All WASM modules built');
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
      // Ensure WASM modules are built before starting server
      await ensureWasmModulesBuilt();

      // Add middleware to serve files
      server.middlewares.use(async (req, res, next) => {
        // Handle DSP files (/dsp/*.wasm and /dsp/*.js)
        if (req.url?.startsWith('/dsp/')) {
          const filename = req.url.slice(5); // Remove '/dsp/'
          const module = modules.find(m => filename === `${m}.wasm` || filename === `${m}.js`);

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
      // Ensure WASM modules are built before copying
      await ensureWasmModulesBuilt();

      const outDir = resolve(rootDir, 'electron/dist-electron/renderer');
      const dspOutDir = resolve(outDir, 'dsp');
      const workletsOutDir = resolve(outDir, 'audio-worklets');

      await mkdir(dspOutDir, { recursive: true });
      await mkdir(workletsOutDir, { recursive: true });

      // Copy WASM and JS files from packages/dsp
      for (const module of modules) {
        const moduleDir = resolve(packagesDspDir, module);

        try {
          const wasmSrc = resolve(moduleDir, `${module}.wasm`);
          const wasmDst = resolve(dspOutDir, `${module}.wasm`);
          await copyFile(wasmSrc, wasmDst);
          console.log(`✓ Copied ${module}.wasm`);
        } catch (err) {
          console.warn(`  ⚠ Skipping ${module}.wasm (not found)`);
        }

        try {
          const jsSrc = resolve(moduleDir, `${module}.js`);
          const jsDst = resolve(dspOutDir, `${module}.js`);
          await copyFile(jsSrc, jsDst);
          console.log(`✓ Copied ${module}.js`);
        } catch (err) {
          // Some modules might not have a .js file, that's okay
        }
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