import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const desktop = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const page = await readFile(resolve(desktop, 'src/renderer/config/index.html'), 'utf8');
const theme = await readFile(resolve(desktop, '../design-tokens/build/web/theme.css'), 'utf8');
const styles = page.match(/<style>([\s\S]*?)<\/style>/)?.[1] ?? '';
const script = page.match(/<script type="module">([\s\S]*?)<\/script>/)?.[1] ?? '';

function tokenValues(name: string): string[] {
  return [...theme.matchAll(new RegExp(`${name}:\\s*([^;]+);`, 'g'))].map(match => match[1].trim());
}

test('the configuration window loads Geist and Geist Mono with the shared type tokens', () => {
  const imports = [...script.matchAll(/^\s*import\s+['"]([^'"]+)['"]/gm)].map(match => match[1]);
  assert.ok(imports.includes('@fontsource-variable/geist'), 'imports Geist');
  assert.ok(imports.includes('@fontsource-variable/geist-mono'), 'imports Geist Mono');
  assert.ok(
    imports.some(specifier => resolve(desktop, 'src/renderer/config', specifier) === resolve(desktop, '../design-tokens/build/web/theme.css')),
    'imports the generated design tokens',
  );
});

test('the configuration window styles name the Geist family through the font tokens', () => {
  assert.match(tokenValues('--font-ui')[0], /^"Geist Variable",/);
  assert.match(tokenValues('--font-mono')[0], /^"Geist Mono Variable",/);
  assert.match(styles, /body\s*\{[^}]*font-family:\s*var\(--font-ui\)/);
  assert.match(styles, /font-family:\s*var\(--font-mono\)/);
  const families = [...styles.matchAll(/font-family:\s*([^;]+);/g)].map(match => match[1].trim());
  assert.deepEqual(families.filter(family => !/^var\(--font-(ui|mono)\)$/.test(family)), [], 'every family reads a font token');
  assert.doesNotMatch(page, /system-ui/);
});

test('the configuration window sets no text below 12px', () => {
  const sizes = [...styles.matchAll(/font-size:\s*([^;]+);/g)].map(match => match[1].trim());
  assert.ok(sizes.length > 0);
  for (const size of sizes) {
    const token = size.match(/^var\((--text-[a-z-]+-size)\)$/)?.[1];
    assert.ok(token, `${size} reads a type role size`);
    assert.notEqual(token, '--text-dense-size', 'the dense size is for admin tables only');
    const values = tokenValues(token);
    assert.ok(values.length > 0, `${token} is defined`);
    for (const value of values) assert.ok(Number.parseFloat(value) >= 12, `${token} is ${value}`);
  }
});
