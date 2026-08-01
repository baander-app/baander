import * as crypto from 'node:crypto';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const wasmModules = require('../electron/src/shared/wasm-modules.json');

const WASM_DIR = path.join(process.cwd(), 'packages/dsp');
const OUTPUT_FILE = path.join(process.cwd(), 'electron/src/main/wasm-hashes.json');

interface WasmHashes {
  [module: string]: string;
}

async function generateHashes(): Promise<WasmHashes> {
  const hashes: WasmHashes = {};

  for (const module of wasmModules) {
    const wasmPath = path.join(WASM_DIR, module, `${module}.wasm`);

    try {
      const wasmBuffer = fs.readFileSync(wasmPath);
      const hash = crypto.createHash('sha256').update(wasmBuffer).digest('hex');
      hashes[module] = hash;
      console.log(`✓ Generated hash for ${module}.wasm: ${hash}`);
    } catch (error) {
      console.error(`✗ Failed to read ${module}.wasm:`, error);
      throw error;
    }
  }

  return hashes;
}

async function main(): Promise<void> {
  console.log('Generating SHA-256 hashes for WASM modules...');

  const hashes = await generateHashes();

  // Write hashes to JSON file
  fs.writeFileSync(OUTPUT_FILE, JSON.stringify(hashes, null, 2));
  console.log(`\n✓ Hashes written to ${OUTPUT_FILE}`);
}

main().catch(console.error);