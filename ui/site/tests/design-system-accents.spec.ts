import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test, expect, type Locator, type Page } from '@playwright/test';

// The accents page: the identity colors, then per mood what each accent derives, with WCAG 2 ratios
// the spec recomputes from the colors printed beside them.

const repository = (path: string) => readFileSync(fileURLToPath(new URL(`../../../${path}`, import.meta.url)), 'utf8');
const semantics = JSON.parse(repository('ui/design-tokens/tokens/web/semantic-colors.json'));
const moods: Record<string, string> = semantics.moods;
const palette: Record<string, { value: string }> = JSON.parse(repository('ui/design-tokens/tokens/accent/palette.json')).accent;
const defaultAccent = repository('ui/design-tokens/build/web/theme.css').match(/\[data-accent="([a-z]+)"\],\s*:root\[data-theme="dark"\]:not\(\[data-accent\]\)/)![1];
const title = (accent: string) => accent[0].toUpperCase() + accent.slice(1);

const luminance = (color: string) => {
  const [red, green, blue] = color.slice(1).match(/../g)!.map((digits) => {
    const value = parseInt(digits, 16) / 255;
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
};
const contrast = (first: string, second: string) => {
  const [lighter, darker] = [luminance(first), luminance(second)].sort((a, b) => b - a);
  return (lighter + 0.05) / (darker + 0.05);
};
const truncated = (ratio: number) => `${(Math.floor(ratio * 100) / 100).toFixed(2)}:1`;

const appearances = ['dark', 'light'] as const;
const openIn = async (page: Page, appearance: (typeof appearances)[number]) => {
  await page.addInitScript((choice) => localStorage.setItem('baander-site-appearance', choice), appearance);
  await page.goto('/design-system/accents/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', appearance === 'light' ? 'balanced' : 'dark');
};
const hex = (color: string) => `#${color.match(/\d+/g)!.slice(0, 3).map((channel) => Number(channel).toString(16).padStart(2, '0')).join('')}`;
const moodTable = (page: Page, label: string) => page.getByRole('region', { name: `${label} mood: colors derived from each accent` });
const row = (table: Locator, accent: string) => table.locator('tbody tr', { has: table.page().locator('.ds-accents-name', { hasText: new RegExp(`^${title(accent)}`) }) });

for (const appearance of appearances) {
  test(`${appearance} site theme: every swatch shows the value printed beside it`, async ({ page }) => {
    await openIn(page, appearance);
    const swatches = await page.locator('article .ds-swatch').evaluateAll((elements) => elements.map((element) => ({
      background: getComputedStyle(element).backgroundColor,
      code: element.nextElementSibling?.tagName === 'CODE' ? element.nextElementSibling.textContent : null,
    })));
    expect(swatches.length).toBeGreaterThan(Object.keys(palette).length * Object.keys(moods).length * 6);
    for (const { background, code } of swatches) expect(hex(background)).toBe(code);
  });
}

test('the identity colors are the palette’s, and only the default accent is labelled default', async ({ page }) => {
  await page.goto('/design-system/accents/');
  const identities = page.locator('.ds-accents-identities li');
  await expect(identities).toHaveCount(Object.keys(palette).length);
  await expect(identities.locator('code')).toHaveText(Object.values(palette).map((token) => token.value));
  await expect(identities.locator('.ds-accents-name')).toHaveText(Object.keys(palette).map((accent) => accent === defaultAccent ? `${title(accent)} default` : title(accent)));
  await expect(page.locator('.ds-accents-default')).toHaveCount(1 + Object.keys(moods).length);
  for (const label of Object.values(moods)) await expect(row(moodTable(page, label), defaultAccent).locator('.ds-accents-default')).toHaveText('default');
});

test('each mood has a table with one row per accent and the targets are stated once above them', async ({ page }) => {
  await page.goto('/design-system/accents/');
  await expect(page.locator('.ds-accents-table')).toHaveCount(Object.keys(moods).length);
  for (const label of Object.values(moods)) {
    const table = moodTable(page, label);
    await expect(table.locator('thead th')).toHaveText(['Accent', 'Primary and its label', 'Accent text', 'Focus ring']);
    await expect(table.locator('tbody tr')).toHaveCount(Object.keys(palette).length);
    await expect(table.locator('tbody th .ds-accents-name')).toHaveText(Object.keys(palette).map((accent) => new RegExp(`^${title(accent)}`)));
    await expect(table.locator('tbody th code')).toHaveText(Object.values(palette).map((token) => token.value));
  }
  const targets = page.locator('.ds-accents-targets');
  await expect(targets).toHaveCount(1);
  await expect(targets).toContainText(`${semantics.normalTextContrast}:1 for the label and for accent text, and ${semantics.indicatorContrast}:1 for the ring`);
  await expect(targets).toContainText(`each fill at ${semantics.actionContrast}:1 against the surfaces`);
  await expect(page.getByRole('heading', { level: 2, name: 'Dynamic accent (blurhash-derived)' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Foreground-on-accent contrast' })).toBeVisible();
});

test('the warm mood’s violet accent text is #6e5ca5 and reaches the text target on its lowest surface', async ({ page }) => {
  await page.goto('/design-system/accents/');
  const text = row(moodTable(page, moods.warm), 'violet').locator('td').nth(1);
  await expect(text.locator('code').first()).toHaveText('#6e5ca5');
  const ratio = parseFloat((await text.locator('.ds-accents-ratio').textContent())!);
  expect(ratio).toBeGreaterThanOrEqual(semantics.normalTextContrast);
});

test('every printed ratio is the WCAG 2 contrast between the two colors in its cell, and meets its target', async ({ page }) => {
  await page.goto('/design-system/accents/');
  const cells = await page.locator('.ds-accents-table td').evaluateAll((elements) => elements.map((cell) => ({
    column: (cell as HTMLTableCellElement).cellIndex,
    codes: [...cell.querySelectorAll('code')].map((code) => code.textContent!),
    ratio: cell.querySelector('.ds-accents-ratio')?.textContent,
  })));
  expect(cells).toHaveLength(Object.keys(moods).length * Object.keys(palette).length * 3);
  const targets = [semantics.normalTextContrast, semantics.normalTextContrast, semantics.indicatorContrast];
  for (const { column, codes, ratio } of cells) {
    expect(codes).toHaveLength(2);
    expect(ratio).toBe(truncated(contrast(codes[0], codes[1])));
    expect(contrast(codes[0], codes[1])).toBeGreaterThanOrEqual(targets[column - 1]);
  }
});

test('at 390px each table scrolls inside its region, which a keyboard reaches and shows focused', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/design-system/accents/');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  const region = moodTable(page, moods.dark);
  expect(await region.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
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
