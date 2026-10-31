import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { createCssVariablesTheme, createHighlighter } from 'shiki';

const languages = new Map([
  ['bash', 'bash'], ['sh', 'bash'], ['shell', 'bash'],
  ['env', 'dotenv'], ['dotenv', 'dotenv'], ['json', 'json'],
  ['yaml', 'yaml'], ['yml', 'yaml'], ['sql', 'sql'],
  ['ts', 'typescript'], ['typescript', 'typescript'], ['tsx', 'tsx'],
]);
const roles = {
  string: 'string', comment: 'comment', constant: 'number', keyword: 'keyword',
  parameter: 'variable', function: 'function', 'string-expression': 'string',
  inserted: 'string', deleted: 'string', changed: 'operator', punctuation: 'operator',
  operator: 'operator', type: 'type', variable: 'variable',
};
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;');
const location = block => `${block.page}, block ${block.ordinal}`;

function highlight(highlighter, source, language) {
  const { tokens } = highlighter.codeToTokens(source, { lang: language, theme: 'css-variables' });
  const parts = source.split(/(\r\n|\r|\n)/);
  return parts.map((part, index) => {
    if (index % 2) return part;
    const line = tokens[index / 2] ?? [];
    if (line.map(token => token.content).join('') !== part) throw new Error('Syntax highlighter changed the source text');
    return line.map(token => {
      const key = token.color?.match(/^var\(--shiki-token-([\w-]+)\)$/)?.[1];
      const role = roles[key];
      return role ? `<span class="tok-${role}">${escape(token.content)}</span>` : escape(token.content);
    }).join('');
  }).join('');
}

function validateDiagram(block) {
  if (/%%\s*\{/.test(block.source) || /^\s*---(?:\r?\n|$)/.test(block.source)) {
    throw new Error('Mermaid configuration directives are disabled; use the shared site theme');
  }
  if (/(?:^|[\n;])\s*click\s/i.test(block.source)) {
    throw new Error('Mermaid click links and callbacks are disabled');
  }
}

async function createDiagramRenderer(request) {
  let font;
  let themeCss;
  try {
    font = await readFile(request.fontPath);
  } catch (error) {
    throw new Error(`Cannot load the local Inter font: ${error.message}`);
  }
  try {
    themeCss = await readFile(request.themeCssPath, 'utf8');
  } catch (error) {
    throw new Error(`Cannot load shared theme CSS: ${error.message}`);
  }
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
  } catch (error) {
    throw new Error(`Chromium is unavailable. Run yarn --cwd ui/site exec playwright install chromium (with --with-deps on CI). ${error.message}`);
  }
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const blockedRequests = [];
    await context.route('**/*', route => {
      blockedRequests.push(route.request().url());
      return route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    await page.setContent('<!doctype html><html><head></head><body></body></html>');
    await page.addStyleTag({ content: themeCss });
    await page.evaluate(async base64 => {
      const font = new FontFace('Inter', `url(data:font/woff2;base64,${base64})`, { weight: '400', style: 'normal' });
      document.fonts.add(await font.load());
      await document.fonts.ready;
      document.body.style.fontFamily = 'Inter';
    }, font.toString('base64'));
    const bundle = await readFile(fileURLToPath(new URL('../node_modules/mermaid/dist/mermaid.min.js', import.meta.url)), 'utf8');
    await page.addScriptTag({ content: bundle });
    return {
      close: () => browser.close(),
      async render(block, appearance) {
        const seed = createHash('sha256').update(`${block.id}:${appearance}`).digest('hex').slice(0, 20);
        const result = await page.evaluate(async ({ source, appearance, seed }) => {
          document.documentElement.dataset.theme = appearance === 'light' ? 'balanced' : 'dark';
          const css = getComputedStyle(document.documentElement);
          const color = role => {
            const value = css.getPropertyValue(`--color-${role}`).trim();
            if (!/^#[0-9a-f]{6}$/i.test(value)) throw new Error(`Missing shared theme color ${role}`);
            return value;
          };
          const foreground = color('foreground');
          const background = color('background');
          const surface = color('card');
          const line = color('muted-foreground');
          window.mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            htmlLabels: false,
            theme: 'base',
            look: 'classic',
            fontFamily: 'Inter',
            deterministicIds: true,
            deterministicIDSeed: seed,
            suppressErrorRendering: true,
            secure: ['secure', 'securityLevel', 'startOnLoad', 'htmlLabels', 'look', 'theme', 'themeVariables', 'fontFamily', 'flowchart'],
            flowchart: { htmlLabels: false, useMaxWidth: false, curve: 'linear' },
            themeVariables: {
              darkMode: appearance === 'dark', background, fontFamily: 'Inter', fontSize: '16px',
              primaryColor: surface, primaryTextColor: foreground, primaryBorderColor: line,
              secondaryColor: surface, secondaryTextColor: foreground, secondaryBorderColor: line,
              tertiaryColor: surface, tertiaryTextColor: foreground, tertiaryBorderColor: line,
              lineColor: line, textColor: foreground, mainBkg: surface,
              nodeBorder: line, clusterBkg: surface, clusterBorder: line,
              edgeLabelBackground: background, titleColor: foreground,
            },
          });
          const rendered = await window.mermaid.render(`diagram-${seed}`, source);
          // Mermaid includes these unused animation defaults even in static flowcharts.
          const svg = rendered.svg
            .replaceAll('@keyframes edge-animation-frame{from{stroke-dashoffset:0;}}', '')
            .replaceAll('@keyframes dash{to{stroke-dashoffset:0;}}', '');
          const xml = new DOMParser().parseFromString(svg, 'image/svg+xml');
          const root = xml.documentElement;
          const viewBox = root.getAttribute('viewBox')?.split(/\s+/).map(Number);
          if (root.localName !== 'svg' || viewBox?.length !== 4 || !viewBox.every(Number.isFinite) || viewBox[2] <= 0 || viewBox[3] <= 0) {
            throw new Error('Mermaid generated an invalid SVG viewport');
          }
          return { svg, width: viewBox[2], height: viewBox[3] };
        }, { source: block.source, appearance, seed });
        if (blockedRequests.length) throw new Error('Diagram attempted to request an external resource');
        return result;
      },
    };
  } catch (error) {
    await browser.close();
    throw error;
  }
}

