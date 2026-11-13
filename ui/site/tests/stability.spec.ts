import { test as base, expect, type Page } from '@playwright/test';

// A reader who follows a link sees every layout shift between the first paint and the settled page
// as a jump. These checks load pages the way a slow visitor does, so work the scripts and fonts do
// after the first paint cannot hide behind a fast local server, and they allow no shift at all.

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
    expect(outbound, 'Layout checks must not contact external services').toEqual([]);
  }, { auto: true }],
});

interface RecordedShift {
  value: number;
  time: number;
  sources: string[];
}

// The Layout Instability API entries, which the bundled DOM typings do not declare.
interface LayoutShiftSource {
  node: Node | null;
  previousRect: DOMRectReadOnly;
  currentRect: DOMRectReadOnly;
}

interface LayoutShiftEntry extends PerformanceEntry {
  value: number;
  hadRecentInput: boolean;
  sources: LayoutShiftSource[];
}

declare global {
  interface Window {
    recordedLayoutShifts: RecordedShift[];
  }
}

const pages = ['/', '/docs/operator-guide/', '/bac/'];
const viewports = [{ width: 1440, height: 900 }, { width: 390, height: 844 }];
const fontFiles = '**/assets/site/fonts/*.woff2';

async function recordLayoutShifts(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.recordedLayoutShifts = [];
    const describe = (node: Node | null): string => {
      if (!(node instanceof Element)) return node?.nodeName ?? 'text';
      const classes = [...node.classList].slice(0, 2).join('.');
      return node.tagName.toLowerCase() + (node.id ? `#${node.id}` : '') + (classes ? `.${classes}` : '');
    };
    const rect = (box: DOMRectReadOnly) => `${Math.round(box.x)},${Math.round(box.y)} ${Math.round(box.width)}x${Math.round(box.height)}`;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries() as LayoutShiftEntry[]) {
        if (entry.hadRecentInput) continue;
        window.recordedLayoutShifts.push({
          value: entry.value,
          time: Math.round(entry.startTime),
          sources: entry.sources.map((source) => `${describe(source.node)} ${rect(source.previousRect)} -> ${rect(source.currentRect)}`),
        });
      }
    }).observe({ type: 'layout-shift', buffered: true });
  });
}

// A slow connection and a slow processor stretch the window between first paint and the moment
// scripts and fonts finish, which is where a shift shows. Holding the font files back further makes
// the page paint in the fallback faces and swap to Geist afterwards, the path a visitor takes when
// the stylesheets are cached but the font files are not.
async function loadSlowly(page: Page, path: string, holdFonts = false): Promise<void> {
  const session = await page.context().newCDPSession(page);
  await session.send('Network.enable');
  await session.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 150,
    downloadThroughput: (1.5 * 1024 * 1024) / 8,
    uploadThroughput: (750 * 1024) / 8,
  });
  await session.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  if (holdFonts) {
    await page.route(fontFiles, async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 1500));
      await route.continue();
    });
  }
  await page.goto(path, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(500);
}

function describeShifts(shifts: RecordedShift[]): string {
  return shifts.map((shift) => `t=${shift.time}ms value=${shift.value.toFixed(4)}: ${shift.sources.join(' | ')}`).join('\n');
}

async function expectNoShift(page: Page): Promise<void> {
  const shifts = await page.evaluate(() => window.recordedLayoutShifts);
  expect(shifts, `Layout shifted after first paint:\n${describeShifts(shifts)}`).toEqual([]);
}

// A fallback face scaled to Geist's advances still differs by a pixel or two on some words, so a
// centred row may slide slightly when Geist replaces it. What it must never do is move a line: a
// changed line count moves everything below it by a whole line, and a header reflow by far more.
async function expectNoLineShift(page: Page): Promise<void> {
  const shifts = await page.evaluate(() => window.recordedLayoutShifts);
  const total = shifts.reduce((sum, shift) => sum + shift.value, 0);
  const moved = shifts.flatMap((shift) => shift.sources).filter((source) => {
    const [, fromX, fromY, toX, toY] = /(-?\d+),(-?\d+) \d+x\d+ -> (-?\d+),(-?\d+)/.exec(source) ?? [];
    return Math.abs(Number(toY) - Number(fromY)) >= 3 || Math.abs(Number(toX) - Number(fromX)) >= 8;
  });
  expect(moved, `A line moved when the fonts arrived:\n${describeShifts(shifts)}`).toEqual([]);
  expect(total, `The font swap shifted more than a few pixels:\n${describeShifts(shifts)}`).toBeLessThan(0.002);
}

