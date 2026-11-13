import { test as base, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const packageVersion = JSON.parse(readFileSync(new URL('../../../packages/baander-audio-core/package.json', import.meta.url), 'utf8')).version as string;

const test = base.extend<{ localRequests: void }>({
  localRequests: [async ({ context, baseURL }, use) => {
    const outbound: string[] = [];
    await context.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== new URL(baseURL!).origin) {
        outbound.push(route.request().url());
        await route.abort();
      } else {
        await route.continue();
      }
    });
    await use();
    expect(outbound, 'bAAC must render without external services').toEqual([]);
  }, { auto: true }],
});

test('visitors discover bAAC and its direct route has one canonical URL', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'bAAC', exact: true }).click();
  await expect(page).toHaveURL(/\/baac\/$/);
  await expect(page.getByRole('heading', { name: 'bAAC', exact: true })).toBeVisible();
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://baander.app/baac/');
  await expect(page.getByRole('link', { name: 'Read the source guide' }))
    .toHaveAttribute('href', /^https:\/\/github\.com\/baander-app\/baander\/blob\/[a-f0-9]{40}\/packages\/baander-audio-core\/docs\/README\.md$/);
  await expect(page.getByRole('heading', { name: 'Server transcoding through FFmpeg' })).toBeVisible();
  await expect(page.locator('#integration')).toContainText('libbaac');
  await expect(page.locator('#integration').getByRole('link', { name: 'AAC implementation' }))
    .toHaveAttribute('href', '/docs/operator-guide/media/transcoding/#aac-implementation');
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'bAAC', exact: true }))
    .toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('navigation', { name: 'Footer navigation' }).getByRole('link', { name: 'bAAC', exact: true }))
    .toHaveAttribute('href', '/baac/');
  await page.goto('/baac');
  await expect(page).toHaveURL(/\/baac\/$/);
  await expect(page.getByRole('heading', { name: 'bAAC', exact: true })).toBeVisible();
  await page.goto('/docs/operator-guide/');
  await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'bAAC', exact: true }).click();
  await expect(page).toHaveURL(/\/baac\/$/);
});

test('all sixteen charts reproduce their adjacent tables and complete recorded populations', async ({ page }) => {
  await page.goto('/baac/');
  const panels = page.locator('.baac-chart-panel');
  await expect(panels).toHaveCount(16);
  for (const panel of await panels.all()) {
    const mapping = await panel.evaluate((element) => {
      const svg = element.querySelector('svg')!;
      const quality = element.closest('section')!.id === 'quality';
      const chart = [...svg.querySelectorAll('[data-value]')].map((point) => ({
        key: point.getAttribute(quality ? 'data-config' : 'data-codec'),
        value: point.getAttribute('data-value'),
      }));
      const table = [...element.querySelectorAll('tbody tr')].map((row) => ({
        key: row.getAttribute(quality ? 'data-config' : 'data-codec'),
        value: row.querySelector('[data-value]')!.getAttribute('data-value'),
      }));
      const labelIds = svg.getAttribute('aria-labelledby')!.split(' ');
      return {
        chart,
        table,
        labels: labelIds.map((id) => document.getElementById(id)?.textContent),
        tooltips: [...svg.querySelectorAll('[data-value]')].map((point) => point.querySelector('title')?.textContent),
      };
    });
    expect(mapping.chart).toEqual(mapping.table);
    expect(mapping.chart.length).toBeGreaterThan(0);
    expect(new Set(mapping.chart.map((point) => point.key)).size).toBe(mapping.chart.length);
    expect(mapping.labels).toHaveLength(2);
    for (const label of [...mapping.labels, ...mapping.tooltips]) expect(label).toMatch(/\S/);
    for (const point of mapping.chart) expect(Number.isFinite(Number(point.value))).toBe(true);
  }
  await expect(page.locator('#quality [id$="-peaq_basic"] svg [data-value]')).toHaveCount(55);
  await expect(page.locator('#quality [id$="-visqol"] svg [data-value]')).toHaveCount(55);
  await expect(page.locator('#throughput svg [data-value]')).toHaveCount(33);
});

