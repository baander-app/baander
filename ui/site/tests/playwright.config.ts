import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const baseURL = process.env.E2E_BASE_URL ?? 'http://website.baander.app:4181';
const target = new URL(baseURL);
if (target.protocol !== 'http:' || target.hostname !== 'website.baander.app' || !target.port
  || target.username || target.password || target.pathname !== '/') {
  throw new Error('Website tests require http://website.baander.app:<disposable-port>.');
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
    browserName: 'chromium',
    launchOptions: { args: ['--host-resolver-rules=MAP website.baander.app 127.0.0.1', '--no-proxy-server'] },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: `php -S 127.0.0.1:${target.port} -t var/website scripts/website-router.php`,
    cwd: root,
    url: `http://127.0.0.1:${target.port}/`,
    reuseExistingServer: false,
    timeout: 15_000,
  },
});
