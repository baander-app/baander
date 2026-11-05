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

const diagramPages = [
  { chapter: 'operator-guide', directory: 'part-1-operator-guide', path: 'deployment-topology' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'communication-map' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'live-updates' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'application-threat-model' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'registry-threat-model' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'cqrs-and-messaging' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'contexts/transcode' },
  { chapter: 'developer-guide', directory: 'part-2-developer-guide', path: 'api-reference' },
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
        for (const { chapter, directory, path } of diagramPages) {
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

for (const colorScheme of ['light', 'dark'] as const) {
  test(`${colorScheme} preference: manual choice selects one diagram variant after reload`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    await page.goto('/docs/developer-guide/live-updates/');
    const opposite = colorScheme === 'light' ? 'dark' : 'light';
    await visibleVariant(page.locator('figure.diagram'), colorScheme);
    await page.getByRole('button', { name: `Switch to ${opposite} mode` }).click();
    await visibleVariant(page.locator('figure.diagram'), opposite);
    await page.reload();
    await visibleVariant(page.locator('figure.diagram'), opposite);
  });
}

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
