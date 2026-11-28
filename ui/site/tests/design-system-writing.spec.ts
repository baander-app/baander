import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test as base, expect } from '@playwright/test';

// The writing page: it publishes the design language's writing rules and its loading, error and empty
// state rules as the document states them, with no rule of its own.

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
    expect(outbound, 'The writing page must load without external services').toEqual([]);
  }, { auto: true }],
});

const repository = fileURLToPath(new URL('../../../', import.meta.url));
const design = readFileSync(join(repository, 'ui/DESIGN.md'), 'utf8');

/** The body of a `## ` or `### ` section, up to the next heading. */
function section(marker: string): string {
  return `\n${design.split(`\n${marker}\n`)[1].split(/\n#{1,3} /)[0]}`;
}

/** Markdown inline text as the page shows it: no emphasis markers, backticks or line breaks. */
const plain = (markdown: string) => markdown.replace(/\*\*|\*|`/g, '').replace(/\s+/g, ' ').trim();

/** The bullet items of a section, each with its continuation lines. */
function items(marker: string): string[] {
  return section(marker).split(/\n- /).slice(1).map((item) => plain(item.split(/\n\n|\n---/)[0]));
}

const ruleSections = ['## Writing', '### Loading states', '### Error states', '### Empty states'];

test('the page lists each rule of the borrowed sections, in order, and no other list items', async ({ page }) => {
  await page.goto('/design-system/writing/');
  const expected = ruleSections.flatMap(items);
  expect(expected.length).toBeGreaterThan(10);
  const listed = (await page.locator('article li').allTextContents()).map((text) => text.replace(/\s+/g, ' ').trim());
  expect(listed).toEqual(expected);
});

test('the writing rules follow the opening, then each state and the one-item-per-element rule under its own heading', async ({ page }) => {
  await page.goto('/design-system/writing/');
  const article = page.locator('article');
  await expect(article.locator('h1')).toHaveText('Writing');
  expect(await article.locator('h2').allTextContents()).toEqual(['Loading states', 'Error states', 'Empty states', 'Content language']);
  await expect(article).toContainText(plain(section('## Writing').trim().split('\n\n')[0]));

  const language = section('### Content language').split('\n\n').map(plain).filter(Boolean);
  const rule = language.find((paragraph) => paragraph.startsWith('Put one content item'))!;
  const contentLanguage = page.locator('section.ds-borrowed').filter({ has: page.getByRole('heading', { level: 2, name: 'Content language' }) });
  await expect(contentLanguage.locator('p')).toHaveText([rule]);
  for (const paragraph of language.filter((text) => text !== rule)) await expect(article).not.toContainText(paragraph);
  await expect(article.getByRole('link', { name: 'typography page' })).toHaveAttribute('href', '/typography/');
});

test('the page keeps within a 390 px viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/design-system/writing/');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});