for (const viewport of viewports) {
  for (const path of pages) {
    test(`${viewport.width}px ${path}: nothing moves between first paint and settled load`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await recordLayoutShifts(page);
      await loadSlowly(page, path);
      await expectNoShift(page);
    });

    test(`${viewport.width}px ${path}: nothing moves when the fonts arrive after first paint`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await recordLayoutShifts(page);
      await loadSlowly(page, path, true);
      const swapped = await page.evaluate(() => [...document.fonts].some((face) => face.family.replaceAll('"', '') === 'Geist Variable' && face.status === 'loaded'));
      expect(swapped, 'Geist must have arrived and replaced the fallback for this check to mean anything').toBe(true);
      await expectNoLineShift(page);
    });
  }
}

// With the Geist files blocked the page paints in the fallback faces for good. Block heights with a
// unitless line-height depend only on the line count, so equal heights prove the fallback wraps the
// text as Geist does; the width of a single-line row proves the glyphs are scaled to Geist's
// advances, which an unadjusted system face misses by several percent. Bold rows cover the faces that
// stand in for Geist's heavier weights, and inline code the mono fallback. The width tolerance covers
// the medium weight, which has no face of its own and renders in the regular one.
const geometryPages = [
  {
    path: '/',
    blocks: '.hero-copy :is(h1, p), .home-section :is(h2, h3, p, li), .site-header nav',
    rows: '.site-header nav, .actions a, .text-action',
  },
  {
    path: '/docs/operator-guide/',
    blocks: '.manual-article :is(h1, h2, h3, p, li), .site-header nav, .manual-breadcrumb',
    rows: '.site-header nav, .manual-breadcrumb, .manual-article code',
  },
  {
    // A long page dense with inline code: the mono face is compared here, line parity on the two pages above.
    path: '/docs/developer-guide/testing/',
    blocks: '.manual-article pre',
    rows: '.manual-article :is(p, li) > code',
  },
];
const rowTolerance = 0.025;

for (const viewport of viewports) {
  for (const { path, blocks: blockSelector, rows: rowSelector } of geometryPages) {
    test(`${viewport.width}px ${path}: text painted in the fallback faces has the lines and widths it has in Geist`, async ({ page }) => {
      // An inline element that wraps has no single width to compare, so only rows that stay on one
      // line in both renderings take part in the width comparison.
      const geometry = () => page.evaluate(async ({ blockSelector, rowSelector }) => {
        await document.fonts.ready;
        return {
          heights: [...document.querySelectorAll(blockSelector)].map((element) => Math.round(element.getBoundingClientRect().height)),
          rows: [...document.querySelectorAll(rowSelector)]
            .map((element) => ({ width: element.getBoundingClientRect().width, lines: element.getClientRects().length })),
        };
      }, { blockSelector, rowSelector });
      await page.setViewportSize(viewport);
      await page.goto(path);
      const withGeist = await geometry();
      const loaded = await page.evaluate(() => [...document.fonts].some((face) => face.family.replaceAll('"', '') === 'Geist Variable' && face.status === 'loaded'));
      expect(loaded, 'Geist must have rendered the reference page').toBe(true);

      await page.route('**/assets/site/fonts/geist*', (route) => route.abort());
      await page.goto(path);
      const withFallback = await geometry();
      const geistStillLoaded = await page.evaluate(() => [...document.fonts].some((face) => face.family.includes('Geist Variable') && face.status === 'loaded'));
      expect(geistStillLoaded, 'The Geist files must be blocked for this comparison').toBe(false);
      expect(withFallback.heights).toEqual(withGeist.heights);
      const compared = withFallback.rows.filter((row, index) => row.lines === 1 && withGeist.rows[index].lines === 1);
      expect(compared.length, 'Some single-line rows must take part in the width comparison').toBeGreaterThan(0);
      withFallback.rows.forEach((row, index) => {
        const reference = withGeist.rows[index];
        if (row.lines !== 1 || reference.lines !== 1) return;
        expect(Math.abs(row.width - reference.width) / reference.width, `row ${index} is ${row.width}px in the fallback and ${reference.width}px in Geist`).toBeLessThan(rowTolerance);
      });
    });
  }
}
