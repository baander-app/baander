import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test as base, expect } from '@playwright/test';

// The iconography page: the icon guide's tables render whole, each in a region that scrolls on its
// own, and icons are named by their Lucide components rather than drawn.

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
    expect(outbound, 'The iconography page must load without external services').toEqual([]);
  }, { auto: true }],
});

const repository = fileURLToPath(new URL('../../../', import.meta.url));
const guide = readFileSync(join(repository, 'ui/ICON-GUIDE.md'), 'utf8');

type Table = { header: string[]; rows: string[][] };

const cells = (line: string) => line.trim().slice(1, -1).split('|').map((cell) => cell.trim().replaceAll('`', '').replaceAll('**', ''));

/** Each table under `## <heading>`, up to the next second-level heading, in document order. */
function tables(heading: string): Table[] {
  const body = guide.split(`\n## ${heading}\n`)[1].split(/\n## /)[0];
  const found: Table[] = [];
  let lines: string[] = [];
  for (const line of [...body.split('\n'), '']) {
    if (line.startsWith('|')) lines.push(line);
    else if (lines.length > 0) {
      found.push({ header: cells(lines[0]), rows: lines.slice(2).map(cells) });
      lines = [];
    }
  }
  return found;
}

const borrowed = ['When to Use Icons', 'Canonical Concept to Icon Assignments'];

test('every table borrowed from the icon guide renders with its header row and every row the guide has', async ({ page }) => {
  await page.goto('/design-system/iconography/');
  for (const heading of borrowed) {
    const expected = tables(heading);
    expect(expected.length, heading).toBeGreaterThan(0);
    const section = page.locator('section.ds-borrowed').filter({ has: page.getByRole('heading', { level: 2, name: heading, exact: true }) });
    const rendered = section.locator('table');
    await expect(rendered, heading).toHaveCount(expected.length);
    for (const [index, table] of expected.entries()) {
      const actual = rendered.nth(index);
      expect(await actual.locator('thead th').allTextContents(), `${heading}, table ${index + 1}`).toEqual(table.header);
      await expect(actual.locator('thead th[scope="col"]')).toHaveCount(table.header.length);
      const rows = await actual.locator('tbody tr').evaluateAll((trs) => trs.map((tr) => [...tr.children].map((cell) => cell.textContent!.trim())));
      expect(rows, `${heading}, table ${index + 1}`).toEqual(table.rows);
    }
  }
});

test('the sections follow the guide and the design language in order, and icons are named, not drawn', async ({ page }) => {
  await page.goto('/design-system/iconography/');
  const article = page.locator('article');
  expect(await article.locator('h2').allTextContents()).toEqual(['When to Use Icons', 'Icons in menu lists', 'Canonical Concept to Icon Assignments']);
  await expect(article.locator('svg, img, picture')).toHaveCount(0);
  const imports = article.locator('table').filter({ has: page.locator('thead th', { hasText: /^Import$/ }) }).locator('tbody td:nth-child(2)');
  expect(await imports.count()).toBeGreaterThan(0);
  for (const cell of await imports.all()) {
    await expect(cell.locator('code')).toHaveCount(1);
    await expect(cell).toHaveAttribute('data-pagefind-ignore', '');
  }
});

test('each table sits in a named region that scrolls inside itself at 390 px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/design-system/iconography/');
  const regions = page.locator('article [role="region"]');
  const total = await page.locator('article table').count();
  await expect(regions).toHaveCount(total);
  for (const region of await regions.all()) {
    await expect(region).toHaveAttribute('tabindex', '0');
    expect(await region.getAttribute('aria-label')).toMatch(/\S table$/);
    await expect(region.locator('> table')).toHaveCount(1);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

  const wide = page.getByRole('region', { name: 'When to Use Icons table', exact: true });
  const [scrollWidth, clientWidth] = await wide.evaluate((element) => [element.scrollWidth, element.clientWidth]);
  expect(scrollWidth).toBeGreaterThan(clientWidth);
  await wide.focus();
  await page.keyboard.press('ArrowRight');
  await expect.poll(() => wide.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
  expect(await page.evaluate(() => window.scrollX)).toBe(0);
});
