import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test as base, expect, type Page } from '@playwright/test';
import { openMenuPanel } from './site-menu';

// Checks every page of the design system section shares: the menu entry, the section navigation,
// the breadcrumb and the page's own metadata. Each page's content has a spec of its own.

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

test('the sitemap lists the overview and nine pages below it', () => {
  expect(sectionPages).toContain('/design-system/');
  expect(sectionPages).toHaveLength(10);
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
