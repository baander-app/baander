#!/usr/bin/env tsx

import { execFileSync } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const packagesDspDir = join(rootDir, 'packages', 'dsp');
const publicDspDirs = [join(rootDir, 'public/dsp'), join(rootDir, 'ui/electron/public/dsp')];
const modules = ['fft2048', 'spectral_features', 'dynamics_meter', 'loudness_r128', 'partitioned_convolver'];

async function main(): Promise<void> {
  const compiler = process.env.EMCC || 'emcc';
  const version = execFileSync(compiler, ['--version'], { encoding: 'utf8' }).split('\n')[0];
  if (!version.includes(' 6.0.3 (')) {
    throw new Error(`DSP regeneration requires Emscripten 6.0.3; found: ${version}`);
  }

  const output = await mkdtemp(join(tmpdir(), 'baander-dsp-build-'));
  try {
    for (const moduleName of modules) {
      console.log(`Building ${moduleName}...`);
      execFileSync('make', ['-B', `EMCC=${compiler}`, `WASM=${join(output, `${moduleName}.wasm`)}`], {
        cwd: join(packagesDspDir, moduleName), stdio: 'inherit',
      });
    }

    const artifacts = modules.map(moduleName => ({
      source: join(output, `${moduleName}.wasm`),
      destinations: [
        join(packagesDspDir, moduleName, `${moduleName}.wasm`),
        ...publicDspDirs.map(directory => join(directory, `${moduleName}.wasm`)),
      ],
    }));
    artifacts.push({
      source: join(packagesDspDir, 'dynamics_meter/dynamics_meter.js'),
      destinations: publicDspDirs.map(directory => join(directory, 'dynamics_meter.js')),
    }, {
      source: join(packagesDspDir, 'fft2048/wasm-spectrum.js'),
      destinations: [join(rootDir, 'public/audio-worklets/wasm-spectrum.js')],
    });

    // Require every generated output and wrapper before publishing the build.
    const contents = await Promise.all(artifacts.map(artifact => readFile(artifact.source)));
    for (const [index, artifact] of artifacts.entries()) {
      for (const destination of artifact.destinations) {
        await mkdir(dirname(destination), { recursive: true });
        await writeFile(destination, contents[index]);
        if (!(await readFile(destination)).equals(contents[index])) {
          throw new Error(`DSP artifact copy differs from its source: ${destination}`);
        }
      }
    }
    console.log('DSP artifacts regenerated and synchronized.');
  } finally {
    await rm(output, { recursive: true, force: true });
  }
}

main().catch(error => {
  console.error('DSP regeneration failed:', error);
  process.exitCode = 1;
});