export async function renderBlocks(request) {
  if (!request || !Array.isArray(request.blocks)) throw new Error('The render request must contain a blocks array');
  const result = Object.create(null);
  let highlighter;
  let diagrams;
  try {
    for (const block of request.blocks) {
      if (!block || typeof block.id !== 'string' || typeof block.source !== 'string' || typeof block.language !== 'string' || typeof block.page !== 'string' || !Number.isInteger(block.ordinal)) {
        throw new Error('Each render block requires id, source, language, page and integer ordinal');
      }
      if (Object.hasOwn(result, block.id)) throw new Error(`${location(block)}: duplicate block id`);
      try {
        if (block.language === 'mermaid') {
          validateDiagram(block);
          diagrams ??= await createDiagramRenderer(request);
          const light = await diagrams.render(block, 'light');
          const dark = await diagrams.render(block, 'dark');
          result[block.id] = { kind: 'diagram', light: light.svg, dark: dark.svg, width: Math.max(light.width, dark.width), height: Math.max(light.height, dark.height) };
        } else if (languages.has(block.language)) {
          if (!highlighter) {
            const theme = createCssVariablesTheme({ fontStyle: false });
            theme.tokenColors.push(
              { scope: 'keyword.operator', settings: { foreground: 'var(--shiki-token-operator)' } },
              { scope: ['entity.name.type', 'support.type'], settings: { foreground: 'var(--shiki-token-type)' } },
              { scope: 'variable', settings: { foreground: 'var(--shiki-token-variable)' } },
            );
            highlighter = await createHighlighter({ langs: [...new Set(languages.values())], themes: [theme] });
          }
          result[block.id] = { kind: 'code', html: highlight(highlighter, block.source, languages.get(block.language)) };
        } else {
          result[block.id] = { kind: 'code', html: escape(block.source) };
        }
      } catch (error) {
        throw new Error(`${location(block)}: ${error.message}`, { cause: error });
      }
    }
    return result;
  } finally {
    highlighter?.dispose();
    await diagrams?.close();
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv.length !== 4) throw new Error('Usage: node render-blocks.mjs <request.json> <result.json>');
    const request = JSON.parse(await readFile(process.argv[2], 'utf8'));
    const result = await renderBlocks(request);
    await writeFile(process.argv[3], JSON.stringify(result));
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}
