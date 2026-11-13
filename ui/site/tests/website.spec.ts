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
      for (const path of ['/', '/docs/operator-guide/getting-started/', '/docs/developer-guide/development/database-naming/']) {
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.locator('main h1')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      }
    });
  }
}

test('pages set their text in the self-hosted Geist faces', async ({ page }) => {
  const typography = async (selector: string) => {
    await page.evaluate(() => document.fonts.ready);
    return page.evaluate((selector) => ({
      family: getComputedStyle(document.querySelector(selector)!).fontFamily,
      loaded: [...document.fonts].filter((face) => face.status === 'loaded').map((face) => face.family.replaceAll('"', '')),
    }), selector);
  };
  await page.goto('/');
  const home = await typography('body');
  expect(home.family).toMatch(/^"Geist Variable",/);
  expect(home.loaded).toContain('Geist Variable');
  await page.goto('/docs/developer-guide/testing/');
  const code = await typography('.manual-article code');
  expect(code.family).toMatch(/^"Geist Mono Variable",/);
  expect(code.loaded).toContain('Geist Mono Variable');
  await page.goto('/bac/');
  const codec = await typography('.bac-method code');
  expect(codec.family, 'Code outside the manual uses the mono stack too').toMatch(/^"Geist Mono Variable",/);
  expect(codec.loaded).toContain('Geist Mono Variable');
});

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
  await chapters.getByRole('link', { name: 'Source Installation', exact: true }).click();
  await expect(page.locator('main h1')).toContainText('Source Installation');
  const section = page.locator('main h2[id]').first();
  const id = await section.getAttribute('id');
  await page.goto(`/docs/operator-guide/getting-started/installation/#${id}`);
  await expect(section).toBeInViewport();
});

