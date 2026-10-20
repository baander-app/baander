import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmod, copyFile, mkdir, mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test, type TestContext } from 'node:test';
import { fileURLToPath } from 'node:url';

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const tsx = fileURLToPath(import.meta.resolve('tsx/cli'));
const modules = ['fft2048', 'spectral_features', 'dynamics_meter', 'loudness_r128'];
const destinations = ['public/dsp', 'ui/electron/public/dsp'];

async function fixture(t: TestContext) {
  const root = await mkdtemp(join(tmpdir(), 'baander-dsp-regeneration-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  await mkdir(join(root, 'scripts'));
  await copyFile(join(repository, 'scripts/build-wasm.ts'), join(root, 'scripts/build-wasm.ts'));
  await writeFile(join(root, 'package.json'), '{"type":"module"}');
  await mkdir(join(root, 'bin'));
  for (const directory of [...destinations, 'public/audio-worklets']) {
    await mkdir(join(root, directory), { recursive: true });
  }
  // Dormant packages exist so accidentally building them cannot mask another failure.
  for (const name of [...modules, 'partitioned_convolver', 'resampler_hq', 'baander-aac']) {
    await mkdir(join(root, 'packages/dsp', name), { recursive: true });
    await writeFile(join(root, 'packages/dsp', name, `${name}.wasm`), `tracked ${name}`);
    for (const destination of destinations) {
      await writeFile(join(root, destination, `${name}.wasm`), `tracked ${name}`);
      await chmod(join(root, destination, `${name}.wasm`), 0o644);
    }
  }
  await writeFile(join(root, 'packages/dsp/dynamics_meter/dynamics_meter.js'), 'dynamics loader');
  await writeFile(join(root, 'packages/dsp/fft2048/wasm-spectrum.js'), 'FFT worklet');
  const compiler = join(root, 'bin/selected-emcc');
  await writeFile(compiler, `#!${process.execPath}\nconsole.log('emcc (Emscripten gcc/clang-like replacement) ' + (process.env.COMPILER_VERSION || '6.0.3') + ' (build)');\n`, { mode: 0o755 });
  await copyFile(compiler, join(root, 'bin/emcc'));
  await writeFile(join(root, 'bin/make'), `#!${process.execPath}
import fs from 'node:fs';
import path from 'node:path';
const args = process.argv.slice(2);
const name = path.basename(process.cwd());
fs.appendFileSync(process.env.BUILD_LOG, JSON.stringify({ name, args }) + '\\n');
if (name === process.env.FAIL_MODULE) process.exit(23);
const output = args.find(arg => arg.startsWith('WASM='))?.slice(5) || path.join(process.cwd(), name + '.wasm');
// A tracked target newer than its source must not suppress regeneration.
if (process.env.FRESH_TARGETS && !args.includes('-B') && fs.existsSync(output)) process.exit(0);
if (name !== process.env.OMIT_MODULE) {
  fs.writeFileSync(output, 'generated ' + name);
  fs.chmodSync(output, 0o755);
}
`, { mode: 0o755 });
  const run = (extra: NodeJS.ProcessEnv = {}) => spawnSync(process.execPath, [tsx, join(root, 'scripts/build-wasm.ts')], {
    cwd: join(root, 'ui/electron'), encoding: 'utf8',
    env: { ...process.env, PATH: `${join(root, 'bin')}:/usr/bin:/bin`, EMCC: compiler, BUILD_LOG: join(root, 'build.log'), ...extra },
  });
  const builds = async () => (await readFile(join(root, 'build.log'), 'utf8')).trim().split('\n').map(line => JSON.parse(line) as { name: string; args: string[] });
  const unchanged = async () => {
    for (const name of modules) {
      for (const directory of [`packages/dsp/${name}`, ...destinations]) {
        assert.equal(await readFile(join(root, directory, `${name}.wasm`), 'utf8'), `tracked ${name}`);
      }
    }
  };
  return { root, compiler, run, builds, unchanged };
}

for (const version of ['5.0.0', '6.0.30']) {
  test(`rejects Emscripten ${version} before publishing or building`, async t => {
    const f = await fixture(t);
    const result = f.run({ COMPILER_VERSION: version });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /6\.0\.3/);
    await f.unchanged();
    await assert.rejects(readFile(join(f.root, 'build.log')), { code: 'ENOENT' });
  });
}

test('rejects a missing selected compiler before publishing or building', async t => {
  const f = await fixture(t);
  const result = f.run({ EMCC: join(f.root, 'missing-emcc') });
  assert.notEqual(result.status, 0);
  await f.unchanged();
  await assert.rejects(readFile(join(f.root, 'build.log')), { code: 'ENOENT' });
});

test('forces the four analysis builds and publishes identical binaries and required wrappers', async t => {
  const f = await fixture(t);
  const result = f.run({ FRESH_TARGETS: '1' });
  assert.equal(result.status, 0, result.stderr);
  const builds = await f.builds();
  assert.deepEqual(builds.map(build => build.name), modules);
  for (const { name, args } of builds) {
    assert.ok(args.includes('-B'));
    assert.ok(args.includes(`EMCC=${f.compiler}`));
    assert.ok(args.some(arg => arg.startsWith('WASM=') && !arg.startsWith(`WASM=${f.root}/packages/`)));
    for (const directory of [`packages/dsp/${name}`, ...destinations]) {
      assert.equal(await readFile(join(f.root, directory, `${name}.wasm`), 'utf8'), `generated ${name}`);
    }
  }
  for (const destination of destinations) {
    for (const name of modules) {
      assert.equal((await stat(join(f.root, destination, `${name}.wasm`))).mode & 0o777, 0o644);
    }
    assert.equal(await readFile(join(f.root, destination, 'dynamics_meter.js'), 'utf8'), 'dynamics loader');
  }
  assert.equal(await readFile(join(f.root, 'public/audio-worklets/wasm-spectrum.js'), 'utf8'), 'FFT worklet');
});

test('does not publish any binary when a later module build fails', async t => {
  const f = await fixture(t);
  const result = f.run({ FAIL_MODULE: 'loudness_r128' });
  assert.notEqual(result.status, 0);
  await f.unchanged();
});

test('rejects a missing required generated output before publication', async t => {
  const f = await fixture(t);
  const result = f.run({ OMIT_MODULE: 'dynamics_meter' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /dynamics_meter\.wasm/);
  await f.unchanged();
});

test('reports required publication errors as failure', async t => {
  const f = await fixture(t);
  const blocked = join(f.root, 'ui/electron/public/dsp/loudness_r128.wasm');
  await rm(blocked);
  await mkdir(blocked);
  const result = f.run();
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /loudness_r128\.wasm/);
});

test('Electron regeneration command resolves the repository driver', async () => {
  const pkg = JSON.parse(await readFile(join(repository, 'ui/electron/package.json'), 'utf8'));
  const [runner, script] = pkg.scripts['build:wasm'].split(' ');
  assert.equal(runner, 'tsx');
  assert.equal(resolve(repository, 'ui/electron', script), join(repository, 'scripts/build-wasm.ts'));
});
