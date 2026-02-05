#!/usr/bin/env tsx

/**
 * Build script for WASM modules
 * Compiles all C++ DSP modules to WASM using Emscripten
 */

import { execSync } from 'node:child_process';
import { copyFile, mkdir } from 'node:fs/promises';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, '..');
const packagesDspDir = join(rootDir, 'packages', 'dsp');
const publicDspDir = join(rootDir, 'public', 'dsp');

const modules = [
  'spectral_features',
  'dynamics_meter',
  'fft2048',
  'loudness_r128',
  'partitioned_convolver',
  'resampler_hq',
];

/**
 * Check if emcc (Emscripten) is available
 */
function checkEmcc(): boolean {
  try {
    execSync('emcc --version', { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

/**
 * Build a single WASM module
 */
async function buildModule(moduleName: string): Promise<void> {
  const moduleDir = join(packagesDspDir, moduleName);
  const makefile = join(moduleDir, 'Makefile');

  console.log(`Building ${moduleName}...`);

  try {
    // Use make to build the module
    execSync('make', { cwd: moduleDir, stdio: 'inherit' });
    console.log(`✓ Built ${moduleName}`);
  } catch (error) {
    console.error(`✗ Failed to build ${moduleName}:`, error);
    throw error;
  }
}

/**
 * Copy WASM and JS files to destination directories
 */
async function copyModuleFiles(moduleName: string): Promise<void> {
  const moduleDir = join(packagesDspDir, moduleName);

  // Files to copy
  const filesToCopy = [
    `${moduleName}.wasm`,
    `${moduleName}.js`,
  ];

  for (const file of filesToCopy) {
    const src = join(moduleDir, file);
    const dst = join(publicDspDir, file);

    try {
      await copyFile(src, dst);
      console.log(`  Copied ${file}`);
    } catch (error) {
      // Some modules might not have a .js file
      if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
        console.log(`  Skipped ${file} (not found)`);
      } else {
        throw error;
      }
    }
  }
}

/**
 * Main build function
 */
async function main(): Promise<void> {
  console.log('=== WASM Module Build Script ===\n');

  // Check if Emscripten is installed
  if (!checkEmcc()) {
    console.error('Error: Emscripten (emcc) is not installed or not in PATH');
    console.error('Please install Emscripten: https://emscripten.org/docs/getting_started/downloads.html');
    process.exit(1);
  }

  console.log('Found Emscripten compiler\n');

  // Ensure output directory exists
  await mkdir(publicDspDir, { recursive: true });

  // Build all modules
  for (const module of modules) {
    await buildModule(module);
    await copyModuleFiles(module);
    console.log('');
  }

  console.log('=== Build Complete ===');
  console.log(`WASM modules copied to: ${publicDspDir}`);
  console.log('\nNote: Electron dev mode uses the Vite plugin to serve files directly from packages/dsp/');
  console.log('      Electron production mode copies files during build via vite-plugin-copy-assets');
}

main().catch((error) => {
  console.error('Build failed:', error);
  process.exit(1);
});
