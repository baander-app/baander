import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const MOODS = ['dark', 'warm', 'cool', 'balanced'];
const ACCENTS = ['white', 'blue', 'violet', 'rose', 'amber', 'emerald', 'cyan', 'pink'];

// ── Helpers ──────────────────────────────────────────────────────────

function readJson(relPath) {
  return JSON.parse(readFileSync(join(__dirname, relPath), 'utf8'));
}

function writeOut(relPath, content) {
  const abs = join(__dirname, 'build', relPath);
  mkdirSync(dirname(abs), { recursive: true });
  writeFileSync(abs, content, 'utf8');
  console.log(`  ${relPath}`);
}

/** Deep-merge source onto target (source wins). */
function merge(target, source) {
  const result = { ...target };
  for (const [key, val] of Object.entries(source)) {
    if (val && typeof val === 'object' && !Array.isArray(val) && 'value' in val) {
      // Leaf token — override
      result[key] = val;
    } else if (val && typeof val === 'object' && !Array.isArray(val)) {
      result[key] = merge(result[key] || {}, val);
    } else {
      result[key] = val;
    }
  }
  return result;
}

/** Flatten { color: { background: { value, type } } } → [{ path: ['color','background'], ... }] */
function flatten(tree, prefix = []) {
  const out = [];
  for (const [key, val] of Object.entries(tree)) {
    if (val && typeof val === 'object' && 'value' in val) {
      out.push({ path: [...prefix, key], ...val });
    } else if (val && typeof val === 'object') {
      out.push(...flatten(val, [...prefix, key]));
    }
  }
  return out;
}

/** Convert token path to CSS variable name: ['color','background'] → --color-background */
function cssName(path) {
  return '--' + path.map(p => p.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase()).join('-');
}

/** Convert token path to TS property name: ['color','primaryForeground'] → primaryForeground */
function tsKey(path) {
  return path.slice(1).join('');
}

