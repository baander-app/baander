import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test as base, expect, type Page } from '@playwright/test';
import { openMenuPanel } from './site-menu';

// Checks every page of the design system section shares: the menu entry, the section navigation,
// the breadcrumb, the page's own metadata, search, containment in both site themes and reading
// without JavaScript. Each page's content has a spec of its own.

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
    expect(outbound, 'Design system pages must load without external services').toEqual([]);
  }, { auto: true }],
});

// The specs cannot read the site's data files, so the built sitemap says which pages the section has.
const sitemap = readFileSync(fileURLToPath(new URL('../../../var/website/sitemap.xml', import.meta.url)), 'utf8');
const sectionPages = [...sitemap.matchAll(/<loc>https:\/\/baander\.app(\/design-system\/[^<]*)<\/loc>/g)].map((match) => match[1]);

const sectionNavigation = (page: Page) => page.getByRole('navigation', { name: 'Design system', exact: true });

// The primary color in the color table, a value that sits only in cells left out of the search index.
const colorPage = readFileSync(fileURLToPath(new URL('../../../var/website/design-system/color/index.html', import.meta.url)), 'utf8');
const primaryColor = /--color-primary<\/code><\/th>\s*<td class="ds-value" data-pagefind-ignore><span class="ds-swatch" style="--swatch: (#[0-9a-f]{6})"/.exec(colorPage)![1];

test('the sitemap lists the overview and nine pages below it', () => {
  expect(sectionPages).toContain('/design-system/');
  expect(sectionPages).toHaveLength(10);
  expect(new Set(sectionPages).size).toBe(sectionPages.length);
  for (const path of sectionPages.filter((path) => path !== '/design-system/')) expect(path).toMatch(/^\/design-system\/[a-z]+(?:-[a-z]+)*\/$/);
});

test('visitors reach the overview from the Resources menu and the footer, and the menu marks it on every page of the section', async ({ page }) => {
  const entry = async () => (await openMenuPanel(page, 'Resources')).getByRole('link', { name: 'Design system', exact: true });
  await page.goto('/');
  await expect(await entry()).not.toHaveAttribute('aria-current');
  await expect(page.getByRole('navigation', { name: 'Footer navigation' }).getByRole('link', { name: 'Design system', exact: true }))
    .toHaveAttribute('href', '/design-system/');
  await (await entry()).click();
  await expect(page).toHaveURL(/\/design-system\/$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Design system' })).toBeVisible();
  await expect(await entry()).toHaveAttribute('aria-current', 'page');
  for (const path of sectionPages.filter((path) => path !== '/design-system/')) {
    await page.goto(path);
    await expect(await entry(), path).toHaveAttribute('aria-current', 'true');
    await expect((await openMenuPanel(page, 'Resources')).getByRole('link', { name: 'Typography', exact: true }), path)
      .not.toHaveAttribute('aria-current');
  }
});

test('the overview lists every page the sitemap lists, in the order of the section navigation', async ({ page }) => {
  await page.goto('/design-system/');
  const listed = await page.locator('.ds-pages a').evaluateAll((links) => links.map((link) => link.getAttribute('href')));
  expect([...listed].sort()).toEqual(sectionPages.filter((path) => path !== '/design-system/').sort());
  const navigation = await sectionNavigation(page).locator('.chapter-group').first().getByRole('link')
    .evaluateAll((links) => links.map((link) => link.getAttribute('href')));
  expect(navigation).toEqual(['/design-system/', ...listed]);
  for (const summary of await page.locator('.ds-pages li span').allTextContents()) expect(summary).toMatch(/\S/);
  await expect(page.getByRole('heading', { level: 2, name: 'Philosophy' })).toBeVisible();
});

for (const path of sectionPages) {
  test(`${path}: the section navigation marks the page, and its breadcrumb and metadata name it`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    const heading = (await page.locator('main h1').textContent())!.trim();
    expect(heading).not.toBe('');

    const current = sectionNavigation(page).locator('a[aria-current="page"]');
    await expect(current).toHaveCount(1);
    await expect(current).toHaveAttribute('href', path);
    await expect(sectionNavigation(page).getByRole('link', { name: 'Typography', exact: true })).toHaveAttribute('href', '/typography/');

    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(crumbs.locator('a').first()).toHaveAttribute('href', '/');
    await expect(crumbs.locator('[aria-current="page"]')).toHaveText(heading);
    if (path !== '/design-system/') {
      await expect(crumbs.getByRole('link', { name: 'Design system', exact: true })).toHaveAttribute('href', '/design-system/');
      await expect(page).toHaveTitle(`${heading} — Design system — Bånder`);
    } else {
      await expect(page).toHaveTitle('Design system — Bånder');
    }
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `https://baander.app${path}`);
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute('content', `https://baander.app${path}`);
    const description = await page.locator('meta[name="description"]').getAttribute('content');
    expect(description!.length).toBeGreaterThan(0);
    expect(description!.length).toBeLessThanOrEqual(155);
    await expect(page.locator('article[data-pagefind-body] [data-pagefind-meta="label"]')).toHaveText('Design system');
  });
}

