import { readFileSync } from 'node:fs';
import { test as base, expect, type Locator } from '@playwright/test';

const test = base.extend<{ localRequests: void }>({
  localRequests: [async ({ context, baseURL }, use) => {
    const outbound: string[] = [];
    await context.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== new URL(baseURL!).origin) {
        outbound.push(route.request().url());
        await route.abort();
      } else {
        await route.continue();
      }
    });
    await use();
    expect(outbound, 'Manual rendering must not contact external services').toEqual([]);
  }, { auto: true }],
});

function fences(file: string): { language: string; source: string }[] {
  const markdown = readFileSync(new URL(`../../../docs-book/${file}`, import.meta.url), 'utf8');
  return [...markdown.matchAll(/^```([^\n`]*)\n([\s\S]*?)^```\s*$/gm)]
    .map((match) => ({ language: match[1].trim(), source: match[2] }));
}

// `fits` asserts the diagram lays out inside its column at desktop width, so a
// page meant to be read without horizontal scrolling fails when a redraw widens it.
const diagramPages = [
  { chapter: 'operator-guide', directory: 'part-1-operator-guide', path: 'deployment-topology', fits: true },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'communication-map', fits: true },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'live-updates', fits: true },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'application-threat-model', fits: false },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'registry-threat-model', fits: false },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'cqrs-and-messaging', fits: true },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'contexts/transcode', fits: true },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'api-reference', fits: true },
];

async function visibleVariant(figure: Locator, appearance: 'light' | 'dark'): Promise<void> {
  const hidden = appearance === 'light' ? 'dark' : 'light';
  const image = figure.locator(`img.diagram-${appearance}`);
  const link = figure.locator(`a.diagram-${appearance}`);
  await expect(image).toBeVisible();
  await expect(link).toBeVisible();
  await expect(figure.locator(`img.diagram-${hidden}`)).toBeHidden();
  await expect(figure.locator(`a.diagram-${hidden}`)).toBeHidden();
  await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
  expect(await link.getAttribute('href')).toBe(await image.getAttribute('src'));
  await expect(image).toHaveAttribute('alt', /\S/);
}

for (const width of [1440, 390]) {
  for (const colorScheme of ['light', 'dark'] as const) {
    test.describe(`${width}px ${colorScheme} static manual`, () => {
      test.use({ javaScriptEnabled: false, viewport: { width, height: 1000 }, colorScheme });
      test('diagrams, original source and keyboard scrolling work without JavaScript', async ({ page }) => {
        for (const { chapter, directory, path, fits } of diagramPages) {
          const slug = path.replaceAll('/', '-');
          await page.goto(`/docs/${chapter}/${path}/`);
          const figure = page.locator('figure.diagram');
          await expect(figure).toHaveCount(1);
          await visibleVariant(figure, colorScheme);
          const region = figure.getByRole('region');
          await expect(region).toHaveAttribute('aria-label', /\S/);
          await region.focus();
          await expect(region).toBeFocused();
          expect(await region.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
          const geometry = await region.evaluate((element) => ({
            viewport: element.clientWidth,
            content: element.scrollWidth,
          }));
          expect(geometry.content).toBeGreaterThanOrEqual(geometry.viewport);
          if (fits && width === 1440) {
            expect(geometry.content, `${path} must fit its column without horizontal scrolling`).toBe(geometry.viewport);
          }
          const image = figure.locator(`img.diagram-${colorScheme}`);
          expect(await image.evaluate((element: HTMLImageElement) => element.getBoundingClientRect().width))
            .toBe(Number(await image.getAttribute('width')));
          if (geometry.content > geometry.viewport) {
            await region.press('ArrowRight');
            await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
          }
          expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
          await region.evaluate((element) => { element.scrollLeft = 0; });
          const screenshot = test.info().outputPath(`${slug}-${width}-${colorScheme}.png`);
          await figure.screenshot({ path: screenshot });
          await test.info().attach(`${slug}-${width}-${colorScheme}`, { path: screenshot, contentType: 'image/png' });
          const disclosure = figure.locator('details');
          await expect(disclosure).toHaveAttribute('data-pagefind-ignore', 'all');
          await disclosure.locator('summary').focus();
          await disclosure.locator('summary').press('Enter');
          await expect(disclosure).toHaveAttribute('open', '');
          const original = fences(`${directory}/${path}.md`).find((block) => block.language === 'mermaid')!;
          expect(await disclosure.locator('code').textContent()).toBe(original.source);
        }
      });
    });
  }
}

// With JavaScript on, the page script swaps the image for the built SVG; the image stays as the fallback.
async function visibleInteractive(figure: Locator, appearance: 'light' | 'dark'): Promise<Locator> {
  const hidden = appearance === 'light' ? 'dark' : 'light';
  const svg = figure.locator(`svg.diagram-interactive.diagram-${appearance}`);
  await expect(svg).toBeVisible();
  await expect(figure.locator(`img.diagram-${appearance}`)).toBeHidden();
  await expect(figure.locator(`svg.diagram-${hidden}`)).toBeHidden();
  await expect(figure.locator(`a.diagram-${appearance}`)).toBeVisible();
  await expect(figure.locator(`a.diagram-${hidden}`)).toBeHidden();
  expect(Number(await svg.getAttribute('width'))).toBeGreaterThan(0);
  await expect(svg).toHaveAttribute('role', 'img');
  await expect(svg).toHaveAttribute('aria-label', (await figure.locator(`img.diagram-${appearance}`).getAttribute('alt'))!);
  return svg;
}

for (const colorScheme of ['light', 'dark'] as const) {
  test(`${colorScheme} preference: manual choice selects one diagram variant after reload`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    await page.goto('/docs/developer-guide/live-updates/');
    const opposite = colorScheme === 'light' ? 'dark' : 'light';
    await visibleInteractive(page.locator('figure.diagram'), colorScheme);
    await page.getByRole('button', { name: `Switch to ${opposite} mode` }).click();
    await visibleInteractive(page.locator('figure.diagram'), opposite);
    await page.reload();
    await visibleInteractive(page.locator('figure.diagram'), opposite);
  });
}

const opacity = (locator: Locator) => locator.evaluate((element) => Number(getComputedStyle(element).opacity));
const inlineStroke = (locator: Locator) => locator.evaluate((element) => (element as SVGElement).style.stroke);

test.describe('interactive diagrams', () => {
  test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });

  test('a node emphasizes its connections and recedes the rest', async ({ page }) => {
    await page.goto('/docs/developer-guide/communication-map/');
    const figure = page.locator('figure.diagram');
    const svg = await visibleInteractive(figure, 'light');
    await svg.locator('g.node[data-node="execution"]').hover();
    await expect(svg.locator('g.node[data-node="execution"]')).toHaveClass(/is-active/);
    const attached = svg.locator('path[data-et="edge"][data-to="execution"], path[data-et="edge"][data-from="execution"]');
    expect(await attached.count()).toBeGreaterThanOrEqual(4);
    for (const edge of await attached.all()) {
      await expect(edge).toHaveClass(/is-related/);
      expect(await inlineStroke(edge)).toContain('--color-ring');
    }
    await expect(svg.locator('g.node[data-node="async"]')).toHaveClass(/is-related/);
    const unrelated = svg.locator('g.node[data-node="browser"]');
    await expect(unrelated).toHaveClass(/is-receded/);
    await expect.poll(() => opacity(unrelated)).toBeLessThan(0.5);
    await page.mouse.move(0, 0);
    await expect(svg.locator('.is-receded')).toHaveCount(0);
  });

  test('an edge emphasizes itself, its label and both endpoints', async ({ page }) => {
    await page.goto('/docs/developer-guide/communication-map/');
    const svg = await visibleInteractive(page.locator('figure.diagram'), 'light');
    const edge = svg.locator('path[data-et="edge"][data-from="outbox"][data-to="async"]');
    const label = svg.locator('g.edgeLabel[data-from="outbox"][data-to="async"]');
    await label.hover();
    await expect(edge).toHaveClass(/is-active/);
    await expect(label).toHaveClass(/is-active/);
    expect(await inlineStroke(edge)).toContain('--color-ring');
    await expect(svg.locator('g.node[data-node="outbox"]')).toHaveClass(/is-related/);
    await expect(svg.locator('g.node[data-node="async"]')).toHaveClass(/is-related/);
    await expect(svg.locator('g.node[data-node="browser"]')).toHaveClass(/is-receded/);
    await page.mouse.move(0, 0);
    await expect(edge).not.toHaveClass(/is-active/);
    // The invisible hit path widens the edge so a pointer near the line counts.
    const hit = svg.locator(`path.diagram-hit[data-hit-for="${await edge.getAttribute('data-id')}"]`);
    await expect(hit).toHaveCount(1);
    await hit.scrollIntoViewIfNeeded();
    const point = await hit.evaluate((element) => {
      const path = element as SVGPathElement;
      const along = path.getPointAtLength(path.getTotalLength() / 4);
      const { x, y } = new DOMPoint(along.x, along.y).matrixTransform(path.getScreenCTM()!);
      return { x, y };
    });
    await page.mouse.move(point.x, point.y);
    await expect(edge).toHaveClass(/is-active/);
  });

  test('a sequence message emphasizes its text and lifelines', async ({ page }) => {
    await page.goto('/docs/developer-guide/api-reference/');
    const svg = await visibleInteractive(page.locator('figure.diagram'), 'light');
    const text = svg.locator('text.messageText', { hasText: 'Proof with nonce' });
    await text.hover();
    await expect(text).toHaveClass(/is-active/);
    await expect(svg.locator('[data-et="life-line"][data-id="C"]')).toHaveClass(/is-related/);
    await expect(svg.locator('[data-et="life-line"][data-id="T"]')).toHaveClass(/is-related/);
    await expect(svg.locator('[data-et="life-line"][data-id="A"]')).toHaveClass(/is-receded/);
  });

  test('Enter steps through nodes with announcements and Escape restores scrolling', async ({ page }) => {
    await page.goto('/docs/developer-guide/application-threat-model/');
    const figure = page.locator('figure.diagram');
    const svg = await visibleInteractive(figure, 'light');
    const region = figure.getByRole('region');
    await region.focus();
    await expect(region).toBeFocused();
    await expect(figure.locator('p.diagram-hint')).toBeVisible();
    await expect(figure.locator('p.diagram-hint')).toContainText('Enter');
    await region.press('ArrowRight');
    await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
    await region.evaluate((element) => { element.scrollLeft = 0; });
    await region.press('Enter');
    await region.press('ArrowRight');
    const active = svg.locator('g.node.is-active');
    await expect(active).toHaveCount(1);
    const name = await svg.locator('g.node').nth(1).getAttribute('data-node');
    await expect(active).toHaveAttribute('data-node', name!);
    const announcer = figure.locator('p[aria-live="polite"]');
    await expect(announcer).toContainText(/connection/);
    await expect(figure.locator('p.diagram-hint')).toContainText('Escape');
    // Stepping brings the active node into the region instead of paging it.
    expect(await figure.evaluate((element) => {
      const node = element.querySelector('g.node.is-active')!.getBoundingClientRect();
      const box = element.querySelector('.diagram-viewport')!.getBoundingClientRect();
      return node.left >= box.left && node.right <= box.right;
    })).toBe(true);
    await region.press('ArrowDown');
    await expect(svg.locator('path[data-et="edge"].is-active')).toHaveCount(1);
    await region.press('Escape');
    await expect(svg.locator('.is-active')).toHaveCount(0);
    await expect(svg.locator('.is-receded')).toHaveCount(0);
    await region.press('ArrowRight');
    await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
  });

  test('a pinned node survives a theme switch with its scroll position', async ({ page }) => {
    await page.goto('/docs/developer-guide/application-threat-model/');
    const figure = page.locator('figure.diagram');
    const light = await visibleInteractive(figure, 'light');
    const region = figure.getByRole('region');
    await region.evaluate((element) => { element.scrollLeft = 120; });
    const name = (await light.locator('g.node').nth(2).getAttribute('data-node'))!;
    await light.locator(`g.node[data-node="${name}"]`).click();
    await expect(light.locator(`g.node[data-node="${name}"]`)).toHaveClass(/is-active/);
    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    const dark = await visibleInteractive(figure, 'dark');
    await expect(dark.locator(`g.node[data-node="${name}"]`)).toHaveClass(/is-active/);
    expect(await region.evaluate((element) => element.scrollLeft)).toBe(120);
  });

  test('a rejected asset leaves the image in place', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('**/assets/diagrams/*-light.svg', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>not a diagram</p>' }));
    await page.goto('/docs/developer-guide/live-updates/');
    const figure = page.locator('figure.diagram');
    await expect(figure.locator('img.diagram-light')).toBeVisible();
    await expect(figure.locator('svg.diagram-interactive')).toHaveCount(0);
    await page.unroute('**/assets/diagrams/*-light.svg');
    await page.route('**/assets/diagrams/*-light.svg', (route) => route.fulfill({ status: 404 }));
    await page.reload();
    await expect(figure.locator('img.diagram-light')).toBeVisible();
    await expect(figure.locator('svg.diagram-interactive')).toHaveCount(0);
    expect(errors).toEqual([]);
  });
});

test.describe('interactive diagrams on touch', () => {
  test.use({ hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 }, colorScheme: 'light' });

  test('a tap highlights and a tap elsewhere clears', async ({ page }) => {
    await page.goto('/docs/developer-guide/communication-map/');
    const figure = page.locator('figure.diagram');
    const svg = await visibleInteractive(figure, 'light');
    const node = svg.locator('g.node[data-node="execution"]');
    await node.scrollIntoViewIfNeeded();
    await node.tap();
    await expect(node).toHaveClass(/is-active/);
    await expect(figure.locator('p[aria-live="polite"]')).toContainText(/connection/);
    const edge = svg.locator('path[data-et="edge"][data-from="outbox"][data-to="async"]');
    const hit = svg.locator(`path.diagram-hit[data-hit-for="${await edge.getAttribute('data-id')}"]`);
    await hit.dispatchEvent('pointerdown', { pointerType: 'touch', clientX: 10, clientY: 10, isPrimary: true });
    await hit.dispatchEvent('pointerup', { pointerType: 'touch', clientX: 12, clientY: 11, isPrimary: true });
    await expect(edge).toHaveClass(/is-active/);
    await expect(node).not.toHaveClass(/is-active/);
    await figure.locator('figcaption').tap();
    await expect(svg.locator('.is-active')).toHaveCount(0);
  });
});

const codePages = [
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', slug: 'adding-a-feature', languages: ['php', 'yaml', 'bash'] },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', slug: 'frontend-development', languages: ['tsx'] },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', slug: 'development-environment', languages: ['json'] },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', slug: 'search', languages: ['sql'] },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', slug: 'live-updates', languages: ['ts'] },
  { chapter: 'operator-guide', directory: 'part-1-operator-guide', slug: 'security', languages: ['env'] },
];

test.describe('code without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  for (const colorScheme of ['light', 'dark'] as const) {
    test(`static ${colorScheme} highlighting preserves every example and readable token colors`, async ({ page }) => {
      await page.emulateMedia({ colorScheme });
      for (const { chapter, directory, slug, languages } of codePages) {
        await page.goto(`/docs/${chapter}/${slug}/`);
        const originals = fences(`${directory}/${slug}.md`).filter((block) => block.language !== 'mermaid');
        const examples = page.locator('.manual-article pre > code');
        const actual = await examples.evaluateAll((elements) => elements
          .filter((element) => !element.closest('figure.diagram'))
          .map((element) => element.textContent));
        expect(actual).toEqual(originals.map((block) => block.source));
        for (const language of languages) {
          const code = page.locator(`.manual-article pre > code.language-${language}`).first();
          await expect(code.locator('[class^="tok-"]').first()).toBeAttached();
        }
      }
      await page.goto('/docs/developer-guide/adding-a-feature/');
      const code = page.locator('code.language-php').first();
      const label = await code.evaluate((element) => getComputedStyle(element.closest('pre')!, '::before').content);
      expect(label, 'Named languages show a label outside the code text').toBe('"PHP"');
      const colors = await code.locator('[class^="tok-"]').evaluateAll((tokens) => {
        // Token colors may compute to oklab() through color-mix(); a canvas resolves any CSS color to sRGB bytes.
        const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!;
        const srgb = (color: string) => {
          context.clearRect(0, 0, 1, 1);
          context.fillStyle = color;
          context.fillRect(0, 0, 1, 1);
          const [red, green, blue] = context.getImageData(0, 0, 1, 1).data;
          return `rgb(${red}, ${green}, ${blue})`;
        };
        const background = srgb(getComputedStyle(tokens[0].closest('pre')!).backgroundColor);
        return { background, foregrounds: [...new Set(tokens.map((token) => srgb(getComputedStyle(token).color)))] };
      });
      expect(colors.foregrounds.length).toBeGreaterThanOrEqual(3);
      const luminance = (color: string) => {
        const rgb = color.match(/[\d.]+/g)!.slice(0, 3).map(Number).map((value) => {
          const channel = value / 255;
          return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        });
        return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
      };
      for (const foreground of colors.foregrounds) {
        const values = [luminance(foreground), luminance(colors.background)].sort((a, b) => a - b);
        expect((values[1] + 0.05) / (values[0] + 0.05)).toBeGreaterThanOrEqual(4.5);
      }
      const original = fences('part-2-developer-guide/adding-a-feature.md').find((block) => block.language === 'php')!;
      const selection = await code.evaluate((element) => {
        const selection = window.getSelection()!;
        const range = document.createRange();
        range.selectNodeContents(element);
        selection.removeAllRanges();
        selection.addRange(range);
        const highlighted = selection.toString();
        const plain = document.createElement('pre');
        plain.textContent = element.textContent;
        element.closest('pre')!.after(plain);
        range.selectNodeContents(plain);
        selection.removeAllRanges();
        selection.addRange(range);
        const unhighlighted = selection.toString();
        plain.remove();
        return { highlighted, unhighlighted };
      });
      expect(await code.textContent()).toBe(original.source);
      expect(selection.highlighted).toBe(selection.unhighlighted);
    });
  }
});
