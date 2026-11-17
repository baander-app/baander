import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test as base, expect, type Locator } from '@playwright/test';

const test = base.extend<{ localRequests: void }>({
  localRequests: [async ({ context, baseURL }, use) => {
    const outbound: string[] = [];
    await context.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== new URL(baseURL!).origin) {
        outbound.push(route.request().url());
        await route.abort();
      } else await route.continue();
    });
    await use();
    expect(outbound, 'Feature pages must load without external services').toEqual([]);
  }, { auto: true }],
});

// The specs cannot read the site's data files, so the built sitemap says which feature pages exist.
const sitemap = readFileSync(fileURLToPath(new URL('../../../var/website/sitemap.xml', import.meta.url)), 'utf8');
const featurePages = [...sitemap.matchAll(/<loc>https:\/\/baander\.app(\/features\/[^<]+)<\/loc>/g)].map((match) => match[1]);

async function loadedImage(image: Locator): Promise<void> {
  await image.scrollIntoViewIfNeeded();
  await expect(image).toBeVisible();
  await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
  await expect(image).toHaveCSS('filter', 'none');
  const light = await image.evaluate(() => document.documentElement.dataset.theme
    ? document.documentElement.dataset.theme === 'balanced'
    : matchMedia('(prefers-color-scheme: light)').matches);
  await expect(image).toHaveAttribute('src', light ? /-light\.webp$/ : /(?<!-light)\.webp$/);
}

/** The feature pages in the order the overview presents them. */
async function overviewLinks(page: import('@playwright/test').Page): Promise<string[]> {
  const response = await page.goto('/features/');
  expect(response?.status()).toBe(200);
  return page.locator('main a[href^="/features/"]').evaluateAll((links) => links.map((link) => link.getAttribute('href')!));
}

test('visitors reach the features overview from the header and the footer, and the header marks it on every feature page', async ({ page }) => {
  const header = page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Features', exact: true });
  await page.goto('/');
  await expect(header).not.toHaveAttribute('aria-current');
  await expect(page.getByRole('navigation', { name: 'Footer navigation' }).getByRole('link', { name: 'Features', exact: true }))
    .toHaveAttribute('href', '/features/');
  await header.click();
  await expect(page).toHaveURL(/\/features\/$/);
  await expect(page.locator('main h1')).toBeVisible();
  await expect(header).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Manual', exact: true }))
    .not.toHaveAttribute('aria-current');
  for (const path of featurePages) {
    await page.goto(path);
    await expect(header, path).toHaveAttribute('aria-current', 'true');
  }
});

test('the overview links to exactly the feature pages the sitemap lists', async ({ page }) => {
  const linked = await overviewLinks(page);
  expect([...linked].sort()).toEqual([...featurePages].sort());
});

