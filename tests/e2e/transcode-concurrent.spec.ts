import { chromium, Browser, Page, expect, test } from '@playwright/test';

const SERVER_URL = process.env.SERVER_URL || 'http://localhost:9501';
const VIEWER_COUNT = 3;
const PLAYBACK_THRESHOLD_SECONDS = 3;

interface ViewerResult {
  levelCount: number;
  currentTime: number;
  fatalErrors: string[];
  allErrors: string[];
}

test.describe('Baander concurrent transcode viewers', () => {
  let browser: Browser;

  test.beforeAll(async () => {
    browser = await chromium.launch();
  });

  test.afterAll(async () => {
    await browser.close();
  });

  test('multiple viewers can watch the same HLS video concurrently', async () => {
    const contexts = await Promise.all(
      Array.from({ length: VIEWER_COUNT }, () => browser.newContext()),
    );

    try {
      const results = await Promise.all(
        contexts.map((context, index) => runViewer(context, index)),
      );

      for (let i = 0; i < results.length; i++) {
        const result = results[i];
        console.log(`Viewer ${i + 1}: ${result.levelCount} levels, playback ${result.currentTime.toFixed(2)}s`);

        expect(result.levelCount, `Viewer ${i + 1} should parse at least 3 levels`).toBeGreaterThanOrEqual(3);
        expect(result.currentTime, `Viewer ${i + 1} should play past ${PLAYBACK_THRESHOLD_SECONDS}s`).toBeGreaterThan(PLAYBACK_THRESHOLD_SECONDS);
        expect(result.fatalErrors, `Viewer ${i + 1} should have no fatal HLS errors`).toEqual([]);
        expect(result.allErrors.filter((e) => !e.includes('favicon') && !e.includes('CmcdController')), `Viewer ${i + 1} should have no unexpected console errors`).toEqual([]);
      }
    } finally {
      await Promise.all(contexts.map((context) => context.close()));
    }
  });
});

async function runViewer(context: Awaited<ReturnType<typeof browser.newContext>>, index: number): Promise<ViewerResult> {
  const page = await context.newPage();
  const allErrors: string[] = [];

  page.on('pageerror', (err) => {
    const msg = err.message;
    allErrors.push(msg);
    console.error(`Viewer ${index + 1} page error:`, msg);
  });

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      allErrors.push(text);
      console.error(`Viewer ${index + 1} console error:`, text);
    }
  });

  await page.goto(`${SERVER_URL}/__e2e/transcode.html`, { waitUntil: 'load' });

  await page.waitForFunction(
    () => (window as any).manifestParsed === true && (window as any).levelCount >= 3,
    { timeout: 30000 },
  );

  // Gate on first-segment buffering so cold-start encoding latency doesn't
  // race the playback assertion (KTD-2).
  await page.waitForFunction(
    () => (window as any).firstSegmentBuffered === true,
    { timeout: 60000 },
  );

  await page.waitForFunction(
    () => {
      const video = document.querySelector('video') as HTMLVideoElement;
      return video && video.currentTime > 3 && !video.paused;
    },
    { timeout: 90000 },
  );

  const result = await page.evaluate(() => ({
    levelCount: (window as any).levelCount as number,
    currentTime: (document.querySelector('video') as HTMLVideoElement).currentTime,
    hlsErrors: (window as any).errors as string[],
  }));

  const fatalErrors = result.hlsErrors.filter((e) => e.includes('FATAL') || e.includes('fatal'));

  await page.close();

  return {
    levelCount: result.levelCount,
    currentTime: result.currentTime,
    fatalErrors,
    allErrors,
  };
}