test('chapters fold out as collapsible categories in the manual navigation', async ({ page }) => {
  const chapters = page.getByRole('navigation', { name: 'Manual chapters' });
  const fold = chapters.locator('.chapter-fold details').filter({ has: page.locator('summary', { hasText: 'Bounded Contexts' }) });
  const testingFold = chapters.locator('.chapter-fold details').filter({ has: page.locator('summary', { hasText: 'Testing' }) });
  const archFold = chapters.locator('.chapter-fold details').filter({ has: page.locator('summary', { hasText: 'System Architecture' }) });
  await page.goto('/docs/developer-guide/contexts/auth/');
  await expect(fold).toHaveAttribute('open', '');
  await expect(testingFold).not.toHaveAttribute('open', '');
  await expect(archFold).not.toHaveAttribute('open', '');
  await expect(fold.getByRole('link', { name: 'Auth', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(fold.getByRole('link', { name: 'Overview', exact: true })).toHaveAttribute('href', '/docs/developer-guide/contexts/');
  expect(await fold.getByRole('link').count()).toBe(23);
  await page.goto('/docs/developer-guide/testing/');
  await expect(fold).not.toHaveAttribute('open', '');
  await expect(testingFold).toHaveAttribute('open', '');
  await expect(testingFold.getByRole('link', { name: 'Overview', exact: true })).toHaveAttribute('aria-current', 'page');
  expect(await testingFold.getByRole('link').count()).toBe(10);
  await page.goto('/docs/developer-guide/architecture/overview/');
  await expect(fold).not.toHaveAttribute('open', '');
  await expect(testingFold).not.toHaveAttribute('open', '');
  await expect(archFold).toHaveAttribute('open', '');
  await expect(archFold.getByRole('link', { name: 'Architecture Overview', exact: true })).toHaveAttribute('aria-current', 'page');
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
  expect(result.locations).toEqual(expect.arrayContaining(['https://baander.app/', 'https://baander.app/bac/', 'https://baander.app/typography/', 'https://baander.app/docs/developer-guide/contexts/']));
  expect(result.locations).not.toContain('https://baander.app/baac/');
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
      page.getByRole('navigation', { name: 'Manual chapters' }).getByRole('link', { name: 'Source Installation', exact: true }),
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
  await page.getByRole('navigation', { name: 'Manual chapters', exact: true }).getByRole('link', { name: "Part I — Operator's Guide", exact: true }).click();
  await expect(page.locator('main h1')).toContainText("Operator's Guide");
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
    await expect(page.getByRole('searchbox', { name: 'Search manuals' })).toBeDisabled();
    await expect(page.locator('#manual-search-status')).toBeHidden();
    await expect(page.locator('#manual-search noscript')).toBeVisible();
    expect(await page.locator('#manual-search noscript').evaluate((element) => element.textContent)).toContain('Search needs JavaScript.');
    await page.getByRole('navigation', { name: 'Manual chapters', exact: true }).getByRole('link', { name: 'Source Installation', exact: true }).click();
    await expect(page.locator('main h1')).toContainText('Source Installation');
  } finally {
    await context.close();
  }
});

test('the codec page is not served at its address from before the rename', async ({ page }) => {
  for (const path of ['/baac', '/baac/']) {
    const response = await page.goto(path);
    expect(response?.status(), path).toBe(404);
    await expect(page.getByRole('heading', { name: /not found/i })).toBeVisible();
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

/** The families the site bundles, as the font files name themselves; a variable font may add its default instance. */
const BUNDLED_FAMILY = /^(?:Geist|Geist Mono|Noto Sans(?: Mono| Thai| Myanmar| SC| TC| JP| KR)?)(?: Thin)?$/;
const HAN_FAMILY = /^Noto Sans (?:SC|TC|JP|KR) Variable$/;
/** The site-wide Geist faces declare no unicode-range; their files hold Fontsource's Latin slice. */
const SITE_WIDE_LATIN = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';

/**
 * Checks the text below the root against the page's declared faces. Each character must fall, along its
 * element's computed family order, to a family with a declared face whose unicode-range covers it, and
 * for rendered text that face must be loaded. A plain `document.fonts.check()` passes when no face
 * covers the character, so the covering family is found first. The faces reached this way are the ones
 * the page's own font stylesheet may declare and the page may request.
 */
async function fontCoverage(page: import('@playwright/test').Page, root: string) {
  return page.evaluate(async ({ root, siteWideLatin }) => {
    for (const details of document.querySelectorAll('details')) details.open = true;
    await new Promise(requestAnimationFrame);
    await document.fonts.ready;
    const ranges = (value: string) => value.split(',').map((part) => {
      const [start, end = start] = part.trim().replace(/^u\+/i, '').split('-');
      return [Number.parseInt(start, 16), Number.parseInt(end, 16)] as const;
    });
    // The metric-matched fallback faces name only installed fonts, so they are not bundled faces
    // and never satisfy a character; only faces that load a bundled file take part.
    const faces = [...document.styleSheets].flatMap((sheet) => [...sheet.cssRules])
      .filter((rule): rule is CSSFontFaceRule => rule instanceof CSSFontFaceRule)
      .flatMap((rule) => {
        const file = /url\("?([^")]+)"?\)/.exec(rule.style.getPropertyValue('src'));
        if (file === null) return [];
        const range = rule.style.getPropertyValue('unicode-range');
        return [{
          family: rule.style.getPropertyValue('font-family').replace(/^"|"$/g, ''),
          file: new URL(file[1], location.href).pathname,
          pageFont: /\/assets\/site\/fonts\/page-[0-9a-f]{16}\.css$/.test(rule.parentStyleSheet?.href ?? ''),
          ranges: ranges(range === '' || /^U\+0-10FFFF$/i.test(range) ? siteWideLatin : range),
        }];
      });
    const reached = new Set<string>();
    const missing = new Set<string>();
    const reach = (stack: string[], character: string) => {
      const code = character.codePointAt(0)!;
      const covering = (name: string) => faces.filter((face) => face.family === name
        && face.ranges.some(([start, end]) => code >= start && code <= end));
      const family = stack.find((name) => covering(name).length > 0);
      for (const face of family === undefined ? [] : covering(family)) reached.add(face.file);
      return family;
    };
    const walker = document.createTreeWalker(document.querySelector(root)!, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
      const element = node.parentElement!;
      if (element.closest('script, style')) continue;
      const style = getComputedStyle(element);
      const stack = style.fontFamily.split(',').map((family) => family.trim().replace(/^"|"$/g, ''));
      const text = node.textContent!.replace(/\s/g, '');
      for (const character of new Set(text)) reach(stack, character);
      const shown = style.textTransform === 'uppercase' ? text.toUpperCase() : text;
      const rendered = element.checkVisibility({ visibilityProperty: true });
      for (const character of new Set(shown)) {
        const family = reach(stack, character);
        if (family === undefined) {
          missing.add(`${character} in "${node.textContent!.trim().slice(0, 60)}" has no bundled face`);
        } else if (rendered && !document.fonts.check(`${style.fontStyle} ${style.fontWeight} ${style.fontSize} "${family}"`, character)) {
          missing.add(`${character} in ${family} is not loaded`);
        }
      }
    }
    const requested = performance.getEntriesByType('resource').map((entry) => new URL(entry.name).pathname)
      .filter((file) => file.endsWith('.woff2'));
    const declared = faces.filter((face) => face.pageFont).map((face) => face.file);
    return {
      missing: [...missing],
      declared,
      declaredUnreached: [...new Set(declared)].filter((file) => !reached.has(file)),
      requestedUnreached: [...new Set(requested)].filter((file) => !reached.has(file)),
    };
  }, { root, siteWideLatin: SITE_WIDE_LATIN });
}

async function openTypography(page: import('@playwright/test').Page) {
  await page.goto('/typography/');
  await expect(page.getByRole('heading', { level: 1, name: 'Typography' })).toBeVisible();
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
}

/** The computed family order of an element, without quotes. */
function families(page: import('@playwright/test').Page, selector: string): Promise<string[]> {
  return page.locator(selector).evaluate((element) => getComputedStyle(element).fontFamily
    .split(',').map((family) => family.trim().replace(/^"|"$/g, '')));
}

function spacing(page: import('@playwright/test').Page, selector: string) {
  return page.locator(selector).first().evaluate((element) => {
    const style = getComputedStyle(element);
    return {
      letterSpacing: style.letterSpacing === 'normal' ? 0 : Number.parseFloat(style.letterSpacing),
      fontSize: Number.parseFloat(style.fontSize),
      lineHeight: Number.parseFloat(style.lineHeight),
      numerals: style.fontVariantNumeric,
      family: style.fontFamily,
    };
  });
}

test('visitors reach the typography page from the header and it describes itself', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Typography', exact: true }).click();
  await expect(page).toHaveURL(/\/typography\/$/);
  await expect(page).toHaveTitle(/^Typography/);
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Typography', exact: true }))
    .toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Audio Core', exact: true }))
    .not.toHaveAttribute('aria-current');
  await expect(page.getByRole('navigation', { name: 'Footer navigation' }).getByRole('link', { name: 'Typography', exact: true }))
    .toHaveAttribute('href', '/typography/');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://baander.app/typography/');
  await expect(page.locator('meta[property="og:url"]')).toHaveAttribute('content', 'https://baander.app/typography/');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /Thai, Burmese, Korean, Chinese and Japanese/);
  for (const section of ['Scripts', 'Glyph forms', 'Script rules', 'Type scale']) {
    await expect(page.getByRole('navigation', { name: 'On this page' }).getByRole('link', { name: section, exact: true })).toBeVisible();
  }
  await expect(page.locator('.typography-specimen h3')).toHaveText([
    'Latin and Vietnamese', 'Cyrillic', 'Thai', 'Burmese', 'Korean', 'Simplified Chinese', 'Traditional Chinese', 'Japanese',
  ]);
  await expect(page.locator('.typography-role')).toHaveCount(10);
});

test('the glyph-form columns put their own Han family first', async ({ page }) => {
  await openTypography(page);
  const firstHan = async (lang: string) => (await families(page, `#glyph-forms [lang="${lang}"]`)).find((family) => HAN_FAMILY.test(family));
  await expect(page.locator('#glyph-forms [lang="ja"]')).toHaveText('直 骨 与');
  expect(await firstHan('ja')).toBe('Noto Sans JP Variable');
  expect(await firstHan('zh-Hans')).toBe('Noto Sans SC Variable');
  expect(await firstHan('zh-Hant')).toBe('Noto Sans TC Variable');
});

test('each script rule sample differs from its sample without the rule', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openTypography(page);
  const rule = (id: string, variant: 'with' | 'without', lang: string) => spacing(page, `#rule-${id} [data-variant="${variant}"] [lang="${lang}"]`);

  const trackedThai = await rule('tracking', 'without', 'th');
  expect(trackedThai.letterSpacing).toBeCloseTo(0.12 * trackedThai.fontSize, 2);
  for (const lang of ['th', 'my', 'ko', 'ja']) expect((await rule('tracking', 'with', lang)).letterSpacing, lang).toBe(0);

  for (const [lang, minimum] of [['th', 1.8], ['my', 2], ['ja', 1.6]] as const) {
    const without = await rule('line-height', 'without', lang);
    const withRule = await rule('line-height', 'with', lang);
    expect(without.lineHeight / without.fontSize, `${lang} without the rule`).toBeCloseTo(1.5, 2);
    expect(withRule.lineHeight / withRule.fontSize, `${lang} with the rule`).toBeCloseTo(minimum, 2);
  }

  const sizes = await page.locator('#rule-size .type').evaluateAll((samples) => samples.map((sample) => Number.parseFloat(getComputedStyle(sample).fontSize)));
  expect(sizes, 'Wholly Burmese text gains 2px; the mixed string keeps the body size').toEqual([15, 15, 17, 15]);

  const tabular = await rule('numerals', 'with', 'en');
  expect(tabular.numerals).toBe('tabular-nums');
  expect(tabular.family).toMatch(/^"Geist Mono Variable",/);
  const proportional = await rule('numerals', 'without', 'en');
  expect(proportional.numerals).toBe('proportional-nums');
  expect(proportional.family).toMatch(/^"Geist Variable",/);
});

