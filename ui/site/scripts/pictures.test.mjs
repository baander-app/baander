// The pictures of the application the site publishes: each is a pair the capture script took and
// recorded, and each is shown by the homepage or a feature page.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const site = join(import.meta.dirname, '..');
const directory = join(site, 'assets/screenshots');
const files = readdirSync(directory);
const pictures = files.filter(file => file.endsWith('.webp') && !file.endsWith('-light.webp')).map(file => file.slice(0, -'.webp'.length));
const records = JSON.parse(readFileSync(join(site, 'features/captures.json'), 'utf8'));
const sources = ['features', 'templates'].flatMap(folder => readdirSync(join(site, folder))
  .filter(file => file.endsWith('.php'))
  .map(file => readFileSync(join(site, folder, file), 'utf8'))).join('\n');
const sha256 = file => createHash('sha256').update(readFileSync(join(directory, file))).digest('hex');

test('the directory holds picture pairs and nothing else', () => {
  assert.ok(pictures.length > 0);
  assert.deepEqual(files.toSorted(), pictures.flatMap(name => [`${name}-light.webp`, `${name}.webp`]).toSorted());
});

test('every picture is one the capture script recorded, and every record has its picture', () => {
  assert.deepEqual(pictures.toSorted(), Object.keys(records).toSorted());
});

test('both files of every picture are the recorded ones', () => {
  const changed = pictures.flatMap(name => [
    ...(sha256(`${name}.webp`) === records[name]?.sha256?.dark ? [] : [`${name}.webp`]),
    ...(sha256(`${name}-light.webp`) === records[name]?.sha256?.light ? [] : [`${name}-light.webp`]),
  ]);
  assert.deepEqual(changed, []);
});

test('the homepage or a feature page shows every picture', () => {
  const shown = name => new RegExp(`'(?:picture|name)' => '${name}'`).test(sources);
  assert.deepEqual(pictures.filter(name => !shown(name)), []);
});