test('the lossless table sets bAAC level 5 and level 8 beside the references, every row bit-exact', async ({ page }) => {
  await page.goto('/baac/');
  await expect(page.locator('.release-note')).toContainText(`Version ${packageVersion} `);
  const section = page.locator('#lossless');
  await expect(section.getByRole('heading', { level: 2 })).toHaveText('Standard FLAC, measured level for level.');
  await expect(section).not.toContainText('does not imply a bAAC lossless mode');
  await expect(section.locator('.baac-dateline'))
    .toHaveText(/^Recorded \d{1,2} [A-Z][a-z]+ \d{4} on .+\. The references were flac 1\.4\.3 and FFmpeg \S+\. /);
  await expect(section.locator('tbody th')).toHaveText([
    'bAAC level 5 (default)',
    'bAAC level 8',
    'flac level 5',
    'flac level 8',
    'FFmpeg FLAC level 5',
    'FFmpeg FLAC level 8',
    'FFmpeg ALAC',
    /^Apple ALAC \(refalac\)(, carried from \d{1,2} [A-Z][a-z]+ \d{4})?$/,
  ]);
  await expect(section.locator('tbody td:last-child')).toHaveText(Array(8).fill('Passed'));
  for (const codec of ['baac-5', 'baac-8', 'flac-5', 'flac-8', 'flac-ffmpeg-5', 'flac-ffmpeg-8', 'alac-ffmpeg']) {
    const cells = section.locator(`tr[data-codec="${codec}"] td`);
    await expect(cells.nth(1)).toHaveText(/^\d{3}\.\d{2}$/);
    await expect(cells.nth(2)).toHaveText(/^[\d,]+×$/);
    await expect(cells.nth(3)).toHaveText(/^[\d,]+×$/);
    if (codec !== 'alac-ffmpeg') await expect(cells.nth(0)).toHaveText(/^\d{1,3}(,\d{3})+$/);
  }
  await expect(section.locator('tr[data-codec="alac-ffmpeg"] td').nth(0)).toHaveText('Not recorded');
  const refalac = section.locator('tr[data-codec="alac-apple-ref"] td');
  await expect(refalac).toHaveText(['Not recorded', /^\d{3}\.\d{2}$/, 'Not measured', 'Not measured', 'Passed']);
});

test('charts show readouts on hover and keyboard focus, and legend buttons toggle encoders', async ({ page }) => {
  const format = (value: string | null, digits = 3) => Number(value).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  await page.goto('/baac/');
  const panel = page.locator('#baac-lc-peaq_basic');
  const chart = panel.locator('svg.baac-chart');
  const tooltip = panel.locator('.baac-tooltip');
  await expect(tooltip).toBeHidden();

  const point = panel.locator('.baac-series[data-codec="ours"] .baac-point').nth(2);
  await point.scrollIntoViewIfNeeded();
  const box = (await point.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2 + 6, box.y + box.height / 2 + 6);
  await expect(tooltip).toBeVisible();
  const nominal = await point.getAttribute('data-nominal');
  await expect(tooltip).toContainText(`Nominal ${nominal} kbit/s`);
  const peers = await panel.locator(`.baac-point[data-nominal="${nominal}"]`).evaluateAll((points) => points.map((element) => ({
    label: element.getAttribute('data-label'),
    value: element.getAttribute('data-value'),
  })));
  expect(peers.length).toBeGreaterThan(1);
  for (const peer of peers) {
    await expect(tooltip).toContainText(peer.label!);
    await expect(tooltip).toContainText(format(peer.value));
  }
  await page.mouse.move(0, 0);
  await expect(tooltip).toBeHidden();

  await chart.focus();
  await chart.press('ArrowRight');
  await expect(tooltip).toBeVisible();
  await expect(panel.locator('[aria-live]')).toContainText('kbit/s measured');
  await chart.press('Escape');
  await expect(tooltip).toBeHidden();

  const ffmpeg = panel.locator('.baac-legend-item[data-series="ffmpeg"]');
  await expect(ffmpeg).toHaveAttribute('role', 'button');
  await expect(ffmpeg).toHaveAttribute('aria-pressed', 'true');
  await ffmpeg.press('Enter');
  await expect(ffmpeg).toHaveAttribute('aria-pressed', 'false');
  await expect(panel.locator('.baac-series[data-codec="ffmpeg"]')).toBeHidden();
  await expect(panel.locator('.baac-series.is-receded')).toHaveCount(0);
  await page.mouse.move(box.x + box.width / 2 + 6, box.y + box.height / 2 + 6);
  await expect(tooltip).toBeVisible();
  await expect(tooltip).not.toContainText('FFmpeg');
  await ffmpeg.click();
  await expect(ffmpeg).toHaveAttribute('aria-pressed', 'true');
  await expect(panel.locator('.baac-series[data-codec="ffmpeg"]')).toBeVisible();

  const speed = page.locator('#baac-lc-encode');
  const bar = speed.locator('.baac-bar').first();
  await bar.scrollIntoViewIfNeeded();
  const barBox = (await bar.boundingBox())!;
  await page.mouse.move(barBox.x + 8, barBox.y + barBox.height / 2);
  const barTooltip = speed.locator('.baac-tooltip');
  await expect(barTooltip).toBeVisible();
  await expect(barTooltip).toContainText((await bar.getAttribute('data-label'))!);
  await expect(barTooltip).toContainText(format(await bar.getAttribute('data-value')));
  await expect(bar).toHaveClass(/is-active/);
});

