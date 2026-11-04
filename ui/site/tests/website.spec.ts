import { test as base, expect } from '@playwright/test';

const test = base.extend<{ outbound: string[] }>({
  outbound: [async ({ context, baseURL }, use) => {
    const outbound: string[] = [];
    await context.route('**/*', async (route) => {
      const url = route.request().url();
      if (new URL(url).origin !== new URL(baseURL!).origin) {
        outbound.push(url);
        await route.abort();
      } else {
        await route.continue();
      }
    });
    await use(outbound);
    expect(outbound, 'The static site must load without external services').toEqual([]);
  }, { auto: true }],
});

for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
  for (const colorScheme of ['light', 'dark'] as const) {
    test(`${viewport.width}px ${colorScheme}: homepage and wide manual remain contained`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.emulateMedia({ colorScheme });
      for (const path of ['/', '/docs/operator-guide/getting-started/', '/docs/developer-guide/database-naming/']) {
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.locator('main h1')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      }
    });
  }
}

test('visitor reaches operating model and source-based installation', async ({ page }) => {
  await page.goto('/');
  const screenshot = page.getByRole('img', { name: /music library/ });
  await expect(screenshot).toBeVisible();
  expect(await screenshot.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
  await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'How it works' }).click();
  await expect(page).toHaveURL(/#how-it-works$/);
  await expect(page.getByRole('heading', { name: 'Your files. A connected library.' })).toBeInViewport();
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: /Source/ }))
    .toHaveAttribute('href', 'https://github.com/baander-app/baander');
  await expect(page.getByRole('navigation', { name: 'Footer navigation' }).getByRole('link', { name: 'Source', exact: true }))
    .toHaveAttribute('href', 'https://github.com/baander-app/baander');
  await page.getByRole('link', { name: /Start hosting/ }).click();
  await expect(page).toHaveURL(/\/docs\/operator-guide\/getting-started\/$/);
  await expect(page.locator('main')).toContainText(/from source/);
  await expect(page.locator('main')).toContainText(/development-oriented/);
  await expect(page.locator('main')).toContainText(/workers/);
  const chapters = page.getByRole('navigation', { name: 'Manual chapters', exact: true });
  await chapters.getByRole('link', { name: 'Library Management', exact: true }).click();
  await expect(page.locator('main h1')).toContainText('Library Management');
  const section = page.locator('main h2[id]').first();
  const id = await section.getAttribute('id');
  await page.goto(`/docs/operator-guide/library-management/#${id}`);
  await expect(section).toBeInViewport();
});

