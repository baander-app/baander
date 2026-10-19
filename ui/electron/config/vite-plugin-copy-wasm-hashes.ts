import { resolve, dirname } from 'node:path';
import { readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import type { Plugin } from 'vite';

const electronDir = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export function writeWasmHashes(): Plugin {
  return {
    name: 'write-wasm-hashes',
    async writeBundle() {
      const modules: string[] = JSON.parse(await readFile(resolve(electronDir, 'src/shared/wasm-modules.json'), 'utf8'));
      const hashes: Record<string, string> = {};
      for (const module of modules) {
        const bytes = await readFile(resolve(electronDir, '../../packages/dsp', module, `${module}.wasm`));
        hashes[module] = createHash('sha256').update(bytes).digest('hex');
      }
      await writeFile(resolve(electronDir, 'dist/main/wasm-hashes.json'), JSON.stringify(hashes, null, 2));
    },
  };
}
