import assert from 'node:assert/strict';
import * as fs from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { mock, test, type TestContext } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import type { Plugin, ViteDevServer } from 'vite';

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const modules = ['spectral_features', 'dynamics_meter', 'fft2048', 'loudness_r128', 'partitioned_convolver', 'resampler_hq'];
const loaders = ['spectral_features', 'dynamics_meter', 'loudness_r128', 'partitioned_convolver', 'resampler_hq'];
const filenames = [...modules.map(name => `${name}.wasm`), ...loaders.map(name => `${name}.js`)];
let corruptCopy = false;

const compile = mock.fn(() => { throw new Error('Asset consumption invoked a compiler or build'); });
mock.module('node:child_process', {
  namedExports: { execSync: compile, spawnSync: compile },
});
mock.module('node:fs/promises', {
  namedExports: {
    ...fs,
    async copyFile(source: string, destination: string) {
      await fs.copyFile(source, destination);
      if (corruptCopy && destination.endsWith('spectral_features.wasm')) {
        await fs.writeFile(destination, 'altered copy');
      }
    },
  },
});

async function fixture(t: TestContext) {
  const root = await fs.mkdtemp(join(tmpdir(), 'baander-dsp-assets-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const configDir = join(root, 'ui/electron/config');
  await fs.mkdir(configDir, { recursive: true });
  await fs.writeFile(join(root, 'package.json'), '{"type":"module"}');
  await fs.copyFile(join(repository, 'ui/electron/config/vite-plugin-copy-assets.ts'), join(configDir, 'vite-plugin-copy-assets.ts'));
  await fs.mkdir(join(root, 'ui/electron/src/shared'), { recursive: true });
  await fs.copyFile(join(repository, 'ui/electron/src/shared/wasm-modules.json'), join(root, 'ui/electron/src/shared/wasm-modules.json'));
  for (const filename of filenames) {
    const module = filename.replace(/\.(wasm|js)$/, '');
    const directory = join(root, 'packages/dsp', module);
    await fs.mkdir(directory, { recursive: true });
    await fs.writeFile(join(directory, filename), `source ${filename}`);
  }
  await fs.mkdir(join(root, 'public/audio-worklets'), { recursive: true });
  await fs.writeFile(join(root, 'public/audio-worklets/processor.js'), 'worklet');
  await fs.mkdir(join(root, 'resources/app'), { recursive: true });
  await fs.writeFile(join(root, 'resources/app/ziggy.js'), 'ziggy');
  const { copyAssets } = await import(pathToFileURL(join(configDir, 'vite-plugin-copy-assets.ts')).href);
  const plugin: Plugin = copyAssets();
  const output = join(root, 'ui/electron/dist/renderer');
  const source = (filename: string) => join(root, 'packages/dsp', filename.replace(/\.(wasm|js)$/, ''), filename);
  const build = async () => {
    const hook = plugin.closeBundle;
    assert.ok(typeof hook === 'function');
    await hook.call({} as never);
  };
  const dev = async () => {
    let middleware: Parameters<ViteDevServer['middlewares']['use']>[0] | undefined;
    const server = { middlewares: { use(handler: typeof middleware) { middleware = handler; } } };
    const hook = plugin.configureServer;
    assert.ok(typeof hook === 'function');
    await hook.call({} as never, server as unknown as ViteDevServer);
    return middleware as unknown as (request: { url: string }, response: object, next: () => void) => Promise<void>;
  };
  return { output, source, build, dev };
}

test('complete checked-in DSP assets serve and package without a compiler or standalone FFT loader', async t => {
  const f = await fixture(t);
  const middleware = await f.dev();
  for (const filename of filenames) {
    let served: Buffer | undefined;
    const headers: Record<string, string | number> = {};
    await middleware({ url: `/dsp/${filename}` }, {
      setHeader(name: string, value: string | number) { headers[name] = value; },
      end(content: Buffer) { served = content; },
    }, () => assert.fail(`Did not serve ${filename}`));
    assert.deepEqual(served, await fs.readFile(f.source(filename)));
    assert.equal(headers['Content-Type'], filename.endsWith('.wasm') ? 'application/wasm' : 'application/javascript; charset=utf-8');
  }
  await f.build();
  assert.equal(compile.mock.callCount(), 0);
  for (const filename of filenames) {
    assert.deepEqual(await fs.readFile(join(f.output, 'dsp', filename)), await fs.readFile(f.source(filename)));
  }
});

for (const filename of filenames) {
  test(`missing required ${filename} rejects dev and packaging with restoration guidance`, async t => {
    const f = await fixture(t);
    await fs.rm(f.source(filename));
    for (const consume of [f.dev, f.build]) {
      await assert.rejects(consume, error => {
        assert.ok(error instanceof Error);
        assert.ok(error.message.includes(filename), error.message);
        assert.match(error.message, /restor/i);
        assert.match(error.message, /build:wasm/);
        return true;
      });
    }
  });
}

test('required DSP copy rejection fails packaging', async t => {
  const f = await fixture(t);
  await fs.mkdir(join(f.output, 'dsp/spectral_features.wasm'), { recursive: true });
  await assert.rejects(f.build, /spectral_features\.wasm/);
});

test('altered DSP destination bytes fail packaging', async t => {
  const f = await fixture(t);
  corruptCopy = true;
  try {
    await assert.rejects(f.build, /spectral_features\.wasm.*(match|differ)|(?:match|differ).*spectral_features\.wasm/i);
  } finally {
    corruptCopy = false;
  }
});