test('a manual search finds the layout page by its prose under the Design system label, and no page by a color value', async ({ page }) => {
  await page.goto('/docs/');
  const input = page.getByRole('searchbox', { name: 'Search manuals' });
  const results = page.locator('#manual-search-results');
  await input.fill('Context panel width');
  const layout = results.locator('li').filter({ has: page.locator('a[href^="/design-system/layout/"]') });
  await expect(layout).toHaveCount(1);
  await expect(layout.locator('.search-context')).toHaveText('Design system');
  await expect(layout.locator('a').first()).toHaveAttribute('href', '/design-system/layout/');

  expect(colorPage).toContain(`<code>${primaryColor}</code>`);
  await input.fill(primaryColor);
  await expect(results).toHaveAttribute('aria-busy', 'false');
  await expect(page.getByRole('status')).not.toHaveText('Searching…');
  await expect(results.locator('a[href^="/design-system/"]')).toHaveCount(0);
});

for (const width of [390, 1440]) {
  for (const appearance of ['light', 'dark'] as const) {
    test(`${width}px ${appearance} site theme: no page scrolls sideways and nothing spills out of its box`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.addInitScript((choice) => localStorage.setItem('baander-site-appearance', choice), appearance);
      for (const path of sectionPages) {
        await page.goto(path);
        await expect(page.locator('html'), path).toHaveAttribute('data-theme', appearance === 'light' ? 'balanced' : 'dark');
        await page.evaluate(() => document.fonts.ready.then(() => undefined));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${path} scrolls sideways`).toBe(true);
        // A value table scrolls inside its focusable region; any other box wider inside than outside spills.
        const spilling = await page.locator('.manual-layout, .manual-layout *').evaluateAll((elements) => elements
          .filter((element) => element.scrollWidth > element.clientWidth + 1
            && getComputedStyle(element).display !== 'inline'
            && !element.matches('[role="region"][tabindex="0"]')
            && element.closest('.visually-hidden') === null)
          .map((element) => `${element.tagName.toLowerCase()}.${[...element.classList].join('.')} ${element.scrollWidth}>${element.clientWidth}`));
        expect(spilling, path).toEqual([]);
      }
    });
  }
}

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  for (const path of sectionPages) {
    test(`${path}: the tables, samples and navigation are present and readable`, async ({ page }) => {
      await page.goto(path);
      await expect(page.locator('main h1')).toBeVisible();
      await expect(page.locator('.ds-opening')).toBeVisible();
      await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toBeVisible();
      const links = sectionNavigation(page).getByRole('link');
      await expect(links).toHaveCount(sectionPages.length + 1);
      for (const link of await links.all()) await expect(link).toBeVisible();

      for (const table of await page.locator('main table').all()) {
        const region = table.locator('xpath=ancestor::*[@role="region"][1]');
        await expect(region).toHaveAttribute('tabindex', '0');
        await expect(region).toHaveAttribute('aria-label', /\S/);
        await expect(table.locator('tr').first()).toBeVisible();
      }
      for (const image of await page.locator('main img').all()) {
        await image.scrollIntoViewIfNeeded();
        await expect(image).toBeVisible();
        await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
      }

      // Every text and every swatch paints with a size and a color; a closed disclosure shows only its summary.
      const unreadable = await page.locator('main').evaluate((main) => {
        const hidden = (element: Element) => element.closest('.visually-hidden, [hidden], script, style') !== null
          || [...main.querySelectorAll('details:not([open])')].some((details) => details.contains(element) && !details.querySelector('summary')!.contains(element));
        const painted = (color: string) => !/^rgba\(.*,\s*0\)$/.test(color) && color !== 'transparent';
        const problems: string[] = [];
        const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
          const element = node.parentElement!;
          const text = node.textContent!.trim();
          if (text === '' || hidden(element)) continue;
          const range = document.createRange();
          range.selectNodeContents(node);
          const box = range.getBoundingClientRect();
          if (!element.checkVisibility({ opacityProperty: true, visibilityProperty: true }) || box.width === 0 || box.height === 0
            || !painted(getComputedStyle(element).color)) problems.push(`text "${text.slice(0, 60)}"`);
        }
        for (const swatch of main.querySelectorAll('.ds-swatch')) {
          const box = swatch.getBoundingClientRect();
          const code = swatch.nextElementSibling?.textContent ?? '';
          if (box.width === 0 || box.height === 0 || !painted(getComputedStyle(swatch).backgroundColor) || !/^#[0-9a-f]{6}$/i.test(code)) {
            problems.push(`swatch beside "${code}"`);
          }
        }
        return problems;
      });
      expect(unreadable).toEqual([]);
    });
  }
});