test('bounded contexts fold out as their own chapter in the developer navigation', async ({ page }) => {
  const chapters = page.getByRole('navigation', { name: 'Manual chapters' });
  const fold = chapters.locator('.chapter-fold details');
  await page.goto('/docs/developer-guide/contexts/auth/');
  await expect(fold).toHaveAttribute('open', '');
  await expect(fold.getByRole('link', { name: 'Auth', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(fold.getByRole('link', { name: 'Overview', exact: true })).toHaveAttribute('href', '/docs/developer-guide/contexts/');
  expect(await fold.getByRole('link').count()).toBe(23);
  await page.goto('/docs/developer-guide/testing/');
  await expect(fold).not.toHaveAttribute('open', '');
  await expect(chapters.getByRole('link', { name: 'Testing', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(chapters.locator('.chapter-group > ul > li > a[href^="/docs/developer-guide/contexts/"]')).toHaveCount(0);
  const summary = fold.locator('summary');
  await summary.focus();
  await summary.press('Enter');
  await expect(fold).toHaveAttribute('open', '');
  await fold.getByRole('link', { name: 'Catalog', exact: true }).click();
  await expect(page).toHaveURL(/\/docs\/developer-guide\/contexts\/catalog\/$/);
  await expect(fold).toHaveAttribute('open', '');
});

test('keyboard focus and enlarged text preserve access to chapters and search', async ({ page }) => {
  await page.goto('/docs/operator-guide/getting-started/');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
  expect(await page.locator(':focus').evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
  const search = page.getByRole('searchbox', { name: 'Search manuals' });
  for (let step = 0; step < 12 && !await search.evaluate((element) => element === document.activeElement); step++) {
    await page.keyboard.press('Tab');
  }
  await expect(search).toBeFocused();
  await page.setViewportSize({ width: 720, height: 900 });
  await page.addStyleTag({ content: 'html { font-size: 200%; }' });
  await expect(search).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
});

test('real Pagefind results include shareable sections and keyboard navigation', async ({ page }) => {
  await page.goto('/docs/');
  const input = page.getByRole('searchbox', { name: 'Search manuals' });
  await input.fill('scanning');
  const results = page.locator('#manual-search-results');
  await expect(results).toBeVisible();
  await expect(results).toContainText(/library/i);
  const section = results.locator('a.search-section[href*="#"]').first();
  await expect(section).toBeVisible();
  await input.press('ArrowDown');
  await expect(results.locator('a').first()).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(input).toBeFocused();
  const href = await section.getAttribute('href');
  await section.click();
  await expect(page).toHaveURL(new RegExp(`${href!.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
  const fragment = decodeURIComponent(new URL(page.url()).hash.slice(1));
  await expect(page.locator(`[id="${fragment}"]`)).toBeInViewport();
});

test('unmatched and empty queries have distinct states', async ({ page }) => {
  await page.goto('/docs/');
  const input = page.getByRole('searchbox', { name: 'Search manuals' });
  await input.fill('xylophone cardboard');
  await expect(page.getByRole('status')).toContainText('No matches');
  await expect(page.locator('#manual-search-results')).toBeHidden();
  await input.fill('');
  await expect(page.getByRole('status')).toHaveText('Search the operator and developer guides.');
  await expect(page.locator('#manual-search-results')).toHaveAttribute('aria-busy', 'false');
});

test('unavailable search leaves chapter navigation usable', async ({ page }) => {
  await page.route('**/pagefind/pagefind.js', (route) => route.abort());
  await page.goto('/docs/');
  await page.getByRole('searchbox', { name: 'Search manuals' }).fill('scanning');
  await expect(page.getByRole('status')).toContainText('Search is unavailable');
  await page.getByRole('navigation', { name: 'Manual chapters', exact: true }).getByRole('link', { name: 'Library Management', exact: true }).click();
  await expect(page.locator('main h1')).toContainText('Library Management');
});

test('late real index load cannot restore results after query is cleared', async ({ page }) => {
  let release!: () => void;
  let intercepted!: () => void;
  const waiting = new Promise<void>((resolve) => { intercepted = resolve; });
  const gate = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/pagefind/pagefind.js', async (route) => {
    intercepted();
    await gate;
    await route.continue();
  });
  await page.goto('/docs/');
  const input = page.getByRole('searchbox', { name: 'Search manuals' });
  await input.fill('scanning');
  await waiting;
  await input.fill('');
  const loaded = page.waitForResponse('**/pagefind/pagefind.js');
  release();
  await loaded;
  await expect(page.getByRole('status')).toHaveText('Search the operator and developer guides.');
  await expect(page.locator('#manual-search-results')).toBeHidden();
  await input.fill('scanning');
  await expect(page.locator('#manual-search-results')).toBeVisible();
});

test('manual remains readable and navigable with JavaScript disabled', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL });
  await context.route('**/*', (route) => new URL(route.request().url()).origin === new URL(baseURL!).origin ? route.continue() : route.abort());
  try {
    const page = await context.newPage();
    await page.goto('/docs/operator-guide/getting-started/');
    await expect(page.locator('main h1')).toBeVisible();
    await expect(page.locator('#manual-search noscript')).toBeVisible();
    expect(await page.locator('#manual-search noscript').evaluate((element) => element.textContent)).toContain('Search needs JavaScript.');
    await page.getByRole('navigation', { name: 'Manual chapters', exact: true }).getByRole('link', { name: 'Library Management', exact: true }).click();
    await expect(page.locator('main h1')).toContainText('Library Management');
  } finally {
    await context.close();
  }
});

test('unknown and private paths return 404; chapter URLs canonicalize', async ({ page }) => {
  for (const path of ['/not-a-public-page/', '/.env', '/.vercel/output/config.json', '/scripts/build-website.php', '/docs/../.git/config']) {
    const response = await page.goto(path);
    expect(response?.status(), path).toBe(404);
    await expect(page.getByRole('heading', { name: /not found/i })).toBeVisible();
  }
  for (const path of ['/docs/operator-guide/getting-started', '/docs/operator-guide/getting-started/index.html']) {
    await page.goto(path);
    await expect(page).toHaveURL(/\/docs\/operator-guide\/getting-started\/$/);
  }
});
