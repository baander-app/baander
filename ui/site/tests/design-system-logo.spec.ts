import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test as base, expect, type Locator, type Page } from '@playwright/test';

// The logo page: every mark loads and renders at the sizes its file names, the page says where each
// file is used as the repository uses it, and the light or dark version a mark paints inside an image
// follows what the page states.

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
    expect(outbound, 'The logo page must load without external services').toEqual([]);
  }, { auto: true }],
});

const repository = (path: string) => readFileSync(fileURLToPath(new URL(`../../../${path}`, import.meta.url)), 'utf8');
const marks = ['logo.svg', 'icon.svg', 'favicon.svg'];
/** The sizes a mark's opening comment names, largest first. */
const statedSizes = (file: string) => {
  const range = repository(`ui/web/public/${file}`).match(/<!--[^>]*?(\d+)(?:-(\d+))?px/)!;
  return [...new Set(range.slice(1).filter(Boolean).map(Number))].sort((first, second) => second - first);
};

/** The color an element paints at a point given as a fraction of its box, read from a screenshot. */
async function paintedAt(page: Page, element: Locator, x: number, y: number): Promise<[number, number, number]> {
  const shot = (await element.screenshot({ animations: 'disabled' })).toString('base64');
  return page.evaluate(async ({ shot, x, y }) => {
    const image = new Image();
    image.src = `data:image/png;base64,${shot}`;
    await image.decode();
    const canvas = new OffscreenCanvas(image.width, image.height);
    const context = canvas.getContext('2d')!;
    context.drawImage(image, 0, 0);
    const [red, green, blue] = context.getImageData(Math.floor(image.width * x), Math.floor(image.height * y), 1, 1).data;
    return [red, green, blue] as [number, number, number];
  }, { shot, x, y });
}

/**
 * Which version of a cassette mark an image paints. A quarter of the way down its middle lies the
 * cassette's body, blue in the light version and near-white in the dark one.
 */
async function version(page: Page, image: Locator): Promise<'light' | 'dark' | string> {
  const [red, green, blue] = await paintedAt(page, image, 0.5, 0.25);
  if (Math.min(red, green, blue) > 200) return 'dark';
  if (blue - red > 100) return 'light';
  return `rgb(${red}, ${green}, ${blue})`;
}

const openIn = async (page: Page, system: 'light' | 'dark', site: 'light' | 'dark') => {
  await page.emulateMedia({ colorScheme: system });
  await page.addInitScript((choice) => localStorage.setItem('baander-site-appearance', choice), site);
  await page.goto('/design-system/logo/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', site === 'light' ? 'balanced' : 'dark');
};

for (const width of [1440, 390]) {
  test(`at ${width} px every mark loads and renders at each size its file names, largest first`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/design-system/logo/');
    for (const file of marks) {
      const figure = page.locator('.ds-logo-mark', { has: page.getByRole('heading', { level: 3, name: file, exact: true }) }).locator('figure');
      const sizes = statedSizes(file);
      await expect(figure.locator('img')).toHaveCount(sizes.length);
      await expect(figure.locator('.ds-logo-label')).toHaveText(sizes.map((size) => `${size} px`));
      await expect(figure.locator('figcaption')).toHaveText(repository(`ui/web/public/${file}`).match(/<!--\s*(.*?)\s*-->/)![1]);
      for (const [position, size] of sizes.entries()) {
        const image = figure.locator('img').nth(position);
        await expect(image).toHaveAttribute('src', `/assets/site/${file}`);
        await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0), `${file} at ${size} px loads`).toBe(true);
        const box = (await image.boundingBox())!;
        expect([box.width, box.height], `${file} at ${size} px`).toEqual([size, size]);
      }
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth), 'the page does not scroll sideways').toBeLessThanOrEqual(width);
  });
}

