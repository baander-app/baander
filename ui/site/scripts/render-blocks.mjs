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

// The two helpers below run inside the Mermaid page, serialized by Playwright, so they must stay self-contained.
// Reduces Mermaid's parsed diagram to the names a stamped SVG needs: node and cluster ids and edge endpoints.
function describeDiagram(parsed) {
  if (parsed.type !== 'flowchart-v2') return { type: parsed.type };
  return {
    type: 'flowchart',
    nodes: [...parsed.db.getVertices().values()].map(vertex => ({ id: vertex.id, domId: vertex.domId })),
    clusters: parsed.db.getSubGraphs().map(subgraph => subgraph.id),
    edges: parsed.db.getEdges().map(edge => ({ id: edge.id, from: edge.start, to: edge.end, visible: edge.stroke !== 'invisible' })),
  };
}

// Stamps endpoint data onto the rendered SVG so a page script can follow one connection at a time.
function stampConnections(root, diagram) {
  if (diagram.type === 'sequence') {
    // Mermaid draws each message's text lines right before the message line, so a message claims the texts before it.
    let texts = [];
    for (const element of root.querySelectorAll('text.messageText, [data-et="message"]')) {
      if (element.getAttribute('data-et') !== 'message') {
        texts.push(element);
        continue;
      }
      if (!texts.length) throw new Error(`Sequence message ${element.getAttribute('data-id')} has no message text to pair with`);
      for (const text of texts) {
        text.setAttribute('data-from', element.getAttribute('data-from'));
        text.setAttribute('data-to', element.getAttribute('data-to'));
      }
      texts = [];
    }
    if (texts.length) throw new Error(`${texts.length} sequence message texts follow no message line`);
    return;
  }
  if (diagram.type !== 'flowchart') return;
  const prefix = `${root.getAttribute('id')}-`;
  const stampGroups = (selector, attribute, names, kind) => {
    for (const group of root.querySelectorAll(selector)) {
      const name = names.get(group.getAttribute('id'));
      if (name === undefined) throw new Error(`${kind} ${group.getAttribute('id')} matches no parsed ${kind.toLowerCase()}`);
      group.setAttribute(attribute, name);
    }
  };
  stampGroups('g.node', 'data-node', new Map(diagram.nodes.map(node => [prefix + node.domId, node.id])), 'Node');
  stampGroups('g.cluster', 'data-cluster', new Map(diagram.clusters.map(id => [prefix + id, id])), 'Cluster');
  const edges = new Map(diagram.edges.map(edge => [edge.id, edge]));
  const stampEdge = (element, id) => {
    const edge = edges.get(id);
    if (!edge) throw new Error(`Edge ${id} matches no parsed edge`);
    if (!edge.visible) return;
    element.setAttribute('data-from', edge.from);
    element.setAttribute('data-to', edge.to);
  };
  for (const path of root.querySelectorAll('path[data-et="edge"]')) stampEdge(path, path.getAttribute('data-id'));
  for (const label of root.querySelectorAll('g.edgeLabel > g.label[data-id]')) stampEdge(label.parentNode, label.getAttribute('data-id'));
}