for (const path of ['/', '/docs/developer-guide/testing/', '/bac/']) {
  test(`${path}: every character renders from a bundled face and only the slices the text reaches load`, async ({ page }) => {
    await page.goto(path);
    await expect(page.locator('main h1')).toBeVisible();
    const report = await fontCoverage(page, 'body');
    expect(report.missing, 'Each character falls to a declared bundled face that is loaded').toEqual([]);
    expect(report.declaredUnreached, 'The page declares no slice its text does not reach first').toEqual([]);
    expect(report.requestedUnreached, 'The page requests no slice its text does not reach first').toEqual([]);
    expect(report.declared.length, 'The page has text beyond the shared Latin faces').toBeGreaterThan(0);
  });
}

test('search results render characters from other pages in bundled faces once results appear', async ({ page }) => {
  const stylesheets: string[] = [];
  page.on('request', (request) => {
    if (/^\/assets\/site\/fonts\/search-[0-9a-f]{16}\.css$/.test(new URL(request.url()).pathname)) stylesheets.push(request.url());
  });
  await page.goto('/docs/');
  expect(await page.locator('body').textContent(), 'The arrow comes only from another page').not.toContain('→');
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
  expect(stylesheets, 'The search stylesheet loads only after a search').toEqual([]);
  await page.getByRole('searchbox', { name: 'Search manuals' }).fill('dependency flow');
  const excerpt = page.locator('#manual-search-results .search-excerpt', { hasText: '→' }).first();
  await expect(excerpt).toBeVisible();
  expect(stylesheets).toHaveLength(1);
  await expect.poll(async () => (await fontCoverage(page, '#manual-search-results')).missing).toEqual([]);
});

