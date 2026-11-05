import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from '@playwright/test';
import { renderBlocks } from './render-blocks.mjs';

const root = new URL('../../../', import.meta.url);
const resources = {
  fontPath: fileURLToPath(new URL('ui/site/node_modules/@fontsource-variable/geist/files/geist-latin-wght-normal.woff2', root)),
  themeCssPath: fileURLToPath(new URL('ui/design-tokens/build/web/theme.css', root)),
};
const block = (language, source, id = language) => ({ id, language, source, page: 'part-2-developer-guide/testing.md', ordinal: 3 });
const decode = html => html.replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
// Lists every start tag of a serialized SVG in document order as { name, ...attributes }.
const tags = svg => [...svg.matchAll(/<([a-zA-Z]+)((?:\s+[\w:-]+="[^"]*")*)\s*\/?>/g)]
  .map(([, name, attributes]) => ({ name, ...Object.fromEntries([...attributes.matchAll(/([\w:-]+)="([^"]*)"/g)].map(([, key, value]) => [key, value])) }));
const withClass = (list, name) => list.filter(tag => tag.class?.split(/\s+/).includes(name));
const connections = tag => [tag['data-from'], tag['data-to']];
const diagram = async (source, id = 'diagram') => (await renderBlocks({ blocks: [block('mermaid', source, id)], ...resources }))[id];

test('all maintained languages preserve source and use only safe semantic spans', async () => {
  const sources = {
    bash: '# comment\r\necho "$HOME" && printf "<script>&"\r\n\r\n',
    env: 'BAANDER_URL=https://baander.app\nPORT=8080\n',
    json: '{"name": "<script>&", "count": 2}\n',
    yaml: 'name: "Bånder"\nitems:\n  - true\n',
    sql: 'SELECT name FROM users WHERE id = 42;\n',
    ts: 'const value: string = "Bånder";\n',
    tsx: 'export const App = () => <div title="&">Hello</div>;\n',
    text: '<script>alert("x")</script>\n',
    '': '\t<svg onload="bad">&\n',
    unknown: 'unknown <tag>\n',
  };
  const result = await renderBlocks({ blocks: Object.entries(sources).map(([lang, source]) => block(lang, source)), ...resources });
  for (const [lang, source] of Object.entries(sources)) {
    assert.equal(result[lang].kind, 'code');
    assert.equal(decode(result[lang].html), source, lang);
    assert.doesNotMatch(result[lang].html.replace(/<span class="tok-(keyword|string|number|comment|variable|operator|function|type)">|<\/span>/g, ''), /[<>]/);
    if (lang && !['text', 'unknown'].includes(lang)) assert.match(result[lang].html, /class="tok-/);
  }
});

test('real diagrams have deterministic paired SVGs, Geist labels, complete markers and dimensions', async () => {
  const blocks = [];
  for (const [index, name] of ['live-updates', 'application-threat-model', 'registry-threat-model'].entries()) {
    const markdown = await readFile(new URL(`docs-book/part-2-developer-guide/${name}.md`, root), 'utf8');
    blocks.push(block('mermaid', markdown.match(/```mermaid\n([\s\S]*?)```/)[1], `diagram-${index}`));
  }
  blocks.push(block('mermaid', 'flowchart LR\n A["Bånder &amp; long label with quotes #quot;test#quot;"] --> B[Done]\n', 'unicode'));
  const result = await renderBlocks({ blocks, ...resources });
  for (const item of Object.values(result)) {
    assert.ok(item.width > 0 && item.height > 0);
    assert.notEqual(item.light, item.dark);
    for (const svg of [item.light, item.dark]) {
      assert.match(svg, /^<svg/);
      assert.match(svg, /font-family:"Geist Variable";font-size:16px/);
      assert.doesNotMatch(svg, /Inter/);
      assert.match(svg, /marker-end="url\(#/);
      assert.doesNotMatch(svg, /<foreignObject|<script|onclick=|<a\b/);
      assert.doesNotMatch(svg.replace(/<style>[\s\S]*?<\/style>/g, ''), /data-look="neo"/);
    }
  }
  assert.match(result.unicode.light, /Bånder/);
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    const font = await readFile(resources.fontPath);
    await page.evaluate(async base64 => {
      document.fonts.add(await new FontFace('Geist Variable', `url(data:font/woff2;base64,${base64})`, { weight: '100 900' }).load());
      await document.fonts.ready;
    }, font.toString('base64'));
    for (const item of Object.values(result)) {
      for (const svg of [item.light, item.dark]) {
        const inspection = await page.evaluate(svg => {
          document.body.innerHTML = svg;
          const bounds = document.querySelector('svg').getBoundingClientRect();
          const labels = [...document.querySelectorAll('text')].map(text => {
            const rect = text.getBoundingClientRect();
            return {
              label: text.textContent,
              font: getComputedStyle(text).fontFamily,
              width: rect.width,
              contained: rect.left >= bounds.left - 1 && rect.right <= bounds.right + 1
                && rect.top >= bounds.top - 1 && rect.bottom <= bounds.bottom + 1,
            };
          });
          const shadowed = [...document.querySelectorAll('svg *')].filter(element => /drop-shadow/.test(getComputedStyle(element).filter));
          return { labels, shadowed: shadowed.map(element => element.outerHTML.slice(0, 150)) };
        }, svg);
        assert.deepEqual(inspection.shadowed, [], 'Static diagrams must not render drop shadows');
        assert.ok(inspection.labels.length > 0);
        for (const label of inspection.labels) {
          assert.match(label.font, /^"Geist Variable"/);
          assert.ok(label.width > 0, label.label);
          assert.ok(label.contained, `Clipped SVG label: ${label.label}`);
        }
      }
    }
  } finally {
    await browser.close();
  }
  const repeat = await renderBlocks({ blocks: [blocks[0]], ...resources });
  assert.equal(repeat['diagram-0'].light, result['diagram-0'].light);
});

test('diagram failures identify the source page and block', async () => {
  for (const source of ['flowchart LR\n A[broken', 'not-a-diagram\n x', '%%{init: {"look":"neo"}}%%\nflowchart LR\n A-->B', '%%{init: {"securityLevel":"loose"}}%%\nflowchart LR\n A-->B', 'flowchart LR\n A-->B\n click A "https://baander.app"', '---\nconfig:\n theme: dark\n---\nflowchart LR\n A-->B']) {
    await assert.rejects(renderBlocks({ blocks: [block('mermaid', source)], ...resources }), /testing\.md.*block 3/);
  }
  await assert.rejects(renderBlocks({ blocks: [block('mermaid', 'flowchart LR\n A-->B')], ...resources, fontPath: '/missing/geist.woff2' }), /testing\.md.*block 3.*font/i);
});


test('flowchart nodes, edges and edge labels carry endpoint data from the parsed diagram', async () => {
  const item = await diagram('flowchart LR\n a_b -->|goes to| b\n');
  assert.deepEqual(Object.keys(item).sort(), ['dark', 'height', 'kind', 'light', 'width']);
  const list = tags(item.light);
  assert.deepEqual(withClass(list, 'node').map(tag => tag['data-node']), ['a_b', 'b']);
  const edges = list.filter(tag => tag.name === 'path' && tag['data-et'] === 'edge');
  assert.equal(edges.length, 1);
  assert.deepEqual(connections(edges[0]), ['a_b', 'b']);
  const labelIndex = list.findIndex(tag => tag.name === 'g' && tag.class === 'label' && tag['data-id'] === edges[0]['data-id']);
  assert.ok(labelIndex > 0, 'The edge label group carries the edge id');
  const labelGroup = list[labelIndex - 1];
  assert.equal(labelGroup.class, 'edgeLabel');
  assert.deepEqual(connections(labelGroup), ['a_b', 'b']);
});

test('an edge ending on a subgraph names the subgraph, and the cluster carries its source id', async () => {
  const item = await diagram('flowchart LR\n subgraph s[Label]\n  y\n end\n x --> s\n');
  const list = tags(item.light);
  assert.deepEqual(withClass(list, 'cluster').map(tag => tag['data-cluster']), ['s']);
  assert.deepEqual(withClass(list, 'node').map(tag => tag['data-node']).sort(), ['x', 'y']);
  const edges = list.filter(tag => tag.name === 'path' && tag['data-et'] === 'edge');
  assert.deepEqual(edges.map(connections), [['x', 's']]);
});

test('two edges between the same pair keep distinct ids and share their endpoints', async () => {
  const item = await diagram('flowchart LR\n a -->|one| b\n a -->|two| b\n');
  const edges = tags(item.light).filter(tag => tag.name === 'path' && tag['data-et'] === 'edge');
  assert.equal(edges.length, 2);
  assert.deepEqual(edges.map(connections), [['a', 'b'], ['a', 'b']]);
  assert.notEqual(edges[0]['data-id'], edges[1]['data-id']);
});

test('invisible layout edges keep their class and carry no endpoints', async () => {
  const item = await diagram('flowchart LR\n a ~~~ b\n');
  const list = tags(item.light);
  const edges = list.filter(tag => tag.name === 'path' && tag['data-et'] === 'edge');
  assert.equal(edges.length, 1);
  assert.equal(withClass(edges, 'edge-thickness-invisible').length, 1);
  assert.deepEqual(list.flatMap(connections).filter(Boolean), [], 'No element carries endpoints for a layout edge');
  assert.deepEqual(withClass(list, 'node').map(tag => tag['data-node']), ['a', 'b']);
});

test('sequence message texts carry the endpoints of their message lines in document order', async () => {
  const item = await diagram('sequenceDiagram\n participant A\n participant B\n A->>B: hello\n B-->>A: reply<br/>again\n');
  const list = tags(item.light);
  const lines = list.filter(tag => tag['data-et'] === 'message');
  assert.deepEqual(lines.map(connections), [['A', 'B'], ['B', 'A']]);
  const texts = withClass(list.filter(tag => tag.name === 'text'), 'messageText');
  assert.deepEqual(texts.map(connections), [['A', 'B'], ['B', 'A'], ['B', 'A']]);
});

test('an edge path without a parsed edge fails the render and names the page and block', async () => {
  const describeDiagram = (parsed, describe) => {
    const description = describe(parsed);
    description.edges.pop();
    return description;
  };
  await assert.rejects(
    renderBlocks({ blocks: [block('mermaid', 'flowchart LR\n a --> b\n b --> c\n')], ...resources }, { describeDiagram }),
    /testing\.md, block 3: .*L_b_c_0.*matches no parsed edge/,
  );
});

test('light and dark variants carry identical endpoint data', async () => {
  const item = await diagram('flowchart TB\n subgraph s[Group]\n  a -->|one| b\n end\n a ~~~ c\n c --> s\n');
  const data = svg => tags(svg).map(tag => ['data-node', 'data-cluster', 'data-from', 'data-to'].map(key => tag[key])).filter(values => values.some(Boolean));
  const light = data(item.light);
  assert.ok(light.length >= 6);
  assert.deepEqual(data(item.dark), light);
});

test('CLI writes the agreed manifest and preserves its output on failure with actionable Chromium guidance', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'baander-render-blocks-'));
  const input = join(directory, 'request.json');
  const output = join(directory, 'result.json');
  const run = promisify(execFile);
  const script = fileURLToPath(new URL('./render-blocks.mjs', import.meta.url));
  try {
    await writeFile(input, JSON.stringify({ blocks: [block('json', '{"value":42}\n')], ...resources }));
    await run(process.execPath, [script, input, output]);
    const result = JSON.parse(await readFile(output, 'utf8'));
    assert.equal(decode(result.json.html), '{"value":42}\n');
    const previous = await readFile(output, 'utf8');
    await writeFile(input, JSON.stringify({ blocks: [block('mermaid', 'flowchart LR\n A-->B')], ...resources }));
    await assert.rejects(run(process.execPath, [script, input, output], {
      env: { ...process.env, PLAYWRIGHT_BROWSERS_PATH: join(directory, 'missing-browsers') },
    }), error => {
      assert.equal(error.code, 1);
      assert.match(error.stderr, /testing\.md.*block 3.*Chromium is unavailable/);
      assert.match(error.stderr, /playwright install chromium/);
      return true;
    });
    assert.equal(await readFile(output, 'utf8'), previous);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