test('a feature page describes itself under Features, and its breadcrumb shows the trail of its structured data', async ({ page }) => {
  await page.goto('/features/equalizer/');
  await expect(page).toHaveTitle('Equalizer — Features — Bånder');
  const head = await page.evaluate(() => ({
    canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href'),
    ogUrl: document.querySelector('meta[property="og:url"]')?.getAttribute('content'),
    graph: JSON.parse(document.querySelector('script[type="application/ld+json"]')!.textContent!)['@graph'],
    crumbs: [...document.querySelectorAll<HTMLAnchorElement>('nav[aria-label="Breadcrumb"] a')].map((link) => ({ name: link.textContent, url: link.href })),
  }));
  expect(head.canonical).toBe('https://baander.app/features/equalizer/');
  expect(head.ogUrl).toBe(head.canonical);
  expect(head.graph.map((node: { '@type': string }) => node['@type'])).toEqual(['WebPage', 'BreadcrumbList']);
  const listed: { name: string; url: string }[] = head.graph[1].itemListElement.map((item: { name: string; item: string }) => ({ name: item.name, url: item.item }));
  expect(listed).toEqual([
    { name: 'Bånder', url: 'https://baander.app/' },
    { name: 'Features', url: 'https://baander.app/features/' },
    { name: 'Equalizer', url: 'https://baander.app/features/equalizer/' },
  ]);
  // The header's brand link is the homepage, so the visible trail holds what lies between it and
  // the page, and the page's own name stands above the headline.
  const path = (url: string) => new URL(url).pathname;
  expect(head.crumbs.map((crumb) => ({ name: crumb.name, url: path(crumb.url) })))
    .toEqual(listed.slice(1, -1).map((item) => ({ name: item.name, url: path(item.url) })));
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('For listening');
  await expect(page.locator('.hero-copy .eyebrow')).toHaveText(listed.at(-1)!.name);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  for (const path of featurePages) {
    test(`${path}: every section, picture and step is readable`, async ({ page }) => {
      await page.goto(path);
      await expect(page.locator('[data-appearance-toggle]')).toBeHidden();
      const headings = await page.locator('main :is(h1, h2, h3)').all();
      expect(headings.length).toBeGreaterThan(0);
      for (const heading of headings) await expect(heading).toBeVisible();
      for (const section of await page.locator('main section[id]').all()) {
        await expect(section.locator('h2')).toBeVisible();
        await expect(section.locator('p').first()).toBeVisible();
      }
      for (const figure of await page.locator('main .app-screenshot').all()) {
        await loadedImage(figure.getByRole('img'));
        await expect(figure.locator('figcaption')).toBeVisible();
      }
      const steps = await page.locator('main ol > li').all();
      expect(steps.length).toBeGreaterThan(0);
      for (const step of steps) {
        await expect(step.locator('.step-number')).toBeVisible();
        await expect(step.getByRole('heading')).toBeVisible();
        await expect(step.locator('p')).toBeVisible();
      }
    });
  }
});

for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
  for (const colorScheme of ['light', 'dark'] as const) {
    test(`${viewport.width}px ${colorScheme}: the overview and every feature page stay within the viewport and show the pictures of the appearance`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.emulateMedia({ colorScheme });
      for (const path of ['/features/', ...featurePages]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(200);
        await expect(page.locator('main h1')).toBeVisible();
        for (const figure of await page.locator('main .app-screenshot').all()) await loadedImage(figure.getByRole('img'));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), path).toBe(true);
      }
    });
  }
}

for (const path of featurePages) {
  test(`${path}: each picture pair exposes one picture, which follows a theme switch`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
    await page.goto(path);
    const figures = await page.locator('main .app-screenshot').all();
    const exposed = async () => {
      const sources: string[] = [];
      for (const figure of figures) {
        const image = figure.getByRole('img');
        await expect(image).toHaveCount(1);
        await loadedImage(image);
        sources.push((await image.getAttribute('src'))!);
      }
      return sources;
    };
    const dark = await exposed();
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    expect(await exposed()).toEqual(dark.map((source) => source.replace(/\.webp$/, '-light.webp')));
  });

  test(`${path}: no picture is shown wider than half its file width`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(path);
    for (const figure of await page.locator('main .app-screenshot').all()) {
      const image = figure.getByRole('img');
      await loadedImage(image);
      const size = await image.evaluate((element: HTMLImageElement) => ({
        shown: element.getBoundingClientRect().width, file: element.naturalWidth, source: element.getAttribute('src')!,
        reserved: [Number(element.getAttribute('width')), Number(element.getAttribute('height'))],
      }));
      expect(size.shown, size.source).toBeLessThanOrEqual(size.file / 2);
      expect(size.reserved, `${size.source} reserves half its file size before it arrives`)
        .toEqual(await image.evaluate((element: HTMLImageElement) => [element.naturalWidth / 2, element.naturalHeight / 2]));
    }
  });
}

test('each feature page links to its neighbours in the overview\'s order and to the overview, and the ends do not wrap around', async ({ page }) => {
  const order = await overviewLinks(page);
  for (const [position, path] of order.entries()) {
    await page.goto(path);
    const pager = page.getByRole('navigation', { name: 'Feature pages' });
    await expect(pager.getByRole('link', { name: 'All features' }), path).toHaveAttribute('href', '/features/');
    const neighbours = await pager.locator('a[rel]').evaluateAll((links) => links.map((link) => [link.getAttribute('rel'), link.getAttribute('href')]));
    expect(neighbours, path).toEqual([
      ...(position > 0 ? [['prev', order[position - 1]]] : []),
      ...(position < order.length - 1 ? [['next', order[position + 1]]] : []),
    ]);
  }
});
