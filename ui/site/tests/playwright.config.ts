import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const baseURL = process.env.E2E_BASE_URL ?? 'https://website.baander.app:4181';
const target = new URL(baseURL);
if (target.protocol !== 'https:' || target.hostname !== 'website.baander.app' || !target.port
  || target.username || target.password || target.pathname !== '/') {
  throw new Error('Website tests require https://website.baander.app:<disposable-port>.');
}

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  outputDir: '../../../var/website-browser-results',
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  timeout: 30_000,
  expect: { timeout: 8_000 },
  reporter: 'list',
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    browserName: 'chromium',
    // Headless Chromium on Linux hints fonts to whole-pixel sizes by default, which discards the
    // fractional size-adjust of the metric-matched fallback faces; desktop browsers position glyphs
    // at fractional sizes, so the suite renders text the way they do.
    launchOptions: { args: ['--host-resolver-rules=MAP website.baander.app 127.0.0.1,MAP * ~NOTFOUND', '--no-proxy-server', '--font-render-hinting=none'] },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: `node ui/site/tests/serve-website.mjs ${target.port}`,
    cwd: root,
    url: `https://127.0.0.1:${target.port}/`,
    ignoreHTTPSErrors: true,
    reuseExistingServer: false,
    timeout: 15_000,
  },
});
