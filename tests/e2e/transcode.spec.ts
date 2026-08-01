import { chromium, Browser, Page } from 'playwright';
import { test, expect } from '@playwright/test';

const SERVER_URL = process.env.SERVER_URL || 'http://localhost:9501';
const VIDEO_ID = '019f66d8-d5a4-7f46-b7ad-34560b35bc2b';

test.describe('Baander on-the-fly transcode', () => {
  test('HLS playback starts and switches through quality levels', async ({ page }) => {
    const errors: string[] = [];

    page.on('pageerror', (err) => {
      errors.push(err.message);
      console.error('Page error:', err.message);
    });

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
        console.error('Console error:', msg.text());
      }
    });

    test.setTimeout(120000);

    // Navigate to the E2E player page. Use 'load' because the player keeps
    // fetching HLS segments, so 'networkidle' would never fire.
    await page.goto(`${SERVER_URL}/__e2e/transcode.html`, { waitUntil: 'load' });

    // Wait for hls.js to parse the manifest and expose at least 3 levels.
    await page.waitForFunction(
      () => (window as any).manifestParsed === true && (window as any).levelCount >= 3,
      { timeout: 30000 },
    );

    const levelCount = await page.evaluate(() => (window as any).levelCount as number);
    expect(levelCount).toBeGreaterThanOrEqual(3);
    console.log(`Manifest parsed with ${levelCount} levels`);

    // Wait for the first media fragment to finish buffering before asserting
    // playback. The stream manager needs ~2–5s after manifest parse to spawn
    // FFmpeg and produce segment 0 on a cold server; gating on the buffered
    // fragment (not manifestParsed) decouples the test from encoding latency.
    await page.waitForFunction(
      () => (window as any).firstSegmentBuffered === true,
      { timeout: 60000 },
    );
    console.log('First segment buffered — playback should advance');

    // Wait for playback to advance past 3 seconds.
    await page.waitForFunction(
      () => {
        const video = document.querySelector('video') as HTMLVideoElement;
        return video && video.currentTime > 3 && !video.paused;
      },
      { timeout: 90000 },
    );

    const currentTime = await page.evaluate(() => (document.querySelector('video') as HTMLVideoElement).currentTime);
    console.log(`Playback reached ${currentTime.toFixed(2)}s`);
    expect(currentTime).toBeGreaterThan(3);

    // Switch to the highest quality level and wait for a level switch event.
    await page.evaluate(() => {
      const hls = (window as any).hls;
      hls.nextLevel = hls.levels.length - 1;
    });

    await page.waitForFunction(
      () => {
        const switches = (window as any).levelSwitches as Array<{ level: number; label: string }>;
        const hls = (window as any).hls;
        return switches.some((s) => s.level === hls.levels.length - 1);
      },
      { timeout: 30000 },
    );

    // Ensure no fatal HLS errors occurred.
    const hlsErrors = await page.evaluate(() => (window as any).errors as string[]);
    const fatalHlsErrors = hlsErrors.filter((e) => e.includes('FATAL') || e.includes('fatal'));
    expect(fatalHlsErrors).toEqual([]);
    expect(errors.filter((e) => !e.includes('favicon'))).toEqual([]);
  });
});