test('the page says where each file is used, as the repository uses it', async ({ page }) => {
  const uses: Record<string, string[]> = {
    'logo.svg': [
      'ui/web/src/features/layout/components/Sidebar.tsx',
      'ui/web/src/features/admin/components/layout/AdminSidebar.tsx',
      'ui/web/src/features/auth/pages/LoginPage.tsx',
      'ui/site/templates/header.php',
      'ui/site/templates/footer.php',
      'ui/site/scripts/social-card.mjs',
    ],
    'favicon.svg': ['ui/site/templates/head.php'],
  };
  for (const [file, users] of Object.entries(uses)) for (const user of users) expect(repository(user), `${user} uses ${file}`).toContain(`${file}"`);
  expect(repository('docs-book/part-2-developer-guide/development/frontend-development.md')).toMatch(/`ui\/electron\/build\/app-icons\/icon\.png`, rendered from the web app's\s+`icon\.svg`/);
  expect(repository('ui/electron/electron-builder.yml')).toContain('icon: build/app-icons/icon.png');

  await page.goto('/design-system/logo/');
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', '/assets/site/favicon.svg');
  const said = (file: string) => page.locator('.ds-logo-mark', { has: page.getByRole('heading', { level: 3, name: file, exact: true }) }).locator('p');
  await expect(said('logo.svg')).toHaveText(/its sidebar, the admin sidebar and the sign-in page\. This website shows it in the header, the footer and the social card/);
  await expect(said('icon.svg')).toContainText('ui/electron/build/app-icons/icon.png, is rendered from it');
  await expect(said('favicon.svg')).toContainText('site icon');
});

test('the page names the marks that draw a dark version, as their files do', async ({ page }) => {
  await page.goto('/design-system/logo/');
  const adapting = marks.filter((file) => /@media\s*\(\s*prefers-color-scheme:\s*dark\s*\)/.test(repository(`ui/web/public/${file}`)));
  expect(adapting).toEqual(['logo.svg', 'icon.svg']);
  const statement = page.locator('section[aria-labelledby="light-and-dark"] p').first();
  await expect(statement).toContainText(`${adapting.join(' and ')} draw a second version for dark color schemes`);
  await expect(statement).toContainText('favicon.svg has one version for both');
});

// A mark inside <img> is an SVG document of its own; its prefers-color-scheme query can follow either
// the operating system or the color scheme of the page that embeds it. The site's themes set that
// color scheme, so this records which of the two wins and holds the page's statement to it.
for (const [system, site] of [['dark', 'light'], ['light', 'dark']] as const) {
  test(`with the system preferring ${system} and the site toggled ${site}, a mark paints the version the page states`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openIn(page, system, site);
    const images = {
      'the logo page’s logo.svg': page.locator('.ds-logo-figure img[src$="/logo.svg"]').first(),
      'the logo page’s icon.svg': page.locator('.ds-logo-figure img[src$="/icon.svg"]').first(),
      'the header’s logo': page.locator('header .brand img'),
    };
    const painted: Record<string, string> = {};
    for (const [name, image] of Object.entries(images)) {
      await image.scrollIntoViewIfNeeded();
      painted[name] = await version(page, image);
    }
    test.info().annotations.push({ type: 'painted version', description: `system ${system}, site ${site}: ${JSON.stringify(painted)}` });
    for (const [name, found] of Object.entries(painted)) expect(found, `${name} follows the site's theme, not the system`).toBe(site);
    await expect(page.locator('section[aria-labelledby="light-and-dark"] p').last())
      .toHaveText('Shown as an image, a mark takes its color scheme from the page around it rather than from the operating system, so on this website it changes with the theme toggle in the header.');

    await page.locator('[data-appearance-toggle]').click();
    const other = site === 'light' ? 'dark' : 'light';
    await expect(page.locator('html')).toHaveAttribute('data-theme', other === 'light' ? 'balanced' : 'dark');
    await expect.poll(() => version(page, images['the header’s logo']), 'the header logo changes with the toggle').toBe(other);
  });
}
