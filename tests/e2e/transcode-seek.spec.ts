import { test, expect } from '@playwright/test';

const SERVER_URL = process.env.SERVER_URL || 'http://localhost:9501';

/**
 * Seek scenario — exercises the kill+restart-with-headroom path (KTD-3).
 *
 * Loads the HLS player, lets it play to ~5s, seeks to 60s, and asserts
 * playback resumes past 63s within the seek-latency budget. U1 measured
 * cold-start seek at ~0.6s, so the budget is generous (30s) to allow for
 * segment production + buffer fill after the restart.
 */
test.describe('Baander on-the-fly transcode seek', () => {
  test('seek from 5s to 60s resumes playback', async ({ page }) => {
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

    await page.goto(`${SERVER_URL}/__e2e/transcode.html`, { waitUntil: 'load' });

    // Wait for manifest + initial playback
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

    const beforeSeek = await page.evaluate(() => (document.querySelector('video') as HTMLVideoElement).currentTime);
    console.log(`Pre-seek playback at ${beforeSeek.toFixed(2)}s`);

    // Seek to 60s
    await page.evaluate(() => {
      const video = document.querySelector('video') as HTMLVideoElement;
      video.currentTime = 60;
    });

    // Assert playback resumes past 63s after the seek.
    // The refactor's kill+restart-with-headroom path (KTD-3) must produce
    // segments around 60s and let hls.js resume.
    await page.waitForFunction(
      () => {
        const video = document.querySelector('video') as HTMLVideoElement;
        return video && video.currentTime > 63 && !video.paused;
      },
      { timeout: 60000 },
    );

    const afterSeek = await page.evaluate(() => (document.querySelector('video') as HTMLVideoElement).currentTime);
    console.log(`Post-seek playback reached ${afterSeek.toFixed(2)}s`);
    expect(afterSeek).toBeGreaterThan(63);

    // No fatal HLS errors
    const hlsErrors = await page.evaluate(() => (window as any).errors as string[]);
    const fatalHlsErrors = hlsErrors.filter((e) => e.includes('FATAL') || e.includes('fatal'));
    expect(fatalHlsErrors).toEqual([]);
    expect(errors.filter((e) => !e.includes('favicon'))).toEqual([]);
  });
});
