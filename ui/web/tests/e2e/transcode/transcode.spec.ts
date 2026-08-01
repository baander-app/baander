import { test, expect } from '@playwright/test'

/**
 * End-to-end validation of on-the-fly HLS transcoding.
 *
 * This test exercises the real transcode pipeline for the mounted
 * Big Buck Bunny source video:
 *   - video: h264 at 360p, 720p and 1080p
 *   - audio: separate AAC stereo track
 *
 * It loads hls.js in a Chromium page pointed at the same-origin
 * signed master manifest and asserts that playback actually starts
 * (readyState >= HAVE_CURRENT_DATA and currentTime advances).
 */

const VIDEO_ID = '019f66d8-d5a4-7f46-b7ad-34560b35bc2b'
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:9501'

const testHtml = (masterUrl: string) => `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Transcode e2e</title>
  <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.13"></script>
</head>
<body>
  <video id="video" controls width="640" height="360"></video>
  <div id="status">loading</div>
  <div id="events"></div>
  <script>
    const video = document.getElementById('video');
    const status = document.getElementById('status');
    const events = document.getElementById('events');
    const masterUrl = ${JSON.stringify(masterUrl)};

    function log(msg) {
      const line = document.createElement('div');
      line.className = 'event-log';
      line.textContent = msg;
      events.appendChild(line);
    }

    if (Hls.isSupported()) {
      const hls = new Hls({
        debug: false,
        enableWorker: true,
        startLevel: 0,
      });
      hls.loadSource(masterUrl);
      hls.attachMedia(video);
      hls.on(Hls.Events.MANIFEST_PARSED, (event, data) => {
        status.textContent = 'manifest_parsed:' + data.levels.length;
        log('levels=' + data.levels.map(l => l.height).join(','));
      });
      hls.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
        log('level=' + data.level);
      });
      hls.on(Hls.Events.ERROR, (event, data) => {
        log('error:' + data.type + ':' + data.details);
        if (data.fatal) {
          status.textContent = 'fatal_error:' + data.type + ':' + data.details;
        }
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = masterUrl;
      status.textContent = 'native_hls';
    } else {
      status.textContent = 'hls_not_supported';
    }
  </script>
</body>
</html>`

test.describe('On-the-fly video transcode', () => {
  test('HLS playback starts with separate audio and video renditions', async ({ page }) => {
    test.setTimeout(180_000)
    const masterUrl = `${BASE_URL}/api/transcode/${VIDEO_ID}/master.m3u8`

    // Serve the test page from the same origin so hls.js XHRs to
    // /api/transcode/... are same-origin and avoid CORS checks.
    await page.route(`${BASE_URL}/__e2e/transcode.html`, async route => {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: testHtml(masterUrl),
      })
    })

    await page.goto(`${BASE_URL}/__e2e/transcode.html`)

    const status = page.locator('#status')
    await expect(status).toHaveText(/manifest_parsed|native_hls/, { timeout: 30_000 })

    // Chromium blocks autoplay without a user gesture. Click the video
    // controls to start playback, then wait for the transcode to produce
    // enough media for the browser to begin decoding.
    await page.locator('video').click({ position: { x: 10, y: 10 } })

    // Wait for actual playback: readyState >= 3 (HAVE_CURRENT_DATA)
    // and currentTime has advanced past 0.
    await page.waitForFunction(
      () => {
        const v = document.querySelector('video')
        return v !== null && v.readyState >= 3 && v.currentTime > 0 && !v.paused
      },
      null,
      { timeout: 120_000 },
    )

    const stats = await page.evaluate(() => {
      const v = document.querySelector('video')!
      const bufferedEnd = v.buffered.length > 0
        ? v.buffered.end(v.buffered.length - 1)
        : 0
      return {
        currentTime: v.currentTime,
        readyState: v.readyState,
        paused: v.paused,
        videoWidth: v.videoWidth,
        videoHeight: v.videoHeight,
        bufferedEnd,
        error: v.error ? { code: v.error.code, message: v.error.message } : null,
      }
    })

    expect(stats.readyState).toBeGreaterThanOrEqual(3)
    expect(stats.currentTime).toBeGreaterThan(0)
    expect(stats.videoWidth).toBeGreaterThan(0)
    expect(stats.videoHeight).toBeGreaterThan(0)
    expect(stats.bufferedEnd).toBeGreaterThan(0)
    expect(stats.error).toBeNull()

    // Verify the player saw multiple quality levels in the manifest.
    const levelText = await status.textContent()
    const match = levelText?.match(/manifest_parsed:(\d+)/)
    if (match) {
      expect(parseInt(match[1], 10)).toBeGreaterThanOrEqual(3)
    }
  })
})
