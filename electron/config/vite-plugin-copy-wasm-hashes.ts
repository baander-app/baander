import { resolve } from 'node:path';
import { copyFile } from 'node:fs/promises';
import type { Plugin } from 'vite';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const rootDir = resolve(__dirname, '../..');

/**
 * Vite plugin to copy wasm-hashes.json to the main process output
 */
export function copyWasmHashes(): Plugin {
  return {
    name: 'copy-wasm-hashes',
    writeBundle() {
      const wasmHashesPath = resolve(rootDir, 'electron/src/main/wasm-hashes.json');
      const outDir = resolve(rootDir, 'electron/dist-electron/main');

      return copyFile(wasmHashesPath, resolve(outDir, 'wasm-hashes.json'))
        .then(() => console.log('✓ Copied wasm-hashes.json to main process output'))
        .catch((err) => console.warn('  ⚠ Skipping wasm-hashes.json (not found)', err));
    },
  };
}