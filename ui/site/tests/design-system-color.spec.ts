import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';

// The color page: a row per documented color variable, a column per mood, and swatches that show the
// value printed beside them in both site themes.

const repository = (path: string) => readFileSync(fileURLToPath(new URL(`../../../${path}`, import.meta.url)), 'utf8');
const moods: Record<string, string> = JSON.parse(repository('ui/design-tokens/tokens/web/semantic-colors.json')).moods;

// The variables ui/DESIGN.md documents: those its Surfaces list names, then those of its token table.
const design = repository('ui/DESIGN.md');
const surfaces = design.split('### Surfaces')[1].split('\n### ')[0];
const tokenTable = design.split('### Token authority')[1].split('\n### ')[0].split('\n').filter((line) => line.startsWith('| `'));
const documented = [
  ...[...surfaces.matchAll(/^- [^:]+: `var\((--color-[a-z0-9-]+)\)`/gm)].map((match) => match[1]),
  ...tokenTable.flatMap((row) => [...row.split('|')[1].matchAll(/--color-[a-z0-9-]+/g)].map((match) => match[0])),
];

const appearances = ['dark', 'light'] as const;
const openIn = async (page: Page, appearance: (typeof appearances)[number]) => {
  await page.addInitScript((choice) => localStorage.setItem('baander-site-appearance', choice), appearance);
  await page.goto('/design-system/color/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', appearance === 'light' ? 'balanced' : 'dark');
};

const hex = (color: string) => {
  const channels = color.match(/\d+/g)!.slice(0, 3).map(Number);
  return `#${channels.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
};

const table = (page: Page) => page.getByRole('region', { name: 'Color roles in each mood' });

for (const appearance of appearances) {
  test(`${appearance} site theme: every swatch shows the value printed beside it`, async ({ page }) => {
    await openIn(page, appearance);
    const swatches = await page.locator('article .ds-swatch').evaluateAll((elements) => elements.map((element) => ({
      background: getComputedStyle(element).backgroundColor,
      code: element.nextElementSibling?.tagName === 'CODE' ? element.nextElementSibling.textContent : null,
    })));
    expect(swatches.length).toBe(documented.length * Object.keys(moods).length + 2);
    for (const { background, code } of swatches) expect(hex(background)).toBe(code);
  });
}

test('the table holds one row per documented color variable and one column per mood', async ({ page }) => {
  await page.goto('/design-system/color/');
  const region = table(page);
  await expect(region.locator('thead th')).toHaveText(['Use', 'Variable', ...Object.values(moods)]);
  expect(documented.length).toBeGreaterThan(20);
  await expect(region.locator('tbody th[scope="row"] code')).toHaveText(documented);
  for (const row of await region.locator('tbody tr').all()) await expect(row.locator('td')).toHaveCount(Object.keys(moods).length);
  const filled = region.locator('tbody', { has: page.locator('code', { hasText: /^--color-primary$/ }) });
  await expect(filled.locator('th[scope="rowgroup"]')).toHaveText('Filled actions and their content');
  await expect(filled.locator('th[scope="rowgroup"]')).toHaveAttribute('rowspan', '2');
  await expect(filled.locator('th[scope="row"] code')).toHaveText(['--color-primary', '--color-primary-foreground']);
  await expect(page.locator('article td[data-pagefind-ignore]')).toHaveCount(documented.length * Object.keys(moods).length);
  await expect(page.getByRole('heading', { level: 2, name: 'Token authority' })).toBeVisible();
});

test('the dark column shows the application’s foreground, and the note gives the website’s, which the page uses', async ({ page }) => {
  await openIn(page, 'dark');
  const darkColumn = Object.keys(moods).indexOf('dark');
  const foreground = table(page).locator('tbody tr', { has: page.locator('th[scope="row"] code', { hasText: /^--color-foreground$/ }) });
  await expect(foreground.locator('td').nth(darkColumn).locator('code')).toHaveText('#f0f0f2');
  const note = page.locator('.ds-color-note');
  await expect(note.locator('code')).toHaveText(['#f0f0f2', '#e6e6e9']);
  expect(hex(await note.evaluate((element) => getComputedStyle(element).color))).toBe('#e6e6e9');
  await expect(note.getByRole('link', { name: 'website page' })).toHaveAttribute('href', '/design-system/website/');
});

test('at 390px the table scrolls inside its region, which a keyboard reaches and shows focused', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/design-system/color/');
  const region = table(page);
  expect(await region.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  for (let presses = 0; presses < 80 && !(await region.evaluate((element) => element === document.activeElement)); presses++) {
    await page.keyboard.press('Tab');
  }
  await expect(region).toBeFocused();
  expect(await region.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
  const outline = await region.evaluate((element) => ({ style: getComputedStyle(element).outlineStyle, width: getComputedStyle(element).outlineWidth }));
  expect(outline.style).not.toBe('none');
  expect(parseFloat(outline.width)).toBeGreaterThan(0);
  await page.keyboard.press('ArrowRight');
  await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
});