async function createDiagramRenderer(request, options) {
  let font;
  let themeCss;
  try {
    font = await readFile(request.fontPath);
  } catch (error) {
    throw new Error(`Cannot load the local Geist font: ${error.message}`);
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
      const font = new FontFace('Geist Variable', `url(data:font/woff2;base64,${base64})`, { weight: '100 900', style: 'normal' });
      document.fonts.add(await font.load());
      await document.fonts.ready;
      document.body.style.fontFamily = '"Geist Variable"';
    }, font.toString('base64'));
    const bundle = await readFile(fileURLToPath(new URL('../node_modules/mermaid/dist/mermaid.min.js', import.meta.url)), 'utf8');
    await page.addScriptTag({ content: bundle });
    // options.describeDiagram replaces the parsed-diagram description; it receives the default describer as its second argument.
    const describe = options.describeDiagram ?? describeDiagram;
    await page.addScriptTag({ content: `window.describeDiagram = ${describeDiagram}; window.describe = ${describe}; window.stampConnections = ${stampConnections};` });
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
          // Blends two #rrggbb colors; weight is the share of the first color.
          const mix = (first, second, weight) => '#' + [1, 3, 5].map(offset => {
            const channel = Math.round(parseInt(first.slice(offset, offset + 2), 16) * weight + parseInt(second.slice(offset, offset + 2), 16) * (1 - weight));
            return channel.toString(16).padStart(2, '0');
          }).join('');
          const foreground = color('foreground');
          const muted = color('muted-foreground');
          const canvas = color('background');
          const card = color('card');
          const border = color('border');
          const accent = color('accent-text');
          const dark = appearance === 'dark';
          const nodeFill = mix(accent, card, dark ? 0.1 : 0.07);
          const nodeBorder = mix(accent, border, dark ? 0.55 : 0.5);
          const clusterFill = mix(accent, canvas, dark ? 0.035 : 0.025);
          const clusterBorder = mix(muted, border, 0.45);
          const edge = mix(muted, canvas, 0.85);
          window.mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            htmlLabels: false,
            theme: 'base',
            look: 'classic',
            fontFamily: '"Geist Variable"',
            deterministicIds: true,
            deterministicIDSeed: seed,
            suppressErrorRendering: true,
            secure: ['secure', 'securityLevel', 'startOnLoad', 'htmlLabels', 'look', 'theme', 'themeVariables', 'themeCSS', 'fontFamily', 'flowchart'],
            flowchart: { htmlLabels: false, useMaxWidth: false, curve: 'rounded', nodeSpacing: 40, rankSpacing: 48, padding: 20, diagramPadding: 12 },
            themeVariables: {
              darkMode: dark, background: canvas, fontFamily: '"Geist Variable"', fontSize: '16px',
              primaryColor: nodeFill, primaryTextColor: foreground, primaryBorderColor: nodeBorder,
              secondaryColor: nodeFill, secondaryTextColor: foreground, secondaryBorderColor: nodeBorder,
              tertiaryColor: clusterFill, tertiaryTextColor: foreground, tertiaryBorderColor: clusterBorder,
              lineColor: edge, textColor: foreground, mainBkg: nodeFill,
              nodeBorder, clusterBkg: clusterFill, clusterBorder,
              edgeLabelBackground: canvas, titleColor: muted,
            },
            themeCSS: [
              `.node rect, .node polygon, .node circle, .node path { stroke-width: 1.25px; }`,
              `.cluster rect { stroke-width: 1px; stroke-dasharray: 5 4; }`,
              `.cluster-label text { fill: ${muted}; }`,
              `.flowchart-link { stroke-width: 1.5px; }`,
              `.marker, .arrowMarkerPath { fill: ${edge}; stroke: ${edge}; }`,
              `.edgeLabel text { fill: ${muted}; }`,
              `.edgeLabel rect.background, .labelBkg { fill: ${canvas}; opacity: 1; }`,
            ].join(''),
          });
          const diagram = window.describe(await window.mermaid.mermaidAPI.getDiagramFromText(source), window.describeDiagram);
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
          // Corner radii are geometry attributes, so they hold in every SVG renderer rather than depending on CSS geometry support.
          const round = (selector, radius) => xml.querySelectorAll(selector).forEach(rect => {
            rect.setAttribute('rx', radius);
            rect.setAttribute('ry', radius);
          });
          round('.node rect.label-container, .node rect.basic', '8');
          round('.cluster > rect', '12');
          round('.edgeLabel rect.background', '6');
          window.stampConnections(root, diagram);
          return { svg: new XMLSerializer().serializeToString(root), width: viewBox[2], height: viewBox[3] };
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

export async function renderBlocks(request, options = {}) {
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
          diagrams ??= await createDiagramRenderer(request, options);
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
              // const bindings are ordinary variables to the reader, not literal constants.
              { scope: 'variable.other.constant', settings: { foreground: 'var(--shiki-token-variable)' } },
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