function uncapitalize(s) { return s.charAt(0).toLowerCase() + s.slice(1); }
function capitalize(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

// ── Load base tokens ─────────────────────────────────────────────────

const baseFiles = ['colors', 'radii', 'spacing', 'typography', 'durations'];
let base = {};
for (const name of baseFiles) {
  base = merge(base, readJson(`tokens/base/${name}.json`));
}

// ── Resolve reference tokens (e.g. {font.sans} → value) ──────────────

function resolveRefs(tokens) {
  const flat = flatten(tokens);
  const lookup = {};
  for (const t of flat) {
    lookup[t.path.join('.')] = t.value;
  }

  function resolveObj(obj) {
    const result = {};
    for (const [key, val] of Object.entries(obj)) {
      if (val && typeof val === 'object' && 'value' in val) {
        let v = val.value;
        if (typeof v === 'string' && v.startsWith('{') && v.endsWith('}')) {
          const ref = v.slice(1, -1);
          v = lookup[ref] ?? v;
        }
        result[key] = { ...val, value: v };
      } else if (val && typeof val === 'object') {
        result[key] = resolveObj(val);
      } else {
        result[key] = val;
      }
    }
    return result;
  }
  return resolveObj(tokens);
}

const resolvedBase = resolveRefs(base);

// ── Web: CSS custom properties ────────────────────────────────────────

console.log('\n Web CSS:');

// Base tokens (dark mood = default)
const baseFlat = flatten(resolvedBase);
const baseLines = [':root {'];
for (const t of baseFlat) {
  baseLines.push(`  ${cssName(t.path)}: ${t.value};`);
}
baseLines.push('}');
writeOut('web/tokens.css', baseLines.join('\n') + '\n');

// Per-mood overrides
const allMoods = readJson('tokens/mood/moods.json');
for (const mood of MOODS) {
  const moodTokens = allMoods[mood];
  if (!moodTokens) continue;
  const merged = merge(resolvedBase, moodTokens);
  const resolved = resolveRefs(merged);
  const flat = flatten(resolved);

  // Only emit tokens that differ from base
  const overrides = flat.filter((t) => {
    const baseToken = baseFlat.find(b => cssName(b.path) === cssName(t.path));
    return !baseToken || baseToken.value !== t.value;
  });

  const lines = [`[data-theme="${mood}"] {`];
  for (const t of overrides) {
    lines.push(`  ${cssName(t.path)}: ${t.value};`);
  }
  lines.push('}');
  writeOut(`web/mood-${mood}.css`, lines.join('\n') + '\n');
}

// Accent selectors
const accentPalette = readJson('tokens/accent/palette.json');
const accentLines = [];
for (const [name, token] of Object.entries(accentPalette.accent)) {
  accentLines.push(`[data-accent="${name}"] {`);
  accentLines.push(`  --color-primary: ${token.value};`);
  accentLines.push(`  --color-ring: ${token.value};`);
  accentLines.push('}');
}
writeOut('web/accent.css', accentLines.join('\n') + '\n');

// ── RN: TypeScript theme object ───────────────────────────────────────

console.log('\n RN TypeScript:');

const darkMood = allMoods.dark;
const mergedDark = merge(resolvedBase, darkMood);
const resolvedDark = resolveRefs(mergedDark);
const darkFlat = flatten(resolvedDark);

// Group by namespace
const namespaces = {};
for (const t of darkFlat) {
  const ns = t.path[0];
  if (!namespaces[ns]) namespaces[ns] = [];
  namespaces[ns].push(t);
}

// theme.types.ts
const typeLines = [
  '// Auto-generated by design-tokens build. Do not edit.',
  '/* eslint-disable */',
  '',
];

const nsMap = { color: 'ThemeColors', radius: 'ThemeRadii', space: 'ThemeSpacing', font: 'ThemeTypography', duration: 'ThemeDurations' };
for (const [ns, tokens] of Object.entries(namespaces)) {
  const ifaceName = nsMap[ns] || `Theme${capitalize(ns)}`;
  typeLines.push(`export interface ${ifaceName} {`);
  for (const t of tokens) {
    typeLines.push(`  readonly ${uncapitalize(tsKey(t.path))}: string`);
  }
  typeLines.push('}');
  typeLines.push('');
}

typeLines.push('export interface ThemeMeta {');
typeLines.push("  mood: 'dark' | 'warm' | 'cool' | 'balanced'");
typeLines.push('  accent: string');
typeLines.push('  isDark: boolean');
typeLines.push('}');
typeLines.push('');
typeLines.push('export interface Theme {');
typeLines.push('  colors: ThemeColors');
typeLines.push('  radii: ThemeRadii');
typeLines.push('  spacing: ThemeSpacing');
typeLines.push('  typography: ThemeTypography');
typeLines.push('  durations: ThemeDurations');
typeLines.push('  _meta: ThemeMeta');
typeLines.push('}');
typeLines.push('');
writeOut('rn/theme.types.ts', typeLines.join('\n'));

// theme.ts
const themeObj = { colors: {}, radii: {}, spacing: {}, typography: {}, durations: {} };
const nsTarget = { color: 'colors', radius: 'radii', space: 'spacing', font: 'typography', duration: 'durations' };
for (const t of darkFlat) {
  const target = nsTarget[t.path[0]];
  if (target && themeObj[target]) {
    themeObj[target][uncapitalize(tsKey(t.path))] = t.value;
  }
}

const objLines = [
  '// Auto-generated by @baander/design-tokens build. Do not edit.',
  '/* eslint-disable */',
  '',
  "import type { Theme } from './theme.types';",
  '',
  `const theme: Theme = ${JSON.stringify(themeObj, null, 2)} as Theme;`,
  '',
  'export default theme;',
  '',
];
writeOut('rn/theme.ts', objLines.join('\n'));

console.log('\n Design tokens built successfully\n');
