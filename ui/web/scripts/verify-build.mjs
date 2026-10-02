import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, relative, isAbsolute } from 'node:path';

const output = fileURLToPath(new URL('../../../public/', import.meta.url));
const manifest = JSON.parse(await readFile(resolve(output, '.vite/manifest.json'), 'utf8'));
const entrypoints = JSON.parse(await readFile(resolve(output, '.vite/entrypoints.json'), 'utf8'));
assert.equal(entrypoints.viteServer, null, 'Expected production Symfony entrypoints');
assert.ok(manifest['src/main.tsx']?.isEntry, 'Missing application entry');
assert.ok(entrypoints.entryPoints?.app, 'Missing Symfony app entrypoint');

for (const entry of Object.values(manifest)) {
  for (const asset of [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])]) {
    assert.equal(typeof asset, 'string', 'Invalid manifest asset');
    const path = resolve(output, asset);
    const within = relative(output, path);
    assert.ok(!within.startsWith('..') && !isAbsolute(within), 'Asset outside build output');
    assert.ok((await stat(path)).size > 0, `Missing or empty asset: ${asset}`);
  }
}
assert.ok((await stat(resolve(output, 'auth-stream-worker.js'))).size > 0, 'Missing auth worker');
console.log('Verified Symfony/Vite manifests, application assets, and auth worker');
