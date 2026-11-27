import { test as base, expect, type Page } from '@playwright/test';

// The type page: each live sample is set in the values its table row gives, the mono roles render in
// the bundled Geist Mono face with tabular numerals, and the links to the typography page land on its
// sections.

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
    expect(outbound, 'The type page must load without external services').toEqual([]);
  }, { auto: true }],
});

type Row = { role: string; desktop: number; phone: number; weight: string; tracking: number; uppercase: boolean; lineHeight: number };

/** The table's rows, with tracking read from the design language's notation (−0.02em, +0.12em, 0). */
async function tableRows(page: Page): Promise<Row[]> {
  return page.locator('.ds-type-roles tbody tr').evaluateAll((rows) => rows.map((row) => {
    const cells = [...row.querySelectorAll('td')].map((cell) => cell.textContent!.trim());
    const [tracking, transform] = cells[3].split(',').map((part) => part.trim());
    return {
      role: row.getAttribute('data-role')!,
      desktop: Number.parseFloat(cells[0]),
      phone: Number.parseFloat(cells[1]),
      weight: cells[2].split(',')[0].trim(),
      tracking: Number.parseFloat(tracking.replace('−', '-').replace('+', '')),
      uppercase: transform === 'uppercase',
      lineHeight: Number.parseFloat(cells[4]),
    };
  }));
}

async function samples(page: Page) {
  return page.locator('.ds-type-samples li').evaluateAll((items) => Object.fromEntries(items.map((item) => {
    const style = getComputedStyle(item.querySelector('.type')!);
    return [item.getAttribute('data-role')!, {
      fontSize: Number.parseFloat(style.fontSize),
      fontWeight: style.fontWeight,
      letterSpacing: style.letterSpacing === 'normal' ? 0 : Number.parseFloat(style.letterSpacing),
      lineHeight: Number.parseFloat(style.lineHeight),
      textTransform: style.textTransform,
      fontFamily: style.fontFamily,
      numerals: style.fontVariantNumeric,
    }];
  })));
}

for (const [width, size] of [[1440, 'desktop'], [390, 'phone']] as const) {
  test(`${width}px: each sample is set in its row's ${size} size, weight, tracking and line-height`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/design-system/type/');
    const rows = await tableRows(page);
    expect(rows.map((row) => row.role)).toHaveLength(10);
    const set = await samples(page);
    expect(Object.keys(set)).toEqual(rows.map((row) => row.role));
    for (const row of rows) {
      const sample = set[row.role];
      expect(sample.fontSize, row.role).toBe(row[size]);
      expect(sample.fontWeight, row.role).toBe(row.weight);
      expect(sample.letterSpacing, row.role).toBeCloseTo(row.tracking * row[size], 2);
      expect(sample.lineHeight, row.role).toBeCloseTo(row.lineHeight * row[size], 1);
      expect(sample.textTransform, row.role).toBe(row.uppercase ? 'uppercase' : 'none');
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  });
}

test('the timecode and mono-sm samples render in the bundled Geist Mono face with tabular numerals', async ({ page }) => {
  await page.goto('/design-system/type/');
  await page.evaluate(() => document.fonts.ready);
  const set = await samples(page);
  for (const role of ['timecode', 'mono-sm']) {
    expect(set[role].fontFamily, role).toMatch(/^"Geist Mono Variable",/);
    expect(set[role].numerals, role).toBe('tabular-nums');
  }
  expect(set.body.numerals).toBe('normal');

  const session = await page.context().newCDPSession(page);
  await session.send('DOM.enable');
  await session.send('CSS.enable');
  const { root } = await session.send('DOM.getDocument', { depth: -1 });
  for (const role of ['timecode', 'mono-sm']) {
    const { nodeId } = await session.send('DOM.querySelector', { nodeId: root.nodeId, selector: `.ds-type-samples li[data-role="${role}"] .type` });
    const { fonts } = await session.send('CSS.getPlatformFontsForNode', { nodeId });
    expect(fonts.map(({ familyName, isCustomFont }) => [familyName.replace(/ Thin$/, ''), isCustomFont]), role).toEqual([['Geist Mono', true]]);
  }
});

test('the samples and value cells stay out of the search index, and the role rules are borrowed below them', async ({ page }) => {
  await page.goto('/design-system/type/');
  for (const sample of await page.locator('.ds-type-samples .type').all()) await expect(sample).toHaveAttribute('data-pagefind-ignore');
  for (const cell of await page.locator('.ds-type-roles td.ds-value').all()) await expect(cell).toHaveAttribute('data-pagefind-ignore');
  await expect(page.getByRole('heading', { level: 2, name: 'Roles', exact: true })).toBeVisible();
  await expect(page.locator('section[aria-labelledby="roles"] li', { hasText: 'Numerals:' })).toBeVisible();
});

test('the links to the typography page land on its scripts, glyph forms and script rules sections', async ({ page }) => {
  await page.goto('/design-system/type/');
  const links = await page.locator('.ds-type-links a').evaluateAll((anchors) => anchors.map((anchor) => anchor.getAttribute('href')!));
  expect(links).toEqual(['/typography/#scripts', '/typography/#glyph-forms', '/typography/#script-rules']);
  for (const href of links) {
    await page.goto('/design-system/type/');
    await page.locator(`.ds-type-links a[href="${href}"]`).click();
    await expect(page).toHaveURL(new RegExp(`${href.replace(/[/#-]/g, '\\$&')}$`));
    const heading = page.locator(`#${href.split('#')[1]} h2`);
    await expect(heading, href).toBeVisible();
    await expect(heading, href).toBeInViewport();
  }
});
