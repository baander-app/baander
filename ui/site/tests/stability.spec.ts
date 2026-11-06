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

const pages = ['/', '/docs/operator-guide/'];
const viewports = [{ width: 1440, height: 900 }, { width: 390, height: 844 }];

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
// scripts and fonts finish, which is where a shift shows.
async function loadSlowly(page: Page, path: string): Promise<void> {
  const session = await page.context().newCDPSession(page);
  await session.send('Network.enable');
  await session.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 150,
    downloadThroughput: (1.5 * 1024 * 1024) / 8,
    uploadThroughput: (750 * 1024) / 8,
  });
  await session.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  await page.goto(path, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(500);
}

for (const viewport of viewports) {
  for (const path of pages) {
    test(`${viewport.width}px ${path}: nothing moves between first paint and settled load`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await recordLayoutShifts(page);
      await loadSlowly(page, path);
      const shifts = await page.evaluate(() => window.recordedLayoutShifts);
      const report = shifts.map((shift) => `t=${shift.time}ms value=${shift.value.toFixed(4)}: ${shift.sources.join(' | ')}`).join('\n');
      expect(shifts, `Layout shifted after first paint:\n${report}`).toEqual([]);
    });
  }
}

// With the Geist files blocked the page paints in the fallback faces for good. Block heights with a
// unitless line-height depend only on the line count, so equal heights prove the fallback wraps the
// text as Geist does; the width of a single-line row of links proves the glyphs are scaled to Geist's
// advances, which an unadjusted system face misses by several percent.
test('text painted in the fallback faces has the lines and widths it has in Geist', async ({ page }) => {
  const geometry = () => page.evaluate(async () => {
    await document.fonts.ready;
    const measure = (selector: string) => [...document.querySelectorAll(selector)].map((element) => element.getBoundingClientRect());
    return {
      heights: measure('.manual-article :is(h1, h2, h3, p, li), .site-header nav, .manual-breadcrumb').map((box) => Math.round(box.height)),
      rowWidths: measure('.site-header nav, .manual-breadcrumb').map((box) => box.width),
    };
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/docs/operator-guide/');
  const withGeist = await geometry();
  const loaded = await page.evaluate(() => [...document.fonts].some((face) => face.family.replaceAll('"', '') === 'Geist Variable' && face.status === 'loaded'));
  expect(loaded, 'Geist must have rendered the reference page').toBe(true);

  await page.route('**/assets/site/fonts/geist*', (route) => route.abort());
  await page.goto('/docs/operator-guide/');
  const withFallback = await geometry();
  const geistStillLoaded = await page.evaluate(() => [...document.fonts].some((face) => face.family.includes('Geist Variable') && face.status === 'loaded'));
  expect(geistStillLoaded, 'The Geist files must be blocked for this comparison').toBe(false);
  expect(withFallback.heights).toEqual(withGeist.heights);
  withFallback.rowWidths.forEach((width, index) => {
    const reference = withGeist.rowWidths[index];
    expect(Math.abs(width - reference) / reference, `row ${index} is ${width}px in the fallback and ${reference}px in Geist`).toBeLessThan(0.01);
  });
});
