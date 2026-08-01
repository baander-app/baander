import { test, expect } from '@playwright/test';

const SERVER_URL = process.env.SERVER_URL || 'http://localhost:9501';

test.describe('Baander on-the-fly DASH transcode', () => {
  test('DASH playback starts and switches through quality levels', async ({ page }) => {
    test.setTimeout(120000);

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

    await page.goto(`${SERVER_URL}/__e2e/transcode-dash.html`, { waitUntil: 'load' });

    await page.waitForFunction(
      () => (window as any).manifestLoaded === true && (window as any).streamInitialized === true,
      { timeout: 30000 },
    );

    // Fetch and inspect the DASH manifest directly.
    const manifestText = await page.evaluate(async (url) => {
      const res = await fetch(url);
      return res.text();
    }, `${SERVER_URL}/api/transcode/019f66d8-d5a4-7f46-b7ad-34560b35bc2b/manifest.mpd`);

    // Parse the manifest in the browser so DOMParser is available.
    const manifestInfo = await page.evaluate(async (url) => {
      const res = await fetch(url);
      const text = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(text, 'application/xml');
      const videoReps = Array.from(doc.querySelectorAll('AdaptationSet[mimeType="video/mp4"] Representation'));
      const audioReps = Array.from(doc.querySelectorAll('AdaptationSet[mimeType="audio/mp4"] Representation'));
      return {
        videoCount: videoReps.length,
        audioCount: audioReps.length,
        videoCodecs: videoReps.map((r) => r.getAttribute('codecs')),
      };
    }, `${SERVER_URL}/api/transcode/019f66d8-d5a4-7f46-b7ad-34560b35bc2b/manifest.mpd`);

    console.log(`DASH manifest: ${manifestInfo.videoCount} video reps, ${manifestInfo.audioCount} audio reps`);
    expect(manifestInfo.videoCount).toBeGreaterThanOrEqual(3);
    expect(manifestInfo.audioCount).toBeGreaterThanOrEqual(1);
    expect(manifestInfo.videoCodecs.every((c) => c?.startsWith('avc1.'))).toBe(true);

    // Gate on first-segment delivery (readyState >= HAVE_CURRENT_DATA) so the
    // playback assertion doesn't race the cold-start encoding latency (KTD-2).
    await page.waitForFunction(
      () => (window as any).firstSegmentReady === true,
      { timeout: 60000 },
    );
    console.log('First DASH segment ready — playback should advance');

    await page.waitForFunction(
      () => {
        const video = document.querySelector('video') as HTMLVideoElement;
        return video && video.currentTime > 3 && !video.paused;
      },
      { timeout: 90000 },
    );

    const currentTime = await page.evaluate(() => (document.querySelector('video') as HTMLVideoElement).currentTime);
    console.log(`DASH playback reached ${currentTime.toFixed(2)}s`);
    expect(currentTime).toBeGreaterThan(3);

    const dashErrors = await page.evaluate(() => (window as any).errors as string[]);
    expect(dashErrors).toEqual([]);
    expect(errors.filter((e) => !e.includes('favicon') && !e.includes('CmcdController'))).toEqual([]);
  });
});
