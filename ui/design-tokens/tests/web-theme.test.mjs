import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync, mkdtempSync, cpSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { THEMES, VALID_MOODS, VALID_ACCENTS } from '../build/web/theme.ts';

const root = fileURLToPath(new URL('..', import.meta.url));
const css = readFileSync(join(root, 'build/web/theme.css'), 'utf8');

// This deliberately measures the emitted values independently of the generator.
function contrast(first, second) {
  function luminance(hex) {
    const rgb = [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255);
    const linear = rgb.map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
  }
  const a = luminance(first);
  const b = luminance(second);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function assertContrast(colors, foreground, background, minimum) {
  const actual = contrast(colors[foreground], colors[background]);
  assert.ok(actual >= minimum, `${foreground} on ${background}: ${actual.toFixed(3)} < ${minimum}`);
}

for (const mood of VALID_MOODS) {
  for (const accent of VALID_ACCENTS) {
    const theme = THEMES[mood][accent];
    test(`${mood}/${accent} meets text, state and indicator contrast`, () => {
      const colors = theme.colors;
      for (const surface of ['background', 'card', 'popover', 'sidebar', 'secondary', 'muted', 'highlight']) {
        for (const text of ['foreground', 'mutedForeground', 'accentText', 'destructive', 'success', 'warning', 'info']) {
          assertContrast(colors, text, surface, 4.5);
        }
        for (const indicator of ['ring', 'inputBoundary', 'primary', 'primaryHover', 'primaryPressed']) {
          assertContrast(colors, indicator, surface, 3);
        }
      }
      for (const fill of ['primary', 'primaryHover', 'primaryPressed']) {
        assertContrast(colors, 'primaryForeground', fill, 4.5);
      }
      for (const fill of ['destructive', 'destructiveHover', 'destructivePressed']) {
        assertContrast(colors, 'destructiveForeground', fill, 4.5);
      }
      for (const status of ['success', 'warning', 'info']) {
        assertContrast(colors, `${status}Foreground`, status, 4.5);
      }
      for (const [foreground, surface] of [['cardForeground', 'card'], ['popoverForeground', 'popover'], ['sidebarForeground', 'sidebar'], ['secondaryForeground', 'secondary'], ['highlightForeground', 'highlight']]) {
        assertContrast(colors, foreground, surface, 4.5);
      }
    });
    test(`${mood}/${accent} CSS and runtime colors agree`, () => {
      const selector = `:root[data-theme="${mood}"][data-accent="${accent}"]`;
      const block = css.slice(css.indexOf(selector)).split('{')[1].split('}')[0];
      const declarations = Object.fromEntries([...block.matchAll(/--color-([\w-]+): ([^;]+);/g)].map(match => [match[1], match[2]]));
      assert.equal(Object.keys(declarations).length, Object.keys(theme.colors).length);
      for (const [key, value] of Object.entries(theme.colors)) {
        const name = key.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/([a-z])(\d)/g, '$1-$2').toLowerCase();
        assert.equal(declarations[name], value, name);
      }
    });
  }
}

test('CSS defaults to dark/violet and supports either missing root attribute', () => {
  const defaults = css.split(':root {')[1].split('}')[0];
  assert.ok(defaults.includes(`--color-primary: ${THEMES.dark.violet.colors.primary};`));
  assert.ok(defaults.includes(`--radius-md: ${THEMES.dark.violet.radii.md};`));
  for (const mood of VALID_MOODS) assert.ok(css.includes(`:root[data-theme="${mood}"]:not([data-accent])`));
  for (const accent of VALID_ACCENTS) assert.ok(css.includes(`:root:not([data-theme])[data-accent="${accent}"]`));
  assert.ok(!css.includes('--color-accent:'));
});

test('generation is reproducible and stale checking never writes output', () => {
  const directory = mkdtempSync(join(tmpdir(), 'baander-tokens-'));
  try {
    for (const entry of ['tokens', 'build', 'build.mjs', 'web.mjs']) cpSync(join(root, entry), join(directory, entry), { recursive: true });
    const current = spawnSync(process.execPath, ['build.mjs', '--check'], { cwd: directory, encoding: 'utf8' });
    assert.equal(current.status, 0, current.stderr);
    const path = join(directory, 'build/web/theme.css');
    writeFileSync(path, 'stale output');
    const stale = spawnSync(process.execPath, ['build.mjs', '--check'], { cwd: directory, encoding: 'utf8' });
    assert.equal(stale.status, 1);
    assert.match(stale.stderr, /Stale generated output: web\/theme.css/);
    assert.equal(readFileSync(path, 'utf8'), 'stale output');
    const rebuild = spawnSync(process.execPath, ['build.mjs'], { cwd: directory, encoding: 'utf8' });
    assert.equal(rebuild.status, 0, rebuild.stderr);
    assert.equal(readFileSync(path, 'utf8'), css);
    for (const entry of ['theme.ts', 'theme.types.ts']) {
      assert.equal(readFileSync(join(directory, 'build/rn', entry), 'utf8'), readFileSync(join(root, 'build/rn', entry), 'utf8'));
    }
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