for (const width of [1440, 390]) {
  for (const colorScheme of ['light', 'dark'] as const) {
    test.describe(`${width}px ${colorScheme} bAAC without JavaScript`, () => {
      test.use({ javaScriptEnabled: false, viewport: { width, height: 1000 }, colorScheme });
      test('plots and tables remain readable and keyboard scroll within the page', async ({ page }) => {
        await page.goto('/baac/');
        await expect(page.getByRole('heading', { name: 'bAAC', exact: true })).toBeVisible();
        await expect(page.locator('[data-appearance-toggle]')).toBeHidden();
        for (const family of await page.locator('.baac-family').all()) {
          if (await family.getAttribute('open') === null) {
            await family.locator(':scope > summary').focus();
            await family.locator(':scope > summary').press('Enter');
          }
          await expect(family).toHaveAttribute('open', '');
        }
        const panels = page.locator('.baac-chart-panel');
        const vendorColors = await page.locator('.baac-series[data-codec], .baac-bar[data-codec]').evaluateAll((series) => series.map((element) => ({
          vendor: element.getAttribute('data-codec')!,
          color: element.classList.contains('baac-bar') ? getComputedStyle(element).fill : getComputedStyle(element).color,
        })));
        const colors = new Map<string, string>();
        for (const { vendor, color } of vendorColors) {
          if (colors.has(vendor)) expect(color, `${vendor} keeps its color across profiles and operations`).toBe(colors.get(vendor));
          else colors.set(vendor, color);
        }
        expect(colors.size).toBe(5);
        expect(new Set(colors.values()).size, 'Every vendor has a distinct color').toBe(5);
        for (const panel of await panels.all()) {
          await expect(panel.locator('svg')).toBeVisible();
          const region = panel.locator('.baac-plot-scroll');
          await region.focus();
          await expect(region).toBeFocused();
          expect(await region.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
          const dimensions = await region.evaluate((element) => ({ viewport: element.clientWidth, content: element.scrollWidth }));
          if (dimensions.content > dimensions.viewport) {
            await region.press('ArrowRight');
            await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
          }
          const disclosure = panel.locator('.baac-data');
          await disclosure.locator('summary').focus();
          await disclosure.locator('summary').press('Enter');
          await expect(disclosure.locator('table')).toBeVisible();
          const table = disclosure.getByRole('region');
          await table.focus();
          await expect(table).toBeFocused();
          const tableOverflows = await table.evaluate((element) => element.scrollWidth > element.clientWidth);
          if (tableOverflows) {
            await table.press('ArrowRight');
            await expect.poll(() => table.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
          }
          await region.evaluate((element) => { element.scrollLeft = 0; });
          await disclosure.locator('summary').press('Enter');
        }
        const lossless = page.getByRole('region', { name: 'Lossless measurements' });
        await lossless.focus();
        await expect(lossless).toBeFocused();
        if (await lossless.evaluate((element) => element.scrollWidth > element.clientWidth)) {
          await lossless.press('ArrowRight');
          await expect.poll(() => lossless.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        const first = panels.first();
        const screenshot = test.info().outputPath(`baac-${width}-${colorScheme}.png`);
        await first.screenshot({ path: screenshot });
        await test.info().attach(`baac-${width}-${colorScheme}`, { path: screenshot, contentType: 'image/png' });
        const widths = await panels.locator('svg').evaluateAll((charts) => charts.map((chart) => chart.getBoundingClientRect().width));
        for (const chartWidth of widths) expect(chartWidth, 'Keep 13px plot text at its intrinsic scale').toBeGreaterThanOrEqual(720);
      });
    });
  }
}

for (const colorScheme of ['light', 'dark'] as const) {
  test(`${colorScheme} system preference: explicit opposite bAAC theme persists`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    await page.goto('/baac/');
    const chart = page.locator('.baac-chart').first();
    const initialColor = await chart.evaluate((element) => getComputedStyle(element).color);
    const opposite = colorScheme === 'light' ? 'dark' : 'light';
    const chosenTheme = opposite === 'light' ? 'balanced' : 'dark';
    await page.getByRole('button', { name: `Switch to ${opposite} mode` }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', chosenTheme);
    const chosenColor = await chart.evaluate((element) => getComputedStyle(element).color);
    expect(chosenColor).not.toBe(initialColor);
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', chosenTheme);
    expect(await chart.evaluate((element) => getComputedStyle(element).color)).toBe(chosenColor);
    await expect(chart).toBeVisible();
  });
}
