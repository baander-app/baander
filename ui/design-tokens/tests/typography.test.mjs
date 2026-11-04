import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync, mkdtempSync, cpSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { THEMES } from '../build/web/theme.ts';

const root = fileURLToPath(new URL('..', import.meta.url));
const css = readFileSync(join(root, 'build/web/theme.css'), 'utf8');

function declarationsOf(block) {
  return Object.fromEntries([...block.matchAll(/(--[\w-]+): ([^;]+);/g)].map(match => [match[1], match[2]]));
}

const rootTokens = declarationsOf(css.split(':root {')[1].split('}')[0]);
const mobileBlocks = css.split('@media (max-width: 767px) {').slice(1);
const mobileTokens = declarationsOf(mobileBlocks.map(block => block.split(/\n}\n/)[0]).join('\n'));

const families = stack => stack.split(',').map(family => family.trim().replace(/^"|"$/g, ''));
const notoChain = ['Noto Sans Variable', 'Noto Sans Thai Variable', 'Noto Sans Myanmar Variable'];
const cjk = ['Noto Sans SC Variable', 'Noto Sans TC Variable', 'Noto Sans JP Variable', 'Noto Sans KR Variable'];
const roles = ['hero', 'title-lg', 'title', 'subtitle', 'body', 'body-sm', 'caption', 'label', 'timecode', 'mono-sm'];

test('body text is 15px on desktop and 16px in the single phone-width block', () => {
  assert.equal(mobileBlocks.length, 1);
  assert.equal(rootTokens['--text-body-size'], '15px');
  assert.equal(mobileTokens['--text-body-size'], '16px');
});

test('label is uppercase with its tracking, and hero carries its tracking', () => {
  assert.equal(rootTokens['--text-label-transform'], 'uppercase');
  assert.equal(rootTokens['--text-label-tracking'], '0.12em');
  assert.equal(rootTokens['--text-hero-tracking'], '-0.02em');
});

test('every role declares size, weight, tracking and line-height, with tracking in em', () => {
  for (const role of roles) {
    for (const property of ['size', 'weight', 'tracking', 'line-height']) {
      assert.ok(`--text-${role}-${property}` in rootTokens, `--text-${role}-${property}`);
    }
  }
  const tracking = Object.entries(rootTokens).filter(([name]) => /^--text-.*-tracking$/.test(name));
  assert.equal(tracking.length, roles.length);
  for (const [name, value] of tracking) assert.match(value, /^-?\d+(\.\d+)?em$/, name);
});

test('no role weight is below 400', () => {
  const weights = Object.entries(rootTokens).filter(([name]) => /^--text-.*-weight$/.test(name));
  assert.equal(weights.length, roles.length);
  for (const [name, value] of weights) assert.ok(Number(value) >= 400, `${name}: ${value}`);
});

test('the dense size for admin tables, badges and chips is 11px', () => {
  assert.equal(rootTokens['--text-dense-size'], '11px');
});

test('the UI stack is Geist, then the Noto chain in default order', () => {
  assert.deepEqual(families(rootTokens['--font-ui']), ['Geist Variable', ...notoChain, ...cjk, 'sans-serif']);
  assert.ok(!('--font-sans' in rootTokens));
  assert.ok(!('--font-heading' in rootTokens));
});

test('the mono stack is Geist Mono, Noto Sans Mono, then the Noto chain from Noto Sans onward', () => {
  assert.deepEqual(
    families(rootTokens['--font-mono']),
    ['Geist Mono Variable', 'Noto Sans Mono Variable', ...notoChain, ...cjk, 'monospace'],
  );
});

test('per-language stacks put their own Han family first and keep the rest in default order', () => {
  const variants = {
    ja: ['Noto Sans JP Variable', 'Noto Sans SC Variable', 'Noto Sans TC Variable', 'Noto Sans KR Variable'],
    ko: ['Noto Sans KR Variable', 'Noto Sans SC Variable', 'Noto Sans TC Variable', 'Noto Sans JP Variable'],
    hant: ['Noto Sans TC Variable', 'Noto Sans SC Variable', 'Noto Sans JP Variable', 'Noto Sans KR Variable'],
  };
  for (const [suffix, order] of Object.entries(variants)) {
    assert.deepEqual(families(rootTokens[`--font-ui-${suffix}`]), ['Geist Variable', ...notoChain, ...order, 'sans-serif'], suffix);
    assert.deepEqual(
      families(rootTokens[`--font-mono-${suffix}`]),
      ['Geist Mono Variable', 'Noto Sans Mono Variable', ...notoChain, ...order, 'monospace'],
      suffix,
    );
  }
});

test('the runtime theme exposes the role tokens', () => {
  const text = THEMES.dark.violet.text;
  assert.equal(text.bodySize, '15px');
  assert.equal(text.labelTracking, '0.12em');
  assert.equal(THEMES.dark.violet.typography.ui, rootTokens['--font-ui']);
});

test('the stale check fails after a token edit without regeneration', () => {
  const directory = mkdtempSync(join(tmpdir(), 'baander-tokens-'));
  try {
    for (const entry of ['tokens', 'build', 'build.mjs', 'web.mjs']) cpSync(join(root, entry), join(directory, entry), { recursive: true });
    const current = spawnSync(process.execPath, ['build.mjs', '--check'], { cwd: directory, encoding: 'utf8' });
    assert.equal(current.status, 0, current.stderr);
    const path = join(directory, 'tokens/base/type.json');
    const tokens = JSON.parse(readFileSync(path, 'utf8'));
    tokens.text.bodySize.mobile = '17px';
    writeFileSync(path, JSON.stringify(tokens));
    const stale = spawnSync(process.execPath, ['build.mjs', '--check'], { cwd: directory, encoding: 'utf8' });
    assert.equal(stale.status, 1);
    assert.match(stale.stderr, /Stale generated output: web\/theme.css/);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
