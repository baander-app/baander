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
  const fold = chapters.locator('.chapter-fold details').filter({ has: page.locator('summary', { hasText: 'Bounded Contexts' }) });
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
  const sizes = await chapters.evaluate((nav) => {
    const size = (selector: string) => parseFloat(getComputedStyle(nav.querySelector(selector)!).fontSize);
    return { chapter: size('.chapter-group > ul > li > a'), fold: size('.chapter-fold summary'), page: size('.chapter-fold li a') };
  });
  expect(sizes.fold, 'A fold-out chapter matches its sibling chapters').toBe(sizes.chapter);
  expect(sizes.page, 'Pages inside a fold-out are not larger than the chapter line').toBeLessThanOrEqual(sizes.fold);
});

test('CLI commands fold out by section and the sidebar reveals the current command', async ({ page }) => {
  const chapters = page.getByRole('navigation', { name: 'Manual chapters' });
  const fold = chapters.locator('.chapter-fold details').filter({ has: page.locator('summary', { hasText: 'CLI Commands' }) });
  await page.goto('/docs/operator-guide/getting-started/');
  await expect(fold).not.toHaveAttribute('open', '');
  await page.goto('/docs/operator-guide/commands/app-user-enable/');
  await expect(fold).toHaveAttribute('open', '');
  await expect(fold.locator('.chapter-fold-label', { hasText: 'Auth & Users' })).toBeVisible();
  const current = fold.getByRole('link', { name: 'app:user:enable', exact: true });
  await expect(current).toHaveAttribute('aria-current', 'page');
  expect(await page.evaluate(() => scrollY), 'Revealing the link scrolls only the sidebar').toBe(0);
  await expect(current).toBeInViewport();
});

test('the sitemap lists every published page at its canonical URL and robots.txt points to it', async ({ page }) => {
  // Requests run inside the page because the routed test origin resolves only in the browser.
  await page.goto('/');
  const result = await page.evaluate(async () => {
    const sitemap = await fetch('/sitemap.xml');
    const body = await sitemap.text();
    const locations = [...body.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
    const lastModified = [...body.matchAll(/<lastmod>([^<]+)<\/lastmod>/g)].map((match) => match[1]);
    const pages = await Promise.all(locations.map(async (location) => {
      const response = await fetch(new URL(location).pathname, { redirect: 'manual' });
      const html = await response.text();
      const head = new DOMParser().parseFromString(html, 'text/html');
      return {
        location, status: response.status, canonical: html.includes(`<link rel="canonical" href="${location}">`),
        title: head.title, description: head.querySelector('meta[name="description"]')?.getAttribute('content') ?? '',
      };
    }));
    const robots = await fetch('/robots.txt');
    return {
      status: sitemap.status, type: sitemap.headers.get('content-type'), locations, lastModified, pages,
      robots: { status: robots.status, body: await robots.text() },
    };
  });
  expect(result.status).toBe(200);
  expect(result.type).toMatch(/xml/);
  expect(result.locations.length).toBeGreaterThan(200);
  expect(result.locations).toEqual(expect.arrayContaining(['https://baander.app/', 'https://baander.app/baac/', 'https://baander.app/docs/developer-guide/contexts/']));
  expect(result.locations.some((location) => location.endsWith('404.html'))).toBe(false);
  for (const value of result.lastModified) expect(Number.isNaN(Date.parse(value)), value).toBe(false);
  for (const listed of result.pages) expect(listed, listed.location).toMatchObject({ status: 200, canonical: true });
  const repeated = (values: string[]) => values.filter((value, index) => values.indexOf(value) !== index);
  expect(repeated(result.pages.map((listed) => listed.title)), 'Every listed page has its own title').toEqual([]);
  expect(repeated(result.pages.map((listed) => listed.description)), 'Every listed page has its own description').toEqual([]);
  for (const listed of result.pages) expect(listed.description.length, listed.location).toBeLessThanOrEqual(160);
  expect(result.robots.status).toBe(200);
  expect(result.robots.body).toContain('Sitemap: https://baander.app/sitemap.xml');
});

for (const width of [1440, 390]) {
  test(`${width}px: focus rings in the scrolling manual sidebar are not clipped`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/docs/operator-guide/getting-started/');
    const targets = [
      page.getByRole('searchbox', { name: 'Search manuals' }),
      page.getByRole('navigation', { name: 'Manual chapters' }).getByRole('link', { name: 'Getting Started', exact: true }),
    ];
    for (const target of targets) {
      await target.focus();
      const ring = await target.evaluate((element) => {
        const style = getComputedStyle(element);
        const reach = parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset);
        const box = element.getBoundingClientRect();
        const clip = element.closest('.chapter-sidebar')!.getBoundingClientRect();
        return { outline: style.outlineStyle, ringLeft: box.left - reach, clipLeft: clip.left };
      });
      expect(ring.outline).not.toBe('none');
      expect(ring.ringLeft, 'The focus ring starts inside the sidebar clip').toBeGreaterThanOrEqual(ring.clipLeft);
    }
  });
}

test('manual pages describe themselves to search engines and link previews', async ({ page }) => {
  await page.goto('/docs/operator-guide/commands/app-user-enable/');
  await expect(page).toHaveTitle('app:user:enable — CLI Commands — Bånder');
  const head = await page.evaluate(() => {
    const content = (selector: string) => document.querySelector(selector)?.getAttribute('content') ?? null;
    return {
      canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href'),
      ogUrl: content('meta[property="og:url"]'), ogTitle: content('meta[property="og:title"]'),
      ogImage: content('meta[property="og:image"]'), twitter: content('meta[name="twitter:card"]'),
      description: content('meta[name="description"]'),
      graph: JSON.parse(document.querySelector('script[type="application/ld+json"]')!.textContent!)['@graph'],
      crumbs: [...document.querySelectorAll('nav[aria-label="Breadcrumb"] a')].map((link) => link.textContent),
    };
  });
  expect(head.canonical).toBe('https://baander.app/docs/operator-guide/commands/app-user-enable/');
  expect(head.ogUrl).toBe(head.canonical);
  expect(head.ogTitle).toBe(await page.title());
  expect(head.twitter).toBe('summary_large_image');
  expect(head.description).toMatch(/^Enable a previously disabled user account/);
  expect(head.graph.map((node: { '@type': string }) => node['@type'])).toEqual(['TechArticle', 'BreadcrumbList']);
  const listed = head.graph[1].itemListElement.map((item: { name: string }) => item.name);
  expect(listed).toEqual([...head.crumbs, 'app:user:enable']);
  expect(head.crumbs).toEqual(['Bånder', 'Operator manual', 'CLI Commands']);
  const card = await page.evaluate(async (url) => {
    const image = new Image();
    image.src = new URL(url!).pathname;
    await image.decode();
    return [image.naturalWidth, image.naturalHeight];
  }, head.ogImage);
  expect(card).toEqual([1200, 630]);
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
