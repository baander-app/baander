import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test as base, expect, type Page } from '@playwright/test';

// The layout page: each spacing and radius token carries the uses the design language's Spacing and
// Border radius lists give its pixel value, the borrowed layout rules follow, and the well is shown
// through the player feature page.

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
    expect(outbound, 'The layout page must load without external services').toEqual([]);
  }, { auto: true }],
});

const design = readFileSync(fileURLToPath(new URL('../../DESIGN.md', import.meta.url)), 'utf8');
const sitemap = readFileSync(fileURLToPath(new URL('../../../var/website/sitemap.xml', import.meta.url)), 'utf8');

/** Each pixel size a list under `### <heading>` names, with the labels of the items that name it. */
function documentedUses(heading: string): Map<number, string[]> {
  const body = design.split(`\n### ${heading}\n`)[1].split(/\n#{2,3} /)[0];
  const uses = new Map<number, string[]>();
  for (const [, label, text] of body.matchAll(/^- ([^:\n]+):\s*([^.\n]*)/gm)) {
    for (const [, size] of text.matchAll(/(?<![\d.])(\d+(?:\.\d+)?)px\b/g)) {
      uses.set(Number(size), [...(uses.get(Number(size)) ?? []), label.trim()]);
    }
  }
  return uses;
}

async function scale(page: Page, name: string) {
  return page.getByRole('region', { name, exact: true }).locator('tbody tr').evaluateAll((rows) => rows.map((row) => ({
    token: row.querySelector('th')!.textContent!.trim(),
    cells: [...row.querySelectorAll('td')].map((cell) => cell.textContent!.trim()),
  })));
}

test('the radius table names Cards beside --radius-lg and "No documented use" beside each radius no list item names', async ({ page }) => {
  await page.goto('/design-system/layout/');
  const rows = await scale(page, 'Radius scale');
  expect(rows.find((row) => row.token === '--radius-lg')?.cells).toEqual(['0.75rem', '12px', '', 'Cards']);
  const uses = documentedUses('Border radius');
  for (const row of rows) {
    const px = Number.parseFloat(row.cells[1]);
    expect(Number.parseFloat(row.cells[0]) * 16, row.token).toBe(px);
    expect(row.cells[3], row.token).toBe(uses.get(px)?.join('; ') ?? 'No documented use');
  }
  expect(rows.filter((row) => row.cells[3] === 'No documented use').length).toBeGreaterThan(0);
});

test('the spacing table gives every step the uses the Spacing list names for its pixel value', async ({ page }) => {
  await page.goto('/design-system/layout/');
  const rows = await scale(page, 'Spacing scale');
  expect(rows.length).toBeGreaterThan(0);
  const uses = documentedUses('Spacing');
  for (const row of rows) {
    const px = Number.parseFloat(row.cells[1]);
    expect(Number.parseFloat(row.cells[0]) * 16, row.token).toBe(px);
    expect(row.cells[3], row.token).toBe(uses.get(px)?.join('; ') ?? 'No documented use');
  }
});

test('each live sample takes its size from the token its row names', async ({ page }) => {
  await page.goto('/design-system/layout/');
  const spaces = await page.locator('.ds-layout-space').evaluateAll((bars) => bars.map((bar) => [
    bar.closest('tr')!.querySelectorAll('td')[1].textContent!.trim(), `${bar.getBoundingClientRect().width}px`,
  ]));
  for (const [stated, drawn] of spaces) expect(drawn).toBe(stated);
  const radii = await page.locator('.ds-layout-radius').evaluateAll((corners) => corners.map((corner) => [
    corner.closest('tr')!.querySelectorAll('td')[1].textContent!.trim(), getComputedStyle(corner).borderTopLeftRadius,
  ]));
  for (const [stated, drawn] of radii) expect(drawn).toBe(stated);
});

test('the borrowed layout sections follow the tables, and the player feature page shows the well', async ({ page }) => {
  await page.goto('/design-system/layout/');
  const headings = await page.locator('.ds-article h2').allTextContents();
  expect(headings).toEqual(['Spacing scale', 'Radius scale', 'App Shell', 'Surfaces', 'Border radius', 'Spacing', 'Footer player', 'Notification pane', 'The well in the application']);
  for (const cell of await page.locator('.ds-layout-scale td.ds-value').all()) await expect(cell).toHaveAttribute('data-pagefind-ignore');
  const player = page.locator('.ds-article a[href="/features/player/"]');
  await expect(player).toHaveText('player feature page');
  expect(sitemap).toContain('<loc>https://baander.app/features/player/</loc>');
  await player.click();
  await expect(page).toHaveURL(/\/features\/player\/$/);
  await expect(page.locator('main h1')).toBeVisible();
});

for (const width of [1440, 390]) {
  test(`${width}px: the layout page stays within the viewport`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/design-system/layout/');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  });
}