test('every character on the typography page renders from a bundled Geist or Noto face', async ({ page }) => {
  await openTypography(page);
  await page.evaluate(async () => {
    for (const section of document.querySelectorAll('.typography-section')) {
      section.scrollIntoView();
      await new Promise(requestAnimationFrame);
    }
    await document.fonts.ready;
  });

  const session = await page.context().newCDPSession(page);
  await session.send('DOM.enable');
  await session.send('CSS.enable');
  const { root } = await session.send('DOM.getDocument', { depth: -1 });
  const { nodeIds } = await session.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: 'body, body *' });
  const used: { familyName: string; isCustomFont: boolean }[] = [];
  for (const nodeId of nodeIds) {
    const { fonts } = await session.send('CSS.getPlatformFontsForNode', { nodeId });
    used.push(...fonts.map(({ familyName, isCustomFont }) => ({ familyName, isCustomFont })));
  }
  expect(new Set(used.map(({ familyName }) => familyName.replace(/ Thin$/, '')))).toEqual(new Set([
    'Geist', 'Geist Mono', 'Noto Sans Thai', 'Noto Sans Myanmar', 'Noto Sans SC', 'Noto Sans TC', 'Noto Sans JP', 'Noto Sans KR',
  ]));
  expect(used.filter(({ familyName, isCustomFont }) => !isCustomFont || !BUNDLED_FAMILY.test(familyName))).toEqual([]);

  // Each specimen character falls to a declared face in its element's own family order, and that face
  // is loaded at the element's weight and size.
  const missing = await page.evaluate((siteWideLatin) => {
    const ranges = (value: string) => value.split(',').map((part) => {
      const [start, end = start] = part.trim().replace(/^u\+/i, '').split('-');
      return [Number.parseInt(start, 16), Number.parseInt(end, 16)] as const;
    });
    const faces = [...document.fonts].map((face) => ({
      family: face.family.replace(/^"|"$/g, ''),
      ranges: ranges(/^U\+0-10FFFF$/i.test(face.unicodeRange) ? siteWideLatin : face.unicodeRange),
    }));
    const samples = [...document.querySelectorAll('.typography-page .type, .typography-glyphs')];
    return samples.flatMap((element) => {
      const style = getComputedStyle(element);
      const stack = style.fontFamily.split(',').map((family) => family.trim().replace(/^"|"$/g, ''));
      const text = style.textTransform === 'uppercase' ? element.textContent!.toUpperCase() : element.textContent!;
      return [...new Set(text.replace(/\s/g, ''))].flatMap((character) => {
        const code = character.codePointAt(0)!;
        const family = stack.find((name) => faces.some((face) => face.family === name
          && face.ranges.some(([start, end]) => code >= start && code <= end)));
        if (family === undefined) return [`${character} in "${element.textContent}" has no bundled face`];
        return document.fonts.check(`${style.fontStyle} ${style.fontWeight} ${style.fontSize} "${family}"`, character)
          ? []
          : [`${character} in ${family} is not loaded`];
      });
    });
  }, SITE_WIDE_LATIN);
  expect(missing).toEqual([]);
});

for (const width of [1280, 390]) {
  for (const colorScheme of ['light', 'dark'] as const) {
    test(`${width}px ${colorScheme}: the typography page stays within the viewport`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.emulateMedia({ colorScheme });
      await openTypography(page);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      const overflowing = await page.locator('.typography-page section, .typography-page section *').evaluateAll((elements) => elements
        .filter((element) => element.scrollWidth > element.clientWidth + 1 && getComputedStyle(element).display !== 'inline')
        .map((element) => element.className || element.tagName));
      expect(overflowing, 'No section or sample scrolls horizontally').toEqual([]);
      const screenshot = test.info().outputPath(`typography-${width}-${colorScheme}.png`);
      await page.screenshot({ path: screenshot, fullPage: true });
      await test.info().attach(`typography-${width}-${colorScheme}`, { path: screenshot, contentType: 'image/png' });
    });
  }
}
