import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test as base, expect, type Page } from '@playwright/test';

// The website page: each departure it prints is what the site's pages compute, and the theme toggle
// moves between the two moods the page names.

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
    expect(outbound, 'The website page must load without external services').toEqual([]);
  }, { auto: true }],
});

const repository = (path: string) => readFileSync(fileURLToPath(new URL(`../../../${path}`, import.meta.url)), 'utf8');
const moods: Record<string, string> = JSON.parse(repository('ui/design-tokens/tokens/web/semantic-colors.json')).moods;
const moodByLabel = Object.fromEntries(Object.entries(moods).map(([mood, label]) => [label, mood]));

const departures = (page: Page) => page.getByRole('region', { name: 'Where the website departs from the application' });
/** The website and application cells of the row about a subject. */
async function row(page: Page, subject: string) {
  const cells = departures(page).locator('tbody tr', { has: page.getByRole('rowheader', { name: subject, exact: true }) }).locator('td');
  await expect(cells).toHaveCount(3);
  return { website: (await cells.nth(0).textContent())!.trim(), application: (await cells.nth(1).textContent())!.trim() };
}

const hex = (color: string) => `#${color.match(/\d+/g)!.slice(0, 3).map((channel) => Number(channel).toString(16).padStart(2, '0')).join('')}`;

test('the body text row equals the type of a paragraph on a manual page', async ({ page }) => {
  await page.goto('/design-system/website/');
  const { website } = await row(page, 'Body text');
  const [, weight, size, lineHeight] = website.match(/^(\d+) (\d+)px\/([\d.]+) /)!;
  await page.goto('/docs/operator-guide/');
  const paragraph = page.locator('main article p').first();
  await expect(paragraph).toBeVisible();
  const computed = await paragraph.evaluate((element) => {
    const style = getComputedStyle(element);
    return { size: style.fontSize, lineHeight: Number.parseFloat(style.lineHeight), weight: style.fontWeight };
  });
  expect(computed.size).toBe(`${size}px`);
  expect(computed.weight).toBe(weight);
  expect(computed.lineHeight).toBeCloseTo(Number(size) * Number(lineHeight), 2);
});

test('the eyebrow row equals a computed eyebrow label', async ({ page }) => {
  await page.goto('/design-system/website/');
  const { website } = await row(page, 'Eyebrow labels');
  const [, size, weight, tracking, transform] = website.match(/^(\d+)px, (\d+), tracking ([\d.]+)em, (\w+)$/)!;
  const eyebrow = page.locator('.eyebrow').first();
  await expect(eyebrow).toBeVisible();
  const computed = await eyebrow.evaluate((element) => {
    const style = getComputedStyle(element);
    return { size: style.fontSize, weight: style.fontWeight, tracking: Number.parseFloat(style.letterSpacing), transform: style.textTransform };
  });
  expect(computed).toEqual({ size: `${size}px`, weight, tracking: expect.closeTo(Number(size) * Number(tracking), 2), transform });
});

test('the moods row lists the toggle’s two moods and every mood of the application', async ({ page }) => {
  await page.goto('/design-system/website/');
  const { website, application } = await row(page, 'Moods');
  expect(application).toBe(Object.values(moods).join(', '));
  const [dark, light] = website.split(' and ');
  expect([moodByLabel[dark], moodByLabel[light]]).toEqual(['dark', 'balanced']);
  await expect(page.locator('section[aria-labelledby="departures"] p').first())
    .toContainText(`The theme toggle in the header switches between the ${dark} mood and the ${light} mood.`);
});

test('the dark foreground row shows the application’s value and the text color of the site’s dark theme', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('baander-site-appearance', 'dark'));
  await page.goto('/design-system/website/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  const { website, application } = await row(page, 'Dark foreground');
  const darkBlock = repository('ui/design-tokens/build/web/theme.css').match(/:root\[data-theme="dark"\]:not\(\[data-accent\]\)[^{]*\{([^}]*)\}/)![1];
  expect(application).toBe(darkBlock.match(/--color-foreground:\s*(#[0-9a-f]{6})/)![1]);
  expect(hex(await page.locator('section[aria-labelledby="departures"] p').first().evaluate((element) => getComputedStyle(element).color))).toBe(website);
  for (const swatch of await departures(page).locator('.ds-swatch').all()) {
    expect(hex(await swatch.evaluate((element) => getComputedStyle(element).backgroundColor))).toBe(await swatch.evaluate((element) => element.nextElementSibling!.textContent));
  }
});

for (const system of ['dark', 'light'] as const) {
  test(`with the system preferring ${system}, the site starts in its matching mood and the toggle moves between the two moods the page names`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: system });
    await page.goto('/design-system/website/');
    const [dark, light] = (await row(page, 'Moods')).website.split(' and ').map((label) => moodByLabel[label]);
    const [unchosen, chosen] = system === 'dark' ? [dark, light] : [light, dark];
    const root = page.locator('html');
    await expect(root).toHaveAttribute('data-theme', unchosen);
    const toggle = page.locator('[data-appearance-toggle]');
    await toggle.click();
    await expect(root).toHaveAttribute('data-theme', chosen);
    await toggle.click();
    await expect(root).toHaveAttribute('data-theme', unchosen);
  });
}

test('at 390 px the departures table scrolls inside its region and the page does not scroll sideways', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/design-system/website/');
  const region = departures(page);
  const sizes = await region.evaluate((element) => ({ scroll: element.scrollWidth, client: element.clientWidth }));
  expect(sizes.scroll).toBeGreaterThan(sizes.client);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await region.focus();
  await expect(region).toBeFocused();
  expect(await region.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
  await expect(page.getByRole('heading', { level: 2, name: 'Static public website' })).toBeVisible();
});
